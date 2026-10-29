<?php

namespace App\Console\Commands\AutoOrder;

use App\Models\WmsDemandEvent;
use App\Services\AutoReplenishment\DemandEventService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Console\Command;

class ProposeDemandEventsCommand extends Command
{
    protected $signature = 'wms:propose-demand-events {client : 対象顧客ID} {year : 対象年} {--apply : 素案を wms_demand_events に登録（状態 DRAFT）}';

    protected $description = '祝日カレンダーから年のイベント素案（年末年始・お盆・GW・3連休以上）を作成する。承認は画面で行う';

    public function handle(DemandEventService $service): int
    {
        try {
            $client = (int) $this->argument('client');
            throw_unless($client === app(ReplenishmentGuard::class)->clientId(), \RuntimeException::class, '配備先の顧客IDと対象が一致しません。');
            $year = filter_var($this->argument('year'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
            throw_if($year === false, \RuntimeException::class, '対象年を確認してください。');
            $calendar = app(\App\Services\AutoReplenishment\DemandEventCalendar::class);
            $reader = app(\App\Services\AutoReplenishment\DemandEventHistoryReader::class);
            $proposals = $calendar->proposals($year, $reader->holidays($year));
            $this->table(['種別', '名称', '期間', '前/後'], array_map(fn ($p) => [$p['type'], $p['name'], "{$p['start']}〜{$p['end']}", "{$p['pre_days']}/{$p['post_days']}"], $proposals));
            if (! $this->option('apply')) {
                $this->warn('--apply を指定すると素案（DRAFT）として登録します。承認は自動発注のイベント設定画面で行います。');

                return self::SUCCESS;
            }
            $inserted = $service->proposeYear($client, $year, (int) config('replenishment.system_actor_id', 0));
            $this->info("{$inserted} 件を素案として登録しました（既存 ".WmsDemandEvent::where(['client_id' => $client, 'year' => $year])->count().' 件）。');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
