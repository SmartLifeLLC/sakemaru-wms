<?php

namespace App\Services\AutoReplenishment;

use App\Models\User;
use App\Models\WmsOrderCandidate;
use App\Support\DbMutex;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Sakemaru\Auth\Services\PermissionService;

/**
 * Operator-assisted recovery only. A BUILDING claim is not proof of non-delivery.
 * The operator must also reconcile storage/logs; this service never sends or
 * regenerates orders, deletes files, or rewinds slip-number sequences.
 */
class ManualJxClaimRecoveryService
{
    public function __construct(
        private readonly ReplenishmentGuard $guard,
        private readonly PermissionService $permissions,
    ) {}

    public function inspect(int $candidateId, int $actorId): array
    {
        return $this->run($candidateId, $actorId);
    }

    public function recover(int $candidateId, int $actorId, string $token, string $reason, bool $confirmedUnpublished): array
    {
        $reason = trim($reason);
        throw_unless($confirmedUnpublished, RuntimeException::class, 'JX生成結果が未登録で、送信されていないことをファイルとログでも確認してください。');
        throw_if(mb_strlen($reason) < 5 || mb_strlen($reason) > 1000, RuntimeException::class, '復旧理由を5文字以上1000文字以内で入力してください。');
        throw_if(trim($token) === '', RuntimeException::class, '照合時に表示された対象識別子を指定してください。');

        return $this->run($candidateId, $actorId, $token, $reason);
    }

