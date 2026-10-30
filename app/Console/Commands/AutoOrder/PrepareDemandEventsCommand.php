<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoReplenishment\DemandEventPipelineService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PrepareDemandEventsCommand extends Command
{
    protected $signature = 'wms:prepare-demand-events {client : 対象顧客ID}
        {--as-of= : 基準日（既定：今日）}
        {--horizon-days= : 対象期間（既定 config replenishment.demand_events.weekly_prepare.horizon_days＝63日）}
        {--apply : 素案登録・倍率保存・検証結果と承認状態を保存（指定なしは確認のみ）}
        {--inputs=production : 検証（過去再生）の算定入力（production=本番と同じ連携入荷実績・発注単位・パレット/出荷数量候補, sales=販売数量のみ）}';

    protected $description = '今後約9週の標準イベントを素案化し、倍率を学習、過去の同じイベントで検証する（週次・既定OFF）。自動承認は設定で有効化した種別のみ（既定なし）';

    public function handle(DemandEventPipelineService $pipeline): int
    {
        try {
            $client = (int) $this->argument('client');
            throw_unless($client === app(ReplenishmentGuard::class)->clientId(), \RuntimeException::class, '配備先の顧客IDと対象が一致しません。');
            $asOf = $this->option('as-of') ? CarbonImmutable::parse($this->option('as-of'))->toDateString() : CarbonImmutable::now('Asia/Tokyo')->toDateString();
            $horizon = (int) ($this->option('horizon-days') ?: config('replenishment.demand_events.weekly_prepare.horizon_days', 63));
            throw_unless($horizon >= 7 && $horizon <= 120, \RuntimeException::class, '対象期間は7〜120日で指定してください。');
            if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 1024) {
                ini_set('memory_limit', '1024M');
            }
            $apply = (bool) $this->option('apply');
            $this->info(($apply ? '保存あり' : '確認のみ')."：{$asOf} から {$horizon} 日以内に始まるイベント");
            throw_unless(in_array($this->option('inputs'), ['production', 'sales'], true), \RuntimeException::class, '--inputs は production または sales で指定してください。');
            $result = $pipeline->run($client, $asOf, $horizon, (int) config('replenishment.system_actor_id', 0), $apply, fn ($line) => $this->line('  '.$line), null,
                $this->option('inputs') === 'production' ? null : false);
            $rows = [];
            foreach ($result['events'] as $entry) {
                $b = $entry['backtest'] ?? null;
                $fmt = fn ($v) => $v === null ? '—' : round($v * 100, 1).'%';
                $rows[] = [$entry['event']['name'], $entry['event']['start'].'〜'.$entry['event']['end'], $entry['status_before'] ?? '—', $entry['action'],
                    $b ? count($b['instances']) : '—', $b && $b['pooled'] ? $fmt($b['pooled']['base']['wape']).'→'.$fmt($b['pooled']['event']['wape']) : '—',
                    $b && $b['pooled'] ? $fmt($b['pooled']['base']['stockout_rate']).'→'.$fmt($b['pooled']['event']['stockout_rate']) : '—', $b['verdict'] ?? '—'];
            }
            $this->table(['イベント', '期間', '状態', '処理', '過去実例', '予測誤差 曜日→補正', '欠品率 曜日→補正', '判定'], $rows);
            $this->info(json_encode($result['summary'], JSON_UNESCAPED_UNICODE));
            if (! $apply) {
                $this->warn('--apply を指定すると素案登録・倍率保存・検証結果と承認状態の更新を行います。');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
