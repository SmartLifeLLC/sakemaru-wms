<?php

namespace App\Services\Distribution;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DistributionDeliveryDateService
{
    /**
     * @param  Collection<int|string, object>  $contractorsByItem
     * @return Collection<int|string, array<string, mixed>>
     */
    public function resolveForItems(int $warehouseId, Collection $contractorsByItem, Carbon $orderDate): Collection
    {
        $availableContractors = $contractorsByItem
            ->filter(fn ($contractor): bool => (int) ($contractor->contractor_id ?? 0) > 0);

        $contractorIds = $availableContractors
            ->pluck('contractor_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($contractorIds->isEmpty()) {
            return collect();
        }

        $warehouseIds = $availableContractors
            ->map(fn ($contractor): int => (int) ($contractor->warehouse_id ?? 0) ?: $warehouseId)
            ->filter()
            ->unique()
            ->values();

        $leadTimes = $this->loadLeadTimes($contractorIds->all());
        $deliveryDays = $this->loadDeliveryDays($warehouseIds->all(), $contractorIds->all());
        $periodEnd = $orderDate->copy()->addDays(90);
        $contractorHolidays = $this->loadContractorHolidays($contractorIds->all(), $orderDate, $periodEnd);
        $warehouseHolidays = $this->loadWarehouseHolidays($warehouseIds->all(), $orderDate, $periodEnd);

        return $contractorsByItem->mapWithKeys(function ($contractor, int|string $itemKey) use (
            $warehouseId,
            $orderDate,
            $leadTimes,
            $deliveryDays,
            $contractorHolidays,
            $warehouseHolidays
        ): array {
            $contractorId = (int) ($contractor->contractor_id ?? 0);
            if ($contractorId < 1) {
                return [$itemKey => null];
            }

            $targetWarehouseId = (int) ($contractor->warehouse_id ?? 0) ?: $warehouseId;

            return [
                $itemKey => $this->calculate(
                    contractorId: $contractorId,
                    warehouseId: $targetWarehouseId,
                    orderDate: $orderDate,
                    leadTime: $leadTimes->get($contractorId),
                    deliveryDays: $deliveryDays->get($this->deliveryDayKey($contractorId, $targetWarehouseId)),
                    contractorHolidays: $contractorHolidays->get($contractorId, []),
                    warehouseHolidays: $warehouseHolidays->get($targetWarehouseId, []),
                ),
            ];
        })->filter();
    }

    /**
     * @return array<string, mixed>
     */
    private function calculate(
        int $contractorId,
        int $warehouseId,
        Carbon $orderDate,
        ?object $leadTime,
        ?array $deliveryDays,
        array $contractorHolidays,
        array $warehouseHolidays
    ): array {
        $acceptedOrderDate = $orderDate->copy();
        $shiftedDays = 0;
        $shiftReasons = [];

        for ($i = 0; $i < 30; $i++) {
            if (! isset($contractorHolidays[$acceptedOrderDate->toDateString()])) {
                break;
            }

            $acceptedOrderDate->addDay();
            $shiftedDays++;
        }

        if (! $acceptedOrderDate->isSameDay($orderDate)) {
            $shiftReasons[] = '発注先休業日のため発注受付日を調整';
        }

        $leadTimeDays = $this->leadTimeDays($leadTime, $acceptedOrderDate->dayOfWeek);
        $arrivalDate = $acceptedOrderDate->copy()->addDays($leadTimeDays);
        $originalArrivalDate = $arrivalDate->copy();

        for ($i = 0; $i < 60; $i++) {
            $dateString = $arrivalDate->toDateString();

            if (isset($contractorHolidays[$dateString])) {
                $arrivalDate->addDay();
                $shiftedDays++;
                $shiftReasons[] = '発注先休業日';

                continue;
            }

            if ($deliveryDays !== null && $this->hasDeliveryDays($deliveryDays) && ! $this->canDeliverOn($deliveryDays, $arrivalDate->dayOfWeek)) {
                $arrivalDate->addDay();
                $shiftedDays++;
                $shiftReasons[] = '納品可能曜日';

                continue;
            }

            if (isset($warehouseHolidays[$dateString])) {
                $arrivalDate->addDay();
                $shiftedDays++;
                $shiftReasons[] = '店舗休業日';

                continue;
            }

            break;
        }

        return [
            'suggested_delivery_date' => $arrivalDate->toDateString(),
            'original_delivery_date' => $originalArrivalDate->toDateString(),
            'accepted_order_date' => $acceptedOrderDate->toDateString(),
            'lead_time_days' => $leadTimeDays,
            'shifted_days' => $shiftedDays,
            'shift_reasons' => array_values(array_unique($shiftReasons)),
            'warehouse_id' => $warehouseId,
            'contractor_id' => $contractorId,
        ];
    }

