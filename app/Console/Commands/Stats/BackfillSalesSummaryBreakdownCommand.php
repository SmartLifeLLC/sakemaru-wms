<?php

namespace App\Console\Commands\Stats;

use App\Models\Sakemaru\ClientSetting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BackfillSalesSummaryBreakdownCommand extends Command
{
    protected $signature = 'wms:backfill-sales-summary-breakdown
        {--warehouse-id= : 特定倉庫のみ補正}
        {--to= : 集計基準日(Y-m-d)。未指定ならシステム日付}
        {--dry-run : 実際の書き込みなしに件数だけ確認}';

    protected $description = 'stats_item_warehouse_daily_salesから販売サマリの日別内訳・5日実績を再計算';

    public function handle(): int
    {
        $to = CarbonImmutable::parse($this->option('to') ?: ClientSetting::systemDateYMD())->toDateString();
        $warehouseId = $this->option('warehouse-id');

        $this->info('販売実績サマリ内訳の補正を開始します。');
        $this->line("基準日: {$to}");
        if ($warehouseId) {
            $this->line("対象倉庫: {$warehouseId}");
        }

        $arguments = [
            '--summary-only' => true,
            '--to' => $to,
        ];

        if ($warehouseId) {
            $arguments['--warehouse-id'] = (int) $warehouseId;
        }

        if ($this->option('dry-run')) {
            $arguments['--dry-run'] = true;
        }

        return $this->call('wms:sync-sales-summaries', $arguments);
    }
}
