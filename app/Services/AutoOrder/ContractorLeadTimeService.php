<?php

namespace App\Services\AutoOrder;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\LeadTime;
use App\Models\WmsContractorHoliday;
use Carbon\Carbon;

/**
 * 発注先リードタイム計算サービス
 *
 * lead_times テーブルの曜日別リードタイムと
 * wms_contractor_holidays の臨時休業を考慮して到着予定日を計算
 */
class ContractorLeadTimeService
{
    /**
     * 発注先のリードタイムを考慮した到着予定日を計算
     *
     * @param  Contractor  $contractor  発注先
     * @param  Carbon  $orderDate  発注日
     * @return array{
     *     arrival_date: Carbon,
     *     original_date: Carbon,
     *     lead_time_days: int,
     *     shifted_days: int,
     *     shift_reason: string|null
     * }
     */
    public function calculateArrivalDate(Contractor $contractor, Carbon $orderDate): array
    {
        $leadTime = $contractor->leadTime;

        if (! $leadTime) {
            // lead_time設定がない場合はデフォルト1日
            return $this->buildResult($orderDate->copy()->addDay(), $orderDate->copy()->addDay(), 1, 0);
        }

        // 発注日の曜日に基づいてリードタイムを取得
        $leadTimeDays = $this->getLeadTimeDaysByDayOfWeek($leadTime, $orderDate->dayOfWeek);

        // 到着予定日を計算
        $arrivalDate = $orderDate->copy()->addDays($leadTimeDays);
        $originalDate = $arrivalDate->copy();
        $shiftedDays = 0;
        $shiftReason = null;

        // 発注先の臨時休業をスキップ（最大30日まで）
        for ($i = 0; $i < 30; $i++) {
            if (! WmsContractorHoliday::isHoliday($contractor->id, $arrivalDate)) {
                break;
            }
            $arrivalDate->addDay();
            $shiftedDays++;
            $shiftReason = '発注先臨時休業';
        }

        return $this->buildResult($arrivalDate, $originalDate, $leadTimeDays, $shiftedDays, $shiftReason);
    }

    /**
     * 曜日に基づいてリードタイム日数を取得
     *
     * @param  int  $dayOfWeek  0=日曜, 1=月曜, ..., 6=土曜
     */
    private function getLeadTimeDaysByDayOfWeek(LeadTime $leadTime, int $dayOfWeek): int
    {
        return match ($dayOfWeek) {
            0 => $leadTime->lead_time_sun ?? 1,
            1 => $leadTime->lead_time_mon ?? 1,
            2 => $leadTime->lead_time_tue ?? 1,
            3 => $leadTime->lead_time_wed ?? 1,
            4 => $leadTime->lead_time_thu ?? 1,
            5 => $leadTime->lead_time_fri ?? 1,
            6 => $leadTime->lead_time_sat ?? 1,
            default => 1,
        };
    }

    /**
     * 結果配列を構築
     */
    private function buildResult(
        Carbon $arrivalDate,
        Carbon $originalDate,
        int $leadTimeDays,
        int $shiftedDays,
        ?string $shiftReason = null
    ): array {
        return [
            'arrival_date' => $arrivalDate,
            'original_date' => $originalDate,
            'lead_time_days' => $leadTimeDays,
            'shifted_days' => $shiftedDays,
            'shift_reason' => $shiftReason,
        ];
    }

    /**
     * 発注先の曜日別リードタイム情報を取得
     */
    public function getLeadTimeInfo(Contractor $contractor): ?array
    {
        $leadTime = $contractor->leadTime;

        if (! $leadTime) {
            return null;
        }

        return [
            'sun' => $leadTime->lead_time_sun,
            'mon' => $leadTime->lead_time_mon,
            'tue' => $leadTime->lead_time_tue,
            'wed' => $leadTime->lead_time_wed,
            'thu' => $leadTime->lead_time_thu,
            'fri' => $leadTime->lead_time_fri,
            'sat' => $leadTime->lead_time_sat,
        ];
    }
}