    private function leadTimeDays(?object $leadTime, int $dayOfWeek): int
    {
        if (! $leadTime) {
            return 1;
        }

        $days = match ($dayOfWeek) {
            0 => $leadTime->lead_time_sun ?? 1,
            1 => $leadTime->lead_time_mon ?? 1,
            2 => $leadTime->lead_time_tue ?? 1,
            3 => $leadTime->lead_time_wed ?? 1,
            4 => $leadTime->lead_time_thu ?? 1,
            5 => $leadTime->lead_time_fri ?? 1,
            6 => $leadTime->lead_time_sat ?? 1,
            default => 1,
        };

        return max(0, (int) $days);
    }

    private function hasDeliveryDays(array $deliveryDays): bool
    {
        return in_array(true, $deliveryDays, true);
    }

    private function canDeliverOn(array $deliveryDays, int $dayOfWeek): bool
    {
        return match ($dayOfWeek) {
            0 => $deliveryDays['sun'] ?? false,
            1 => $deliveryDays['mon'] ?? false,
            2 => $deliveryDays['tue'] ?? false,
            3 => $deliveryDays['wed'] ?? false,
            4 => $deliveryDays['thu'] ?? false,
            5 => $deliveryDays['fri'] ?? false,
            6 => $deliveryDays['sat'] ?? false,
            default => false,
        };
    }

    /**
     * @param  array<int, int>  $contractorIds
     * @return Collection<int, object>
     */
    private function loadLeadTimes(array $contractorIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('contractors as c')
            ->leftJoin('lead_times as lt', 'lt.id', '=', 'c.lead_time_id')
            ->whereIn('c.id', $contractorIds)
            ->get([
                'c.id as contractor_id',
                'lt.lead_time_sun',
                'lt.lead_time_mon',
                'lt.lead_time_tue',
                'lt.lead_time_wed',
                'lt.lead_time_thu',
                'lt.lead_time_fri',
                'lt.lead_time_sat',
            ])
            ->keyBy(fn ($row): int => (int) $row->contractor_id);
    }

    /**
     * @param  array<int, int>  $warehouseIds
     * @param  array<int, int>  $contractorIds
     * @return Collection<string, array<string, bool>>
     */
    private function loadDeliveryDays(array $warehouseIds, array $contractorIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('wms_contractor_warehouse_delivery_days')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('contractor_id', $contractorIds)
            ->get([
                'warehouse_id',
                'contractor_id',
                'delivery_mon',
                'delivery_tue',
                'delivery_wed',
                'delivery_thu',
                'delivery_fri',
                'delivery_sat',
                'delivery_sun',
            ])
            ->keyBy(fn ($row): string => $this->deliveryDayKey((int) $row->contractor_id, (int) $row->warehouse_id))
            ->map(fn ($row): array => [
                'mon' => (bool) $row->delivery_mon,
                'tue' => (bool) $row->delivery_tue,
                'wed' => (bool) $row->delivery_wed,
                'thu' => (bool) $row->delivery_thu,
                'fri' => (bool) $row->delivery_fri,
                'sat' => (bool) $row->delivery_sat,
                'sun' => (bool) $row->delivery_sun,
            ]);
    }

    /**
     * @param  array<int, int>  $contractorIds
     * @return Collection<int, array<string, true>>
     */
    private function loadContractorHolidays(array $contractorIds, Carbon $startDate, Carbon $endDate): Collection
    {
        return DB::connection('sakemaru')
            ->table('wms_contractor_holidays')
            ->whereIn('contractor_id', $contractorIds)
            ->whereBetween('holiday_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get(['contractor_id', 'holiday_date'])
            ->groupBy(fn ($row): int => (int) $row->contractor_id)
            ->map(fn (Collection $rows): array => $rows
                ->mapWithKeys(fn ($row): array => [(string) $row->holiday_date => true])
                ->all());
    }

    /**
     * @param  array<int, int>  $warehouseIds
     * @return Collection<int, array<string, true>>
     */
    private function loadWarehouseHolidays(array $warehouseIds, Carbon $startDate, Carbon $endDate): Collection
    {
        return DB::connection('sakemaru')
            ->table('wms_warehouse_calendars')
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('is_holiday', true)
            ->whereBetween('target_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get(['warehouse_id', 'target_date'])
            ->groupBy(fn ($row): int => (int) $row->warehouse_id)
            ->map(fn (Collection $rows): array => $rows
                ->mapWithKeys(fn ($row): array => [(string) $row->target_date => true])
                ->all());
    }

    private function deliveryDayKey(int $contractorId, int $warehouseId): string
    {
        return "{$contractorId}:{$warehouseId}";
    }
}
