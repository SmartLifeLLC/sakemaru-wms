<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoOrder\CalendarGenerationService;
use Illuminate\Console\Command;

class ImportHolidaysCommand extends Command
{
    protected $signature = 'wms:import-holidays {year? : 対象年（省略時は今年と来年）}';

    protected $description = '日本の祝日データをインポート';

    public function handle(CalendarGenerationService $service): int
    {
        $year = $this->argument('year');

        $this->info('祝日データをインポートします...');

        try {
            if ($year) {
                $count = $service->generateNationalHolidays((int) $year);
                $this->info("{$year}年: {$count}件の祝日を登録しました");
            } else {
                $currentYear = (int) date('Y');
                $years = [$currentYear, $currentYear + 1];

                foreach ($years as $y) {
                    $count = $service->generateNationalHolidays($y);
                    $this->info("{$y}年: {$count}件の祝日を登録");
                }
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('エラーが発生しました: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
