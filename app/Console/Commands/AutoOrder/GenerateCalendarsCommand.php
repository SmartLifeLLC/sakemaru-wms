<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoOrder\CalendarGenerationService;
use Illuminate\Console\Command;

class GenerateCalendarsCommand extends Command
{
    protected $signature = 'wms:generate-calendars
                            {--warehouse= : 特定の倉庫IDのみ生成}
                            {--months=12 : 生成する月数}';

    protected $description = '倉庫別営業日カレンダーを生成';

    public function handle(CalendarGenerationService $service): int
    {
        $warehouseId = $this->option('warehouse');
        $months = (int) $this->option('months');

        $this->info('カレンダー生成を開始します...');

        try {
            if ($warehouseId) {
                $count = $service->generateCalendar((int) $warehouseId, $months);
                $this->info("倉庫ID {$warehouseId}: {$count}日分を生成しました");
            } else {
                $results = $service->regenerateAllCalendars($months);

                if (empty($results)) {
                    $this->warn('自動発注が有効な倉庫がありません');
                    return self::SUCCESS;
                }

                foreach ($results as $whId => $count) {
                    $this->info("倉庫ID {$whId}: {$count}日分を生成");
                }

                $this->info('完了しました。合計: ' . array_sum($results) . '件');
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('エラーが発生しました: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
