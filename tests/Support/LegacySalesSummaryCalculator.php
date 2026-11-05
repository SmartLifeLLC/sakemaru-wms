<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;

/**
 * Independent, in-memory oracle for SyncSalesSummariesCommand before its
 * October 2026 optimization. Deliberately keeps the original window order,
 * eligibility, missing-row behavior, and dry-run counting; do not share
 * calculations or constants with the production implementation.
 */
final class LegacySalesSummaryCalculator
{
    public const WINDOWS = [3, 5, 7, 14, 30];

    public static function emptySummary(int $warehouseId, int $itemId): array
    {
        $row = ['warehouse_id' => $warehouseId, 'item_id' => $itemId];
        foreach (self::WINDOWS as $days) {
            $row["last_{$days}d_qty"] = 0;
            $row["avg_{$days}d_qty"] = 0.0;
        }

        return $row + [
            'sales_today_qty' => 0,
            'sales_yesterday_qty' => 0,
            'sales_2days_ago_qty' => 0,
            'last_shipped_at' => null,
        ];
    }

    public static function calculate(
        array $daily,
        array $summaries,
        string $to,
        ?int $warehouseId = null,
        bool $dryRun = false,
        string $now = '2026-10-04 22:00:00',
    ): array {
        $coverage = [];
        foreach ($daily as $row) {
            $id = $row['warehouse_id'];
            $coverage[$id] = isset($coverage[$id])
                ? min($coverage[$id], $row['business_date']) : $row['business_date'];
        }

        $state = [];
        foreach ($summaries as $row) {
            $state[$row['warehouse_id'].':'.$row['item_id']] = array_intersect_key(
                $row,
                self::emptySummary($row['warehouse_id'], $row['item_id'])
                    + ['created_at' => null, 'updated_at' => null, 'calculated_at' => null],
            );
        }

        $counts = [];
        foreach (self::WINDOWS as $days) {
            $from = CarbonImmutable::parse($to)->subDays($days - 1)->toDateString();
            $eligible = self::eligibleWarehouses($coverage, $from, $warehouseId);
            $groups = self::groupRows($daily, $from, $to, $eligible);
            $counts[$days] = count($groups);

            foreach ($groups as $key => $rows) {
                if ($dryRun) {
                    continue;
                }
                $row = $rows[0];
                $state[$key] ??= self::emptySummary($row['warehouse_id'], $row['item_id']) + ['created_at' => $now];
                $quantity = (int) array_sum(array_column($rows, 'shipped_piece_qty'));
                $positiveDates = array_column(array_filter($rows, fn ($r) => $r['shipped_piece_qty'] > 0), 'business_date');
                $state[$key]["last_{$days}d_qty"] = $quantity;
                $state[$key]["avg_{$days}d_qty"] = round($quantity / $days, 2);
                $state[$key]['last_shipped_at'] = $positiveDates === [] ? null : max($positiveDates);
                $state[$key]['calculated_at'] = $now;
                $state[$key]['updated_at'] = $now;
            }

            // The original COUNT happens after the preceding windows' writes.
            foreach ($state as $key => &$row) {
                if (isset($eligible[$row['warehouse_id']]) && ! isset($groups[$key])) {
                    $counts[$days]++;
                    if (! $dryRun) {
                        $row["last_{$days}d_qty"] = 0;
                        $row["avg_{$days}d_qty"] = 0.0;
                        $row['calculated_at'] = $now;
                        $row['updated_at'] = $now;
                    }
                }
            }
            unset($row);
        }

        $from = CarbonImmutable::parse($to)->subDays(2)->toDateString();
        $eligible = self::eligibleWarehouses($coverage, $from, $warehouseId);
        $groups = self::groupRows($daily, $from, $to, $eligible);
        $dailyCount = count($groups);
        if (! $dryRun) {
            foreach ($state as $key => &$row) {
                if (! isset($eligible[$row['warehouse_id']])) {
                    continue;
                }
                foreach (['sales_today_qty', 'sales_yesterday_qty', 'sales_2days_ago_qty'] as $offset => $column) {
                    $date = CarbonImmutable::parse($to)->subDays($offset)->toDateString();
                    $row[$column] = (int) array_sum(array_column(array_filter(
                        $groups[$key] ?? [],
                        fn ($r) => $r['business_date'] === $date,
                    ), 'shipped_piece_qty'));
                }
                $row['calculated_at'] = $now;
                $row['updated_at'] = $now;
            }
            unset($row);
        }

        ksort($state);

        return ['rows' => $state, 'counts' => $counts, 'daily_count' => $dailyCount];
    }

    private static function eligibleWarehouses(array $coverage, string $from, ?int $warehouseId): array
    {
        return array_filter($coverage, fn ($first, $id) => $first <= $from && (! $warehouseId || $warehouseId === $id), ARRAY_FILTER_USE_BOTH);
    }

    private static function groupRows(array $daily, string $from, string $to, array $eligible): array
    {
        $groups = [];
        foreach ($daily as $row) {
            if (isset($eligible[$row['warehouse_id']]) && $row['business_date'] >= $from && $row['business_date'] <= $to) {
                $groups[$row['warehouse_id'].':'.$row['item_id']][] = $row;
            }
        }

        return $groups;
    }
}
