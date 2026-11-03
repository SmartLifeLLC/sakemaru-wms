<?php

namespace App\Services\AutoReplenishment;

use App\Services\AutoOrder\JxOrderArrivalDateAdjustmentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Automatic replenishment only. Manual orders and JX retain their existing calendar. */
class ArrivalCalendar
{
    /** JxOrderArrivalDateAdjustmentService::earliestArrivalDates accepts at most this many order dates per call. */
    public const BATCH_SIZE = 56;

    public function earliestArrivalDate(int $contractor, int $warehouse, Carbon $orderDate): ?Carbon
    {
        return $this->earliestArrivalDates($contractor, $warehouse, ['current' => $orderDate])['current'];
    }

    /** Manual FAX's next-day fallback is a labelled assumption, never used by execution. */
    public function referenceArrivalDates(int $contractor, int $warehouse, array $orderDates): array
    {
        return $this->resolve($contractor, $warehouse, $orderDates, true);
    }

    /** Bounded master reads, 56 opportunities per batch (longer horizons — the 10-week chart, the replay calendar — are read in batches); no persistent cache. */
    public function earliestArrivalDates(int $contractor, int $warehouse, array $orderDates): array
    {
        return $this->resolve($contractor, $warehouse, $orderDates, false);
    }

    private function resolve(int $contractor, int $warehouse, array $orderDates, bool $referenceOnly): array
    {
        if ($orderDates === []) {
            return [];
        }
        $adjustment = app(JxOrderArrivalDateAdjustmentService::class);
        $arrivals = [];
        $chunk = [];
        $minimum = $maximum = null;
        foreach ($orderDates as $key => $day) {
            $date = $day->toDateString();
            $from = $minimum === null ? $date : min($minimum, $date);
            $to = $maximum === null ? $date : max($maximum, $date);
            // Sparse execution weekdays can span more than 56 days with fewer than 56 entries.
            if ($chunk && (count($chunk) >= self::BATCH_SIZE || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > self::BATCH_SIZE)) {
                $arrivals += $adjustment->earliestArrivalDates($contractor, $warehouse, $chunk);
                $chunk = [];
                $from = $to = $date;
            }
            $chunk[$key] = $day;
            $minimum = $from;
            $maximum = $to;
        }
        $arrivals += $adjustment->earliestArrivalDates($contractor, $warehouse, $chunk);
        $delivery = $this->deliveryDays($contractor, $warehouse);
        // All weekdays OFF is common for manually arranged FAX orders. Do not
        // apply this fallback to EOS/logistics or to a fully booked holiday range.
        if ($referenceOnly && $delivery['weekdays'] === []) {
            $arrivals = array_map(fn (Carbon $day) => $day->copy()->addDay(), $orderDates);
            $delivery['weekdays'] = range(0, 6);
        }
        $dates = array_values(array_filter($arrivals));
        if ($dates === []) {
            return $arrivals;
        }
        $strings = array_map(fn (Carbon $day) => $day->toDateString(), $dates);
        $range = [min($strings), Carbon::parse(max($strings))->addDays(27)->toDateString()];
        $db = DB::connection('sakemaru');
        $holidays = array_merge(
            $this->warehouseHolidays($warehouse, $range),
            $db->table('wms_contractor_holidays')->where('contractor_id', $contractor)
                ->whereBetween('holiday_date', $range)->pluck('holiday_date')->all());

        return array_map(fn (?Carbon $arrival) => $arrival
            ? $this->nextAllowed($arrival, $delivery['weekdays'], $holidays) : null, $arrivals);
    }

    public function deliveryDays(int $contractor, int $warehouse): array
    {
        $row = DB::connection('sakemaru')->table('wms_contractor_warehouse_delivery_days')
            ->where(['contractor_id' => $contractor, 'warehouse_id' => $warehouse])->first();
        // Missing settings retain the existing lead-time fallback. Receiving
        // warehouse holidays are checked separately for every arrival date.
        $allowed = [];
        foreach (['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $index => $name) {
            if ($row === null || $row->{'delivery_'.$name}) {
                $allowed[] = $index;
            }
        }

        return ['weekdays' => $allowed, 'sunday_master_enabled' => (bool) ($row->delivery_sun ?? false)];
    }

    public function nextAllowed(Carbon $date, array $allowed, array $holidays): ?Carbon
    {
        $arrival = $date->copy()->startOfDay();
        $holidays = array_fill_keys($holidays, true);
        for ($i = 0; $i < 28; $i++, $arrival->addDay()) {
            if (in_array($arrival->dayOfWeek, $allowed, true) && ! isset($holidays[$arrival->toDateString()])) {
                return $arrival;
            }
        }

        return null;
    }

    /** Dated receiving rules override the warehouse's Sunday default; no global Sunday closure. */
    private function warehouseHolidays(int $warehouse, array $range): array
    {
        $db = DB::connection('sakemaru');
        $dated = $db->table('wms_warehouse_calendars')->where('warehouse_id', $warehouse)
            ->whereBetween('target_date', $range)->pluck('is_holiday', 'target_date')->all();
        $sundayClosed = (bool) $db->table('wms_warehouse_auto_order_settings')->where('warehouse_id', $warehouse)
            ->value('exclude_sunday_arrival');
        $holidays = [];
        for ($day = Carbon::parse($range[0]); $day->toDateString() <= $range[1]; $day->addDay()) {
            $date = $day->toDateString();
            if (array_key_exists($date, $dated) ? (bool) $dated[$date] : ($sundayClosed && $day->isSunday())) {
                $holidays[] = $date;
            }
        }

        return $holidays;
    }
}