    private function run(int $candidateId, int $actorId, ?string $token = null, ?string $reason = null): array
    {
        throw_if($candidateId < 1 || $actorId < 1, RuntimeException::class, '発注候補IDと復旧担当者IDを指定してください。');
        $db = DB::connection('sakemaru');
        $pdo = $db->getPdo();
        $key = 'auto-jx-generation:'.$candidateId;
        $result = DbMutex::withLockOrFail($key, function () use ($db, $pdo, $key, $candidateId, $actorId, $token, $reason) {
            $this->assertLockOwnership($db, $pdo, $key);

            // Do not retry on another connection: the mutex belongs to this PDO.
            return $db->transaction(function () use ($db, $pdo, $key, $candidateId, $actorId, $token, $reason) {
                $clientId = $this->guard->clientId();
                $actor = User::query()->where('client_id', $clientId)->where('is_active', true)->find($actorId);
                throw_unless($actor, RuntimeException::class, '復旧担当者がこの顧客の有効な担当者ではありません。');
                foreach (['wms.access', 'wms.wms-order-document.view', 'wms.wms-order-document.edit'] as $permission) {
                    throw_unless($this->permissions->check($actor, $permission), RuntimeException::class, '復旧担当者に発注データファイルの管理権限がありません。');
                }

                // Same order as JX publication: candidate, then claim.
                $candidate = WmsOrderCandidate::query()->lockForUpdate()->find($candidateId);
                throw_unless($candidate, RuntimeException::class, '発注候補が見つかりません。');
                $claim = $db->table('wms_order_generation_claims')->where('candidate_id', $candidateId)->lockForUpdate()->first();
                throw_unless($claim, RuntimeException::class, '解除対象のJX生成記録がありません。再度照合してください。');
                throw_unless($db->table('warehouses')->where('id', $candidate->warehouse_id)->where('client_id', $clientId)->exists(), RuntimeException::class, '対象倉庫がこの顧客に属していません。');
                throw_if($db->table('wms_auto_order_run_lines')->where('order_candidate_id', $candidateId)->lockForUpdate()->first(), RuntimeException::class, '自動発注の候補です。手動発注の復旧では解除できません。');
                $raw = $candidate->getRawOriginal();
                throw_unless(in_array($raw['origin_type'] ?? null, ['USER', 'MANUAL_SAFETY_STOCK', 'MANUAL_SALES_BASED', 'DIST'], true), RuntimeException::class, '手動発注であることを確認できません。発注元を照合してください。');
                throw_unless(($raw['status'] ?? null) === 'CONFIRMED' && in_array($raw['order_channel'] ?? null, [null, 'EOS'], true), RuntimeException::class, '確定済みのEOS発注候補だけが復旧対象です。');
                throw_if(($raw['transmission_status'] ?? null) !== 'PENDING' || ! empty($raw['transmitted_at']), RuntimeException::class, '送信済み、または送信結果が不明な発注です。送信結果を照合してください。');
                throw_if($claim->state !== 'BUILDING' || $claim->document_id !== null || $candidate->wms_order_jx_document_id !== null, RuntimeException::class, 'JX生成結果が登録済み、または生成中以外の状態です。解除できません。');
                throw_if($token !== null && ! hash_equals((string) $claim->token, $token), RuntimeException::class, '照合後にJX生成記録が変わりました。再度照合してください。');
                throw_if(empty($claim->created_at), RuntimeException::class, 'JX生成開始日時を確認できません。解除できません。');

                $item = $db->table('items')->where('id', $candidate->item_id)->where('client_id', $clientId)->lockForUpdate()->first();
                throw_unless($item, RuntimeException::class, '商品の所属を確認できません。');
                // Lock master data before reloading the relation used by generation.
                $candidate->unsetRelation('item')->load('item');
                $hash = app(JxGenerationGuard::class)->hash($candidate);
                throw_unless(hash_equals((string) $claim->manifest_hash, $hash), RuntimeException::class, 'JX生成開始後に発注内容または入数が変わっています。内容を照合してください。');

                $evidence = $this->reconcileArtifacts($db, $candidate, $claim, $item, $clientId);
                $report = [
                    'candidate_id' => $candidateId, 'client_id' => $clientId,
                    'warehouse_id' => (int) $candidate->warehouse_id, 'item_id' => (int) $candidate->item_id,
                    'claim_id' => (int) $claim->id, 'claim_token' => $claim->token,
                    'manifest_hash' => $claim->manifest_hash, 'started_at' => $claim->created_at,
                    'actor_id' => $actorId,
                    'original_creator_ids' => $db->table('wms_auto_order_job_controls')->where('batch_code', $candidate->batch_code)->whereNotNull('created_by')->distinct()->pluck('created_by')->map(fn ($id) => (int) $id)->all(),
                    ...$evidence,
                ];
                $this->assertLockOwnership($db, $pdo, $key);
                if ($token !== null) {
                    $deleted = $db->table('wms_order_generation_claims')->where('id', $claim->id)
                        ->where('candidate_id', $candidateId)->where('token', $token)->where('state', 'BUILDING')
                        ->whereNull('document_id')->where('manifest_hash', $hash)->delete();
                    throw_unless($deleted === 1, RuntimeException::class, 'JX生成記録が変わったため解除しません。再度照合してください。');
                    $this->guard->event($clientId, 'MANUAL_JX_UNPUBLISHED_CLAIM_RESOLVED', [
                        ...$report, 'reason' => $reason, 'confirmed_unpublished' => true,
                    ], null, $actorId);
                    $this->assertLockOwnership($db, $pdo, $key);
                }

                return ['status' => $token === null ? 'CHECKED' : 'RECOVERED', ...$report];
            });
        }, 0, 'sakemaru');
        throw_unless($result, RuntimeException::class, '別の処理がJXを生成中です。終了を確認してから再度照合してください。');

        return $result;
    }

