<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoReplenishment\RecommendationRefreshService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Console\Command;

class RefreshReplenishmentRecommendationsCommand extends Command
{
    protected $signature = 'wms:refresh-replenishment-recommendations {client : 対象顧客ID}
        {--warehouse= : 倉庫IDで絞り込み}
        {--max-change= : 前回値からの変化幅の上限（倍率。既定は config replenishment.weekly_refresh.max_change_ratio）}
        {--apply : 再計算した数量を保存（指定なしは確認のみ）}';

    protected $description = '保存済みのおすすめ設定を同じ条件で再計算し、承認済みイベントを反映して数量を更新する（週次）';

    public function handle(RecommendationRefreshService $service): int
    {
        try {
            $client = (int) $this->argument('client');
            throw_unless($client === app(ReplenishmentGuard::class)->clientId(), \RuntimeException::class, '配備先の顧客IDと対象が一致しません。');
            $warehouse = $this->option('warehouse') !== null ? (int) $this->option('warehouse') : null;
            $maxChange = (float) ($this->option('max-change') ?: config('replenishment.weekly_refresh.max_change_ratio', 2.0));
            $actor = (int) config('replenishment.system_actor_id', 0);
            $apply = (bool) $this->option('apply');
            $this->info(($apply ? '保存あり' : '確認のみ')."：変化幅の上限 ×{$maxChange}");
            $result = $service->refresh($client, $warehouse, $maxChange, $actor, $apply, function (array $row) {
                $changes = implode(' ', array_map(fn ($k, $c) => "{$k} {$c['from']}→{$c['to']}", array_keys($row['changes']), $row['changes']));
                $this->line(sprintf('%-11s 倉庫 %d 商品 %d %s %s', $row['outcome'], $row['warehouse_id'], $row['item_id'], $changes, $row['reason'] ?? ''));
            });
            $this->info(json_encode($result['summary'], JSON_UNESCAPED_UNICODE));

            return $result['summary']['ERROR'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
