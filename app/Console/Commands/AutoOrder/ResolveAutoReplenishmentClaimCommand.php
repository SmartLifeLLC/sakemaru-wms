<?php

namespace App\Console\Commands\AutoOrder;

use App\Models\WmsAutoOrderRunLine;
use App\Services\AutoReplenishment\ManualJxClaimRecoveryService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use App\Support\DbMutex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResolveAutoReplenishmentClaimCommand extends Command
{
    protected $signature = 'wms:resolve-auto-replenishment-claim {candidate : 発注候補ID}
        {--manual : 手動発注を照合（指定だけでは変更しない）}
        {--apply : 照合した手動発注の生成記録を解除}
        {--actor= : 復旧担当者ID}
        {--claim-token= : 照合時に表示された対象識別子}
        {--reason= : 復旧理由}
        {--confirmed-unpublished : JX生成結果が未登録で未送信であることを照合済み}';

    protected $description = '停止したJX生成記録を照合後に解除（発注・入荷予定は再作成しない）';

    public function handle(ReplenishmentGuard $guard): int
    {
        if ($this->option('manual')) {
            return $this->handleManual();
        }

        if (! $this->option('confirmed-unpublished') || mb_strlen(trim((string) $this->option('reason'))) < 5) {
            $this->error('未公開の照合完了フラグと5文字以上の理由が必要です。');

            return self::FAILURE;
        }
        $id = (int) $this->argument('candidate');
        try {
            $result = DbMutex::withLockOrFail('auto-jx-generation:'.$id, function () use ($guard, $id) {
                return DB::connection('sakemaru')->transaction(function () use ($guard, $id) {
                    $db = DB::connection('sakemaru');
                    $guard->control($guard->clientId());
                    $line = WmsAutoOrderRunLine::where('client_id', $guard->clientId())->where('order_candidate_id', $id)->lockForUpdate()->firstOrFail();
                    $candidate = $db->table('wms_order_candidates')->where('id', $id)->lockForUpdate()->first();
                    $claim = $db->table('wms_order_generation_claims')->where('candidate_id', $id)->lockForUpdate()->first();
                    throw_if(! $candidate || $candidate->wms_order_jx_document_id || in_array($line->phase, ['EOS_JX_READY', 'ABORTED']) || ($claim && $claim->state !== 'BUILDING'), \RuntimeException::class, '公開済みまたは結果不明のため解除できません。');
                    if ($claim) {
                        $db->table('wms_order_generation_claims')->where('id', $claim->id)->delete();
                    }
                    $guard->event((int) $line->client_id, 'UNPUBLISHED_CLAIM_RESOLVED', ['candidate_id' => $id, 'reason' => $this->option('reason')], (int) $line->id);

                    return true;
                }, 3);
            }, 0, 'sakemaru');
            throw_unless($result, \RuntimeException::class, '生成workerが稼働中です。解除しません。');
            $this->info('未公開claimを解除しました。同じ実行の残工程から再開してください。');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function handleManual(): int
    {
        $candidate = filter_var($this->argument('candidate'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $actor = filter_var($this->option('actor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($candidate === false || $actor === false) {
            $this->error('発注候補IDと復旧担当者ID（--actor）を正の整数で指定してください。');

            return self::FAILURE;
        }

        try {
            $service = app(ManualJxClaimRecoveryService::class);
            $result = $this->option('apply')
                ? $service->recover($candidate, $actor, (string) $this->option('claim-token'), (string) $this->option('reason'), (bool) $this->option('confirmed-unpublished'))
                : $service->inspect($candidate, $actor);
            $this->line('発注候補ID: '.$result['candidate_id'].' / 倉庫ID: '.$result['warehouse_id'].' / 商品ID: '.$result['item_id']);
            $this->line('対象識別子（--claim-token）: '.$result['claim_token']);
            $this->line('生成開始日時: '.$result['started_at']);
            $this->line('入荷予定ID: '.implode(', ', $result['incoming_schedule_ids']));
            foreach ($result['confirmation_file_paths'] as $path) {
                $this->line('確認用CSV: '.$path);
            }
            if ($this->option('apply')) {
                $this->info('対象1件のJX生成記録を解除し、担当者と理由を記録しました。通常のJX生成操作から再開できます。発注・入荷予定・連番は変更していません。');
            } else {
                $this->info('DB照合が完了しました。変更はしていません。');
                $this->warn('生成途中のJXファイルはDBに記録されない場合があります。対象のファイル・ログと送信状況を担当者が確認してください。');
                $this->line('解除する場合のみ --manual --apply --actor --claim-token --reason --confirmed-unpublished を指定してください。');
            }

            return self::SUCCESS;
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            $this->error('DB照合または監査記録の保存に失敗しました。解除処理は取り消しました。システム管理者がログを確認してください。');

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
