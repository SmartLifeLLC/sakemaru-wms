<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoReplenishment\DemandEventLearningService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class LearnDemandEventsCommand extends Command
{
    protected $signature = 'wms:learn-demand-events {client : 対象顧客ID}
        {--through= : 学習に使う実績の最終日（既定：前日）}
        {--years=3 : 学習する年数}
        {--items=policies : ITEMプロファイルを作る商品（policies＝自動発注設定のある商品／all＝実績のある全商品）}
        {--apply : 学習結果を wms_demand_event_profiles に保存（指定なしは集計のみ）}';

    protected $description = '過去の標準イベント（年末年始・お盆・GW）の販売実績から商品別・カテゴリ別のイベント倍率を学習する（カテゴリ値は候補提示用）';

    public function handle(DemandEventLearningService $service): int
    {
        try {
            $client = (int) $this->argument('client');
            throw_unless($client === app(ReplenishmentGuard::class)->clientId(), \RuntimeException::class, '配備先の顧客IDと対象が一致しません。');
            $through = $this->option('through') ?: CarbonImmutable::now('Asia/Tokyo')->subDay()->toDateString();
            $years = filter_var($this->option('years'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 6]]);
            throw_if($years === false, \RuntimeException::class, '--years は1〜6で指定してください。');
            $scope = $this->option('items');
            throw_unless(in_array($scope, ['policies', 'all'], true), \RuntimeException::class, '--items は policies または all を指定してください。');
            $itemIds = $scope === 'policies' ? $service->policyItemIds($client) : null;
            if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 1024) {
                ini_set('memory_limit', '1024M');
            }
            $this->info("学習期間：{$through} までの {$years} 年分（ITEM対象：".($itemIds === null ? '全商品' : count($itemIds).'商品').'）');
            $learned = $service->learn($client, $through, $years, $itemIds, fn ($line) => $this->line($line));
            $this->table(['イベント', '期間', '前後日'], array_map(fn ($i) => [$i['name'], "{$i['start']}〜{$i['end']}", "{$i['pre_days']}/{$i['post_days']}"], $learned['instances']));
            $this->info(json_encode($learned['stats'], JSON_UNESCAPED_UNICODE));
            foreach (array_slice($learned['items'], 0, 5, true) as $itemId => $profiles) {
                foreach ($profiles as $key => $profile) {
                    $top = array_slice($profile['positions'], 0, 6, true);
                    $this->line("商品 {$itemId} {$key}：".implode(' ', array_map(fn ($p, $f) => "{$p}={$f['factor']}", array_keys($top), $top)).'（観測 '.$profile['pieces'].'バラ）');
                }
            }
            if (! $this->option('apply')) {
                $this->warn('--apply を指定すると学習結果を保存します（既存の学習結果は置き換えます）。');

                return self::SUCCESS;
            }
            $count = $service->persist($client, $learned, $through);
            $this->info("{$count} 件のイベント倍率を保存しました。");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