    private function reconcileArtifacts(Connection $db, WmsOrderCandidate $candidate, object $claim, object $item, int $clientId): array
    {
        $id = (int) $candidate->id;
        $schedules = $db->table('wms_order_incoming_schedules')->where('order_candidate_id', $id)->orderBy('id')->lockForUpdate()->get();
        throw_if($schedules->isEmpty(), RuntimeException::class, '発注候補に対応する入荷予定がありません。再確定せず、入荷予定を照合してください。');
        $allowedWarehouses = [(int) $candidate->warehouse_id];
        foreach (($candidate->demand_breakdown ?? []) as $part) {
            $allowedWarehouses[] = (int) ($part['warehouse_id'] ?? 0);
        }
        $warehouseIds = $schedules->pluck('warehouse_id')->map(fn ($value) => (int) $value)->unique()->all();
        $ownedWarehouses = $db->table('warehouses')->where('client_id', $clientId)->whereIn('id', $warehouseIds)->pluck('id')->map(fn ($value) => (int) $value)->all();
        throw_if(array_diff($warehouseIds, $allowedWarehouses) || array_diff($warehouseIds, $ownedWarehouses), RuntimeException::class, '入荷予定の倉庫が発注候補または顧客と一致しません。');
        foreach ($schedules as $schedule) {
            throw_if($schedule->status !== 'PENDING' || (int) $schedule->received_quantity !== 0 || $schedule->purchase_queue_id !== null
                || $schedule->stock_transfer_id !== null || (int) $schedule->item_id !== (int) $candidate->item_id
                || (int) $schedule->contractor_id !== (int) $candidate->contractor_id
                || (string) $schedule->expected_arrival_date !== (string) $candidate->getRawOriginal('expected_arrival_date'),
                RuntimeException::class, '入荷予定の処理状態・商品・発注先・入荷予定日が一致しません。入荷予定を照合してください。');
        }
        $pieces = app(SnapshotReader::class);
        $schedulePieces = $schedules->sum(fn ($schedule) => $pieces->pieces((int) $schedule->expected_quantity, $schedule->quantity_type, $item));
        // OrderExecutionService::resolveIncomingQuantity stores candidate units as-is.
        // JX output pack conversion is deliberately not used for incoming schedules.
        $candidatePieces = $pieces->pieces((int) $candidate->order_quantity, $candidate->getRawOriginal('quantity_type'), $item);
        throw_if($candidatePieces < 1 || $schedulePieces !== $candidatePieces, RuntimeException::class, '発注数量と入荷予定の総バラ数が一致しません。数量を照合してください。');

        $slips = $schedules->pluck('slip_number')->filter()->unique()->values()->all();
        $assignments = $db->table('wms_order_slip_number_assignments')->where(function ($query) use ($id, $slips) {
            $query->whereJsonContains('order_candidate_ids', $id)->orWhereJsonContains('order_candidate_ids', (string) $id);
            if ($slips) {
                $query->orWhereIn('slip_number', $slips);
            }
        })->orderBy('id')->lockForUpdate()->get();
        throw_if($assignments->contains(fn ($assignment) => $assignment->status !== 'CANCELLED'), RuntimeException::class, '有効または送信済みのJX伝票番号割当があります。生成結果を照合してください。');

        // Confirmation CSV metadata commits BEFORE the document publication transaction.
        // An undistributed CSV with no document is expected after some crash positions.
        $files = $db->table('wms_order_data_files')->where(function ($query) use ($id) {
            $query->whereJsonContains('candidate_ids', $id)->orWhereJsonContains('candidate_ids', (string) $id);
        })->orderBy('id')->lockForUpdate()->get();
        throw_if($files->contains(fn ($file) => $file->order_channel !== 'JX_CONFIRMATION' || $file->status !== 'GENERATED'
            || $file->csv_downloaded_at !== null || $file->fax_downloaded_at !== null || $file->mail_sent_at !== null),
            RuntimeException::class, '発注ファイルが配布済み、または用途を確認できません。外部への送付状況を照合してください。');
        $batchCodes = $files->pluck('batch_code')->push($candidate->batch_code)->filter()->unique()->values()->all();
        $paths = $files->pluck('file_path')->filter()->unique()->values()->all();
        $documentIds = $assignments->pluck('wms_order_jx_document_id')->unique()->values()->all();
        $documents = $db->table('wms_order_jx_documents')->where(function ($query) use ($paths, $documentIds, $batchCodes) {
            $query->whereIn('id', $documentIds)->orWhereIn('csv_path', $paths)
                ->orWhere(function ($orphans) use ($batchCodes) {
                    $orphans->whereIn('batch_code', $batchCodes)->where('order_count', '>', 0)
                        ->whereNotExists(fn ($linked) => $linked->selectRaw('1')->from('wms_order_candidates')->whereColumn('wms_order_jx_document_id', 'wms_order_jx_documents.id'))
                        ->whereNotExists(fn ($assigned) => $assigned->selectRaw('1')->from('wms_order_slip_number_assignments')->whereColumn('wms_order_jx_document_id', 'wms_order_jx_documents.id'));
                });
        })->orderBy('id')->lockForUpdate()->get();
        throw_if(count(array_diff($documentIds, $documents->pluck('id')->all())) > 0, RuntimeException::class, 'JX伝票番号割当の参照先がありません。生成結果を照合してください。');
        foreach ($documents as $document) {
            throw_if($document->status !== 'CANCELLED' || $document->transmitted_at !== null || $document->jx_message_id !== null
                || $document->jx_document_no !== null || empty($document->updated_at) || $document->updated_at >= $claim->created_at,
                RuntimeException::class, '未取消・送信済み・結果不明のJXデータ、または生成開始後の変更があります。解除できません。');
        }
        // A multi-file batch may already have published another candidate. Its
        // independently linked document/logs must not prevent this claim's recovery.
        $otherPublishedIds = $db->table('wms_order_generation_claims as other_claim')
            ->join('wms_order_candidates as other_candidate', 'other_candidate.id', '=', 'other_claim.candidate_id')
            ->join('wms_order_jx_documents as other_document', 'other_document.id', '=', 'other_claim.document_id')
            ->where('other_claim.candidate_id', '<>', $id)->where('other_claim.state', 'PUBLISHED')
            ->whereColumn('other_candidate.wms_order_jx_document_id', 'other_document.id')
            ->whereIn('other_document.batch_code', $batchCodes)->pluck('other_document.id')->all();
        $logs = $db->table('wms_order_transmission_logs')->where(function ($query) use ($documents, $batchCodes, $otherPublishedIds) {
            $query->whereIn('wms_order_jx_document_id', $documents->pluck('id')->all())
                ->orWhere(fn ($batch) => $batch->whereIn('batch_code', $batchCodes)
                    ->where(fn ($unresolved) => $unresolved->whereNull('wms_order_jx_document_id')->orWhereNotIn('wms_order_jx_document_id', $otherPublishedIds)));
        })->lockForUpdate()->get();
        throw_if($logs->contains(fn ($log) => $log->action !== 'CANCEL' || $log->status !== 'SUCCESS' || $log->created_at >= $claim->created_at), RuntimeException::class, 'JX送信を試みた記録、または送信結果が不明な記録があります。送信先との照合が必要です。');

        return [
            'incoming_schedule_ids' => $schedules->pluck('id')->all(),
            'confirmation_file_ids' => $files->pluck('id')->all(),
            'confirmation_file_paths' => $paths,
            'cancelled_document_ids' => $documents->pluck('id')->all(),
            'cancelled_assignment_ids' => $assignments->pluck('id')->all(),
            'other_published_document_ids' => $otherPublishedIds,
            'storage_check' => '担当者によるJXファイル・ログの未送信確認が必要です。',
        ];
    }

    private function assertLockOwnership(Connection $db, PDO $pdo, string $key): void
    {
        throw_unless($db->getPdo() === $pdo, RuntimeException::class, '照合中にDB接続が変わりました。解除せず終了します。');
        $statement = $pdo->prepare('SELECT IS_USED_LOCK(?) AS owner_id, CONNECTION_ID() AS connection_id');
        $statement->execute([$key]);
        $lock = $statement->fetch(PDO::FETCH_ASSOC);
        throw_unless($lock && $lock['owner_id'] !== null && (string) $lock['owner_id'] === (string) $lock['connection_id'], RuntimeException::class, 'JX生成の排他状態を確認できません。解除せず終了します。');
    }
}
