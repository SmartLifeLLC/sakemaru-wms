<?php

namespace App\Console\Commands\AutoOrder;

use App\Jobs\ProcessAutoReplenishmentRun;
use App\Services\AutoReplenishment\ExecutionService;
use App\Services\AutoReplenishment\GenerationSchedule;
use App\Services\AutoReplenishment\HistoryService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunAutoReplenishmentCommand extends Command
{
    protected $signature = 'wms:run-auto-replenishment {--warehouse= : 試算する倉庫ID} {--preview : 発注を生成せず試算する} {--resume-run= : 同じ実行IDの未完了処理を再開}';

    protected $description = '倉庫別の自動発注実行枠を起動（EOS・物流）';

    public function handle(ReplenishmentGuard $guard, ExecutionService $service, HistoryService $history, GenerationSchedule $schedule): int
    {
        if (! $guard->installed()) {
            return self::SUCCESS;
        }
        $clientId = $guard->clientId();
        app(\App\Services\AutoReplenishment\SupplyRecorder::class)->reconcileReceipts($clientId);
        $history->synchronize($clientId);
        if ($this->option('resume-run')) {
            $run = \App\Models\WmsAutoOrderRun::where('client_id', $clientId)->findOrFail((int) $this->option('resume-run'));
            if (in_array($run->status, ['COMPLETED', 'NO_OP'])) {
                $this->error('完了済みの実行は再生成できません。');

                return self::FAILURE;
            }
            ProcessAutoReplenishmentRun::dispatch((int) $run->id)->afterCommit();
            $this->info('同じ実行IDから再開要求を登録しました。');

            return self::SUCCESS;
        }
        $preview = (bool) $this->option('preview');
        if ($preview) {
            $run = $service->createRun($clientId, (int) $this->option('warehouse'), true, '');
            $service->process($run);
            if ($run->refresh()->status === 'RUNNING') {
                ProcessAutoReplenishmentRun::dispatch((int) $run->id)->afterCommit();
            }
            $this->info('試算実行: '.$run->run_key.' / '.$run->status);

            return self::SUCCESS;
        }
        if (! config('replenishment.enabled')) {
            return self::SUCCESS;
        }
        $control = $guard->control($clientId, false);
        if (! $control->enabled || $control->reset_state !== 'COMPLETE') {
            return self::SUCCESS;
        }
        $settings = DB::connection('sakemaru')->table('wms_warehouse_auto_order_settings as s')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('w.client_id', $clientId)->where('s.is_auto_order_enabled', true)->whereNotNull('s.replenishment_config')->get(['s.*']);
        $now = now();
        foreach ($settings as $setting) {
            $config = json_decode($setting->replenishment_config, true, 512, JSON_THROW_ON_ERROR);
            $slot = $schedule->dueSlot($config, $now);
            if ($slot === null) {
                continue;
            }
            $run = $service->createRun($clientId, (int) $setting->warehouse_id, false, $slot);
            if ($run->status === 'RUNNING') {
                ProcessAutoReplenishmentRun::dispatch((int) $run->id)->afterCommit();
            }
        }

        return self::SUCCESS;
    }
}
