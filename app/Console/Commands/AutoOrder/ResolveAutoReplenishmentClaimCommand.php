<?php

namespace App\Console\Commands\AutoOrder;

use App\Models\WmsAutoOrderRunLine;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use App\Support\DbMutex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResolveAutoReplenishmentClaimCommand extends Command
{
    protected $signature = 'wms:resolve-auto-replenishment-claim {candidate} {--reason=} {--confirmed-unpublished : 未公開であることを照合済み}';

    protected $description = '停止した自動JX生成claimを照合後に解除（発注・入荷予定は再作成しない）';

    public function handle(ReplenishmentGuard $guard): int
    {
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
}
