<?php

namespace App\Console\Commands;

use App\Services\WmsStatsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateDailyStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wms:update-daily-stats
                            {--date= : Target date (Y-m-d format). Defaults to yesterday}
                            {--warehouse-id= : Specific warehouse ID. If not specified, all warehouses will be updated}
                            {--force : Force update even if data exists and is recent}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update WMS daily statistics for the specified date and warehouses';

    protected WmsStatsService $statsService;

    public function __construct(WmsStatsService $statsService)
    {
        parent::__construct();
        $this->statsService = $statsService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = now();
        $this->info('Starting WMS daily stats update...');

        // 日付の決定（指定されていない場合は前日）
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();

        $this->info("Target date: {$date->format('Y-m-d')}");

        // 倉庫IDの決定
        $warehouseId = $this->option('warehouse-id');
        $warehouseIds = $warehouseId ? [(int) $warehouseId] : null;

        if ($warehouseId) {
            $this->info("Target warehouse: {$warehouseId}");
        } else {
            $this->info('Target: All active warehouses');
        }

        // 強制更新フラグ
        $forceUpdate = $this->option('force');
        if ($forceUpdate) {
            $this->warn('Force update mode enabled');
        }

        // 統計の更新実行
        try {
            if ($warehouseIds) {
                // 単一倉庫の場合
                $result = $this->statsService->calculate($date, $warehouseIds[0]);
                $this->info("✓ Successfully updated stats for warehouse {$warehouseIds[0]}");
                $this->displayStats($result);
                $successCount = 1;
            } else {
                // 全倉庫の場合
                $this->info('Processing all warehouses...');
                $successCount = $this->statsService->bulkCalculate($date, $warehouseIds);
                $this->info("✓ Successfully updated stats for {$successCount} warehouse(s)");
            }

            $duration = $startTime->diffInSeconds(now());
            $this->info("Completed in {$duration} seconds");

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to update daily stats: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    /**
     * Display statistics summary
     *
     * @param  \App\Models\WmsDailyStat  $stat
     */
    private function displayStats($stat): void
    {
        $this->newLine();
        $this->info('Statistics Summary:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Picking Slips', number_format($stat->picking_slip_count)],
                ['Picking Items', number_format($stat->picking_item_count)],
                ['Unique Items', number_format($stat->unique_item_count)],
                ['Delivery Courses', number_format($stat->delivery_course_count)],
                ['Total Ship Qty', number_format($stat->total_ship_qty)],
                ['Amount (Ex Tax)', '¥'.number_format($stat->total_amount_ex, 2)],
                ['Amount (Inc Tax)', '¥'.number_format($stat->total_amount_in, 2)],
                ['Container Deposit', '¥'.number_format($stat->total_container_deposit, 2)],
                ['Stockout (Unique)', number_format($stat->stockout_unique_count)],
                ['Stockout (Total)', number_format($stat->stockout_total_count)],
                ['Opportunity Loss', '¥'.number_format($stat->total_opportunity_loss, 2)],
            ]
        );

        $categoryBreakdown = $stat->getCategoryBreakdown();
        if (! empty($categoryBreakdown['categories'])) {
            $this->newLine();
            $this->info('Category Breakdown:');
            $categoryRows = [];
            foreach ($categoryBreakdown['categories'] as $categoryId => $data) {
                $categoryRows[] = [
                    $data['name'],
                    number_format($data['ship_qty']),
                    '¥'.number_format($data['amount_ex'], 2),
                ];
            }
            $this->table(
                ['Category', 'Ship Qty', 'Amount (Ex Tax)'],
                $categoryRows
            );
        }
    }
}
