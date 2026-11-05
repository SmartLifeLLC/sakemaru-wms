<?php

namespace App\Services\AutoReplenishment;

use Carbon\Carbon;

/** Read-only counterpart of core UpdateStockTransfer::calculatePickingDateFromLeadDays. */
final class LogisticsPickingDate
{
    public function resolve(string $arrivalDate, int $leadDays): string
    {
        $arrival = Carbon::parse($arrivalDate);
        $picking = $arrival->copy()->subDays($leadDays);

        // Keep the core's Monday receipt / Sunday picking correction exactly.
        return ($arrival->isMonday() && $picking->isSunday() ? $arrival : $picking)->toDateString();
    }
}
