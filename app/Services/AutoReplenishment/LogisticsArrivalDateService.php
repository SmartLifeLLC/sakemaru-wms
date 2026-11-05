<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use Carbon\Carbon;

class LogisticsArrivalDateService
{
    public function resolve(WmsItemReorderPolicy $policy, string $businessDate): Carbon
    {
        $arrival = app(ArrivalCalendar::class)->earliestArrivalDate(
            (int) $policy->contractor_id, (int) $policy->warehouse_id, Carbon::parse($businessDate));
        throw_unless($arrival, \RuntimeException::class, '入荷可能日がありません。発注先・倉庫の納品曜日と休日設定を確認してください。');

        return $arrival;
    }
}
