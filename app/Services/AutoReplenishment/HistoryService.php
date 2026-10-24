<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsAutoOrderRunLine;
use Illuminate\Support\Facades\DB;

class HistoryService
{
    public function synchronize(int $clientId, int $limit = 200): int
    {
        $count = 0;
        $lines = WmsAutoOrderRunLine::where('client_id', $clientId)->whereNotNull('queue_id')
            ->whereNotIn('phase', ['TRANSFER_CREATED', 'ABORTED'])->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        $lines->each(function ($line) use (&$count) {
            DB::connection('sakemaru')->transaction(function () use ($line, &$count) {
                $receipt = DB::connection('sakemaru')->table('wms_auto_order_transfer_receipts')->where('queue_id', $line->queue_id)->first();
                if ($receipt) {
                    $snapshot = json_decode($receipt->snapshot, true, 512, JSON_THROW_ON_ERROR);
                    app(ArtifactRecorder::class)->link($line, 'stock_transfer', (int) $receipt->stock_transfer_id, $snapshot['transfer'] + ['slip_number' => $snapshot['trade']['slip_number'] ?? null]);
                    $line->update(['phase' => 'TRANSFER_CREATED', 'reason' => null, 'artifact_output_snapshot' => $snapshot]);
                    app(ReplenishmentGuard::class)->event((int) $line->client_id, 'TRANSFER_CREATED', ['stock_transfer_id' => $receipt->stock_transfer_id], (int) $line->id);
                    $count++;
                } else {
                    $queue = DB::connection('sakemaru')->table('stock_transfer_queue')->where('id', $line->queue_id)->first();
                    $phase = match ($queue?->status) {
                        'BEFORE' => 'TRANSFER_QUEUED', 'PROCESSING' => 'TRANSFER_PROCESSING',
                        default => 'RECONCILIATION_REQUIRED',
                    };
                    $line->update(['phase' => $phase, 'reason' => $phase === 'RECONCILIATION_REQUIRED' ? '移動作成結果の照合が必要です。別要求を作らず同じ要求IDを確認してください。' : null]);
                }
            }, 3);
        });

        foreach (\App\Models\WmsAutoOrderRun::whereIn('id', $lines->pluck('run_id')->unique())->whereNotIn('status', ['RUNNING', 'FAILED'])->get() as $run) {
            app(ExecutionService::class)->refreshRun($run);
        }

        return $count;
    }

    public function abortUnpublished(int $clientId, int $id, string $reason, int $actorId): void
    {
        throw_if(mb_strlen(trim($reason)) < 5, \InvalidArgumentException::class, '中止の確認理由を5文字以上で入力してください。');
        DB::connection('sakemaru')->transaction(function () use ($clientId, $id, $reason, $actorId) {
            $db = DB::connection('sakemaru');
            app(ReplenishmentGuard::class)->control($clientId);
            $line = WmsAutoOrderRunLine::where('client_id', $clientId)->lockForUpdate()->findOrFail($id);
            throw_if($line->queue_id || $line->phase === 'ABORTED', \RuntimeException::class, '公開済み、または中止済みです。既存の取消処理で照合してください。');
            $candidate = $db->table('wms_order_candidates')->where('id', $line->order_candidate_id)->lockForUpdate()->first();
            $claim = $db->table('wms_order_generation_claims')->where('candidate_id', $line->order_candidate_id)->lockForUpdate()->first();
            throw_if($claim || $candidate?->wms_order_jx_document_id, \RuntimeException::class, '生成処理または公開済み文書があります。照合完了まで中止できません。');
            $links = $db->table('wms_auto_order_artifact_links')->where('line_id', $line->id)->where('artifact_type', 'incoming')->pluck('artifact_id');
            $schedules = $db->table('wms_order_incoming_schedules')->whereIn('id', $links)->lockForUpdate()->get();
            throw_if($schedules->contains(fn ($s) => $s->status !== 'PENDING' || (int) $s->received_quantity > 0), \RuntimeException::class, '受入済みデータがあるため照合が必要です。');
            $db->table('wms_order_incoming_schedules')->whereIn('id', $links)->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'cancelled_by' => $actorId, 'cancellation_reason' => $reason]);
            // Keep the candidate terminal and the origin link, never restore it to APPROVED.
            if ($candidate) {
                $db->table('wms_order_candidates')->where('id', $candidate->id)->update(['status' => 'EXCLUDED', 'exclusion_reason' => $reason]);
            }
            if ($line->transfer_candidate_id) {
                $transferCandidate = $db->table('wms_stock_transfer_candidates')->where('id', $line->transfer_candidate_id)->lockForUpdate()->first();
                throw_if($transferCandidate && $transferCandidate->status !== 'APPROVED', \RuntimeException::class, '移動候補の確定状態が変わったため照合が必要です。');
                $db->table('wms_stock_transfer_candidates')->where('id', $line->transfer_candidate_id)->update(['status' => 'EXCLUDED', 'exclusion_reason' => $reason]);
            }
            $db->table('wms_auto_order_budget_entries')->where('effect_key', $line->effect_key)->where('state', 'RESERVED')->update(['state' => 'RELEASED', 'updated_at' => now()]);
            if ($line->order_candidate_id) {
                $db->table('wms_replenishment_effects')->where(['source_type' => 'order', 'source_id' => $line->order_candidate_id])->update(['state' => 'CANCELLED', 'reconciliation_required' => false, 'received_completed_at' => now(), 'updated_at' => now()]);
            }
            $line->update(['phase' => 'ABORTED', 'reason' => $reason]);
            app(ReplenishmentGuard::class)->event($clientId, 'UNPUBLISHED_ABORTED', ['reason' => $reason], $id, $actorId);
        }, 3);
    }
}
