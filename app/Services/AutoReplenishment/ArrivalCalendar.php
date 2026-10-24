<?php

namespace App\Services\AutoReplenishment;

use App\Services\AutoOrder\JxOrderArrivalDateAdjustmentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Automatic replenishment only. Manual orders and JX retain their existing calendar. */
class ArrivalCalendar
{
    public function earliestArrivalDate(int $contractor, int $warehouse, Carbon $orderDate): ?Carbon
    {
        return $this->earliestArrivalDates($contractor, $warehouse, ['current' => $orderDate])['current'];
    }

    /** Manual FAX's next-day fallback is a labelled assumption, never used by execution. */
    public function referenceArrivalDates(int $contractor, int $warehouse, array $orderDates): array
    {
        return $this->resolve($contractor, $warehouse, $orderDates, true);
    }

    /** Bounded master reads for up to 56 opportunities; no persistent cache. */
    public function earliestArrivalDates(int $contractor, int $warehouse, array $orderDates): array
    {
        return $this->resolve($contractor, $warehouse, $orderDates, false);
    }

    private function resolve(int $contractor, int $warehouse, array $orderDates, bool $referenceOnly): array
    {
        $arrivals = app(JxOrderArrivalDateAdjustmentService::class)->earliestArrivalDates($contractor, $warehouse, $orderDates);
        if ($orderDates === []) {
            return [];
        }
        $delivery = $this->deliveryDays($contractor, $warehouse);
        // All weekdays OFF is common for manually arranged FAX orders. Do not
        // apply this fallback to EOS/logistics or to a fully booked holiday range.
        if ($referenceOnly && $delivery['weekdays'] === []) {
            $arrivals = array_map(fn (Carbon $day) => $day->copy()->addDay(), $orderDates);
            $delivery['weekdays'] = range(1, 6);
        }
        $dates = array_values(array_filter($arrivals));
        if ($dates === []) {
            return $arrivals;
        }
        $strings = array_map(fn (Carbon $day) => $day->toDateString(), $dates);
        $range = [min($strings), Carbon::parse(max($strings))->addDays(27)->toDateString()];
        $db = DB::connection('sakemaru');
        $holidays = array_merge(
            $db->table('wms_warehouse_calendars')->where(['warehouse_id' => $warehouse, 'is_holiday' => 1])
                ->whereBetween('target_date', $range)->pluck('target_date')->all(),
            $db->table('wms_contractor_holidays')->where('contractor_id', $contractor)
                ->whereBetween('holiday_date', $range)->pluck('holiday_date')->all());

        return array_map(fn (?Carbon $arrival) => $arrival
            ? $this->nextAllowed($arrival, $delivery['weekdays'], $holidays) : null, $arrivals);
    }

    public function deliveryDays(int $contractor, int $warehouse): array
    {
        $row = DB::connection('sakemaru')->table('wms_contractor_warehouse_delivery_days')
            ->where(['contractor_id' => $contractor, 'warehouse_id' => $warehouse])->first();
        // Missing settings retain the existing lead-time fallback, excluding Sunday.
        $allowed = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $index => $name) {
            if ($row === null || $row->{'delivery_'.$name}) {
                $allowed[] = $index + 1;
            }
        }

        return ['weekdays' => $allowed, 'sunday_master_enabled' => (bool) ($row->delivery_sun ?? false)];
    }

    public function nextAllowed(Carbon $date, array $allowed, array $holidays): ?Carbon
    {
        $arrival = $date->copy()->startOfDay();
        $holidays = array_fill_keys($holidays, true);
        for ($i = 0; $i < 28; $i++, $arrival->addDay()) {
            if (! $arrival->isSunday() && in_array($arrival->dayOfWeek, $allowed, true) && ! isset($holidays[$arrival->toDateString()])) {
                return $arrival;
            }
        }

        return null;
    }
}
