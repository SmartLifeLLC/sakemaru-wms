<?php

namespace App\Services\AutoOrder;

use App\Models\WmsNationalHoliday;
use App\Models\WmsWarehouseAutoOrderSetting;
use App\Models\WmsWarehouseCalendar;
use App\Models\WmsWarehouseHolidaySetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * カレンダー生成サービス
 */
class CalendarGenerationService
{
    /**
     * 指定倉庫のカレンダーを生成（向こう12ヶ月）
     */
    public function generateCalendar(int $warehouseId, int $months = 12): int
    {
        $setting = WmsWarehouseHolidaySetting::where('warehouse_id', $warehouseId)->first();

        if (!$setting) {
            Log::warning("Holiday setting not found for warehouse", ['warehouse_id' => $warehouseId]);
            return 0;
        }

        $startDate = today();
        $endDate = today()->addMonths($months);
        $processedCount = 0;

        // 祝日をプリロード
        $nationalHolidays = WmsNationalHoliday::forPeriod($startDate, $endDate)
            ->pluck('holiday_name', 'holiday_date')
            ->mapWithKeys(fn ($name, $date) => [Carbon::parse($date)->toDateString() => $name])
            ->toArray();

        // 既存の手動変更を取得
        $manualOverrides = WmsWarehouseCalendar::where('warehouse_id', $warehouseId)
            ->where('is_manual_override', true)
            ->forPeriod($startDate, $endDate)
            ->pluck('is_holiday', 'target_date')
            ->mapWithKeys(fn ($isHoliday, $date) => [Carbon::parse($date)->toDateString() => $isHoliday])
            ->toArray();

        DB::connection('sakemaru')->transaction(function () use (
            $warehouseId,
            $setting,
            $startDate,
            $endDate,
            $nationalHolidays,
            $manualOverrides,
            &$processedCount
        ) {
            $date = $startDate->copy();

            while ($date->lte($endDate)) {
                $dateString = $date->toDateString();

                // 手動変更がある場合はスキップ
                if (isset($manualOverrides[$dateString])) {
                    $date->addDay();
                    continue;
                }

                $isHoliday = false;
                $reason = null;

                // 定休日チェック
                if ($setting->isRegularHoliday($date->dayOfWeek)) {
                    $isHoliday = true;
                    $reason = WmsWarehouseHolidaySetting::getDayLabels()[$date->dayOfWeek] ?? '定休日';
                }

                // 祝日チェック
                if (!$isHoliday && $setting->is_national_holiday_closed && isset($nationalHolidays[$dateString])) {
                    $isHoliday = true;
                    $reason = $nationalHolidays[$dateString];
                }

                WmsWarehouseCalendar::updateOrCreate(
                    [
                        'warehouse_id' => $warehouseId,
                        'target_date' => $dateString,
                    ],
                    [
                        'is_holiday' => $isHoliday,
                        'holiday_reason' => $reason,
                        'is_manual_override' => false,
                    ]
                );

                $processedCount++;
                $date->addDay();
            }
        });

        Log::info("Calendar generated for warehouse", [
            'warehouse_id' => $warehouseId,
            'processed_count' => $processedCount,
        ]);

        return $processedCount;
    }

    /**
     * 全倉庫のカレンダーを再生成
     */
    public function regenerateAllCalendars(int $months = 12): array
    {
        $results = [];

        $warehouseIds = WmsWarehouseAutoOrderSetting::enabled()
            ->pluck('warehouse_id');

        foreach ($warehouseIds as $warehouseId) {
            // 設定がなければ作成
            WmsWarehouseHolidaySetting::firstOrCreate(
                ['warehouse_id' => $warehouseId],
                [
                    'regular_holiday_days' => [0], // デフォルトは日曜休み
                    'is_national_holiday_closed' => true,
                ]
            );

            $count = $this->generateCalendar($warehouseId, $months);
            $results[$warehouseId] = $count;
        }

        return $results;
    }

    /**
     * 祝日マスタを生成
     */
    public function generateNationalHolidays(int $year): int
    {
        $holidays = WmsNationalHoliday::generateJapaneseHolidays($year);
        $count = 0;

        foreach ($holidays as $holiday) {
            WmsNationalHoliday::updateOrCreate(
                ['holiday_date' => $holiday['holiday_date']],
                ['holiday_name' => $holiday['holiday_name']]
            );
            $count++;
        }

        Log::info("National holidays generated", [
            'year' => $year,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * 手動で休日/営業日を設定
     */
    public function setManualOverride(int $warehouseId, Carbon $date, bool $isHoliday, ?string $reason = null): void
    {
        WmsWarehouseCalendar::updateOrCreate(
            [
                'warehouse_id' => $warehouseId,
                'target_date' => $date->toDateString(),
            ],
            [
                'is_holiday' => $isHoliday,
                'holiday_reason' => $reason ?? ($isHoliday ? '臨時休業' : null),
                'is_manual_override' => true,
            ]
        );
    }
}
