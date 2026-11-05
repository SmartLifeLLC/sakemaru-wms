<?php

namespace App\Services\AutoReplenishment;

/** Read-time exclusions only: actual sales, receipts and stock remain unchanged. */
final class DemandObservationExclusions
{
    public function ranges(int $client, int $warehouse): array
    {
        return array_values(array_filter(config('replenishment.demand_exclusions', []),
            fn ($r) => $r['client_id'] === $client && $r['warehouse_id'] === $warehouse));
    }

    public function contains(int $client, int $warehouse, string $date): bool
    {
        foreach ($this->ranges($client, $warehouse) as $range) {
            if ($date >= $range['start'] && $date <= $range['end']) {
                return true;
            }
        }

        return false;
    }

    public function mask(int $client, int $warehouse, array $daily): array
    {
        foreach ($daily as $date => $quantity) {
            if ($this->contains($client, $warehouse, $date)) {
                $daily[$date] = null; // Unobserved, never a zero-demand trading day.
            }
        }

        return $daily;
    }

    /** Fixed store cohort throughout a learning window avoids a closure changing the aggregate baseline. */
    public function learningStores(int $client, array $stores, string $start, string $end): array
    {
        return array_values(array_filter($stores, function ($warehouse) use ($client, $start, $end) {
            foreach ($this->ranges($client, (int) $warehouse) as $range) {
                if ($range['start'] <= $end && $range['end'] >= $start) {
                    return false;
                }
            }

            return true;
        }));
    }
}
