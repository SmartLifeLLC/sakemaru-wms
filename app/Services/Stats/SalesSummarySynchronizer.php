<?php

namespace App\Services\Stats;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

class SalesSummarySynchronizer
{
    private const WINDOWS = [3, 5, 7, 14, 30];

    private const DAILY_COLUMNS = ['sales_today_qty', 'sales_yesterday_qty', 'sales_2days_ago_qty'];

    /**
     * Calculate every window from one bounded read. A missing group is distinct
     * from a group whose sales and returns add up to zero.
     *
     * @return array{summary_counts: array<int, int>, daily_breakdown_count: int}
     */
    public function sync(
        CarbonImmutable $to,
        ?int $warehouseId,
        Collection $coverage,
        bool $dryRun,
        ?PDO $lockedPdo = null
    ): array {
        $counts = array_fill_keys(self::WINDOWS, 0);
        $matured = [];
        foreach (self::WINDOWS as $days) {
            $from = $to->subDays($days - 1);
            $matured[$days] = $coverage
                ->filter(fn (CarbonImmutable $firstDate, int $id): bool => (! $warehouseId || $id === $warehouseId)
                    && $firstDate->lessThanOrEqualTo($from))
                ->map(fn (): bool => true)
                ->all();
        }

        if ($matured[3] === []) {
            return ['summary_counts' => $counts, 'daily_breakdown_count' => 0];
        }

        $connection = DB::connection('sakemaru');
        $aggregateQuery = $connection->table('stats_item_warehouse_daily_sales')
            ->useWritePdo()
            ->whereIn('warehouse_id', array_keys($matured[3]))
            ->whereBetween('business_date', [$to->subDays(29)->toDateString(), $to->toDateString()])
            ->select('warehouse_id', 'item_id')
            ->groupBy('warehouse_id', 'item_id');

        foreach (self::WINDOWS as $days) {
            $from = $to->subDays($days - 1)->toDateString();
            $aggregateQuery->selectRaw(
                "COUNT(CASE WHEN business_date >= ? THEN 1 END) AS rows_{$days}d, "
                ."SUM(CASE WHEN business_date >= ? THEN shipped_piece_qty ELSE 0 END) AS qty_{$days}d, "
                ."MAX(CASE WHEN business_date >= ? AND shipped_piece_qty > 0 THEN business_date END) AS last_{$days}d",
                [$from, $from, $from]
            );
        }
        foreach (self::DAILY_COLUMNS as $offset => $column) {
            $aggregateQuery->selectRaw(
                "SUM(CASE WHEN business_date = ? THEN shipped_piece_qty ELSE 0 END) AS {$column}",
                [$to->subDays($offset)->toDateString()]
            );
        }

        $key = static fn (object $row): string => "{$row->warehouse_id}:{$row->item_id}";
        $aggregates = $aggregateQuery->get()->keyBy($key);
        $existing = $connection->table('stats_item_warehouse_sales_summaries')
            ->useWritePdo()
            ->whereIn('warehouse_id', array_keys($matured[3]))
            ->select(['warehouse_id', 'item_id', ...$this->valueColumns(), 'created_at'])
            ->cursor();

        $now = now();
        $rows = [];
        $dailyCount = 0;

        // Stream existing rows and release consumed aggregates instead of
        // retaining both full input collections alongside the final rows.
        foreach ($existing as $existingRow) {
            $rowKey = $key($existingRow);
            $row = (array) $existingRow;
            $this->calculateRow($row, $aggregates->pull($rowKey), true, $matured, $dryRun, $counts, $dailyCount);
            if (! $dryRun) {
                $rows[] = [...$row, 'calculated_at' => $now, 'updated_at' => $now];
            }
        }
        foreach ($aggregates->keys() as $rowKey) {
            $aggregate = $aggregates->pull($rowKey);
            $row = [
                'warehouse_id' => (int) $aggregate->warehouse_id,
                'item_id' => (int) $aggregate->item_id,
                ...array_fill_keys($this->valueColumns(), 0),
                'last_shipped_at' => null,
                'created_at' => $now,
            ];
            $created = $this->calculateRow($row, $aggregate, false, $matured, $dryRun, $counts, $dailyCount);
            if (! $dryRun && $created) {
                $rows[] = [...$row, 'calculated_at' => $now, 'updated_at' => $now];
            }
        }

        if ($rows !== []) {
            usort($rows, static fn (array $a, array $b): int => [$a['warehouse_id'], $a['item_id']] <=> [$b['warehouse_id'], $b['item_id']]);

            // Only publication is transactional; the heavy read and PHP work
            // have finished. Readers cannot see a mixture of window versions.
            $connection->transaction(function () use ($connection, $lockedPdo, $rows): void {
                if ($lockedPdo !== null && $connection->getPdo() !== $lockedPdo) {
                    throw new RuntimeException('集計中にDB接続が再確立されたため、サマリの保存を中止しました。再実行してください。');
                }

                foreach (array_chunk($rows, 1000) as $chunk) {
                    $connection->table('stats_item_warehouse_sales_summaries')
                        ->upsert($chunk, ['warehouse_id', 'item_id'], [...$this->valueColumns(), 'calculated_at', 'updated_at']);
                }
            });
        }

        return ['summary_counts' => $counts, 'daily_breakdown_count' => $dailyCount];
    }

    /**
     * Apply the legacy 3 -> 5 -> 7 -> 14 -> 30 day order, preserving columns of
     * immature windows and the last shipment date when a window has no rows.
     */
    private function calculateRow(
        array &$row,
        ?object $aggregate,
        bool $exists,
        array $matured,
        bool $dryRun,
        array &$counts,
        int &$dailyCount
    ): bool {
        $warehouseId = (int) $row['warehouse_id'];
        foreach (self::WINDOWS as $days) {
            if (! isset($matured[$days][$warehouseId])) {
                continue;
            }

            if ((int) ($aggregate->{"rows_{$days}d"} ?? 0) > 0) {
                $quantity = (int) $aggregate->{"qty_{$days}d"};
                $row["last_{$days}d_qty"] = $quantity;
                $row["avg_{$days}d_qty"] = round($quantity / $days, 2);
                $row['last_shipped_at'] = $aggregate->{"last_{$days}d"};
                $counts[$days]++;
                if (! $dryRun) {
                    $exists = true;
                }
            } elseif ($exists) {
                $row["last_{$days}d_qty"] = 0;
                $row["avg_{$days}d_qty"] = 0;
                $counts[$days]++;
            }
        }

        if ((int) ($aggregate->rows_3d ?? 0) > 0) {
            foreach (self::DAILY_COLUMNS as $column) {
                $row[$column] = (int) $aggregate->{$column};
            }
            $dailyCount++;
        } elseif ($exists) {
            foreach (self::DAILY_COLUMNS as $column) {
                $row[$column] = 0;
            }
        }

        return $exists;
    }

    /** @return array<string> */
    private function valueColumns(): array
    {
        $columns = self::DAILY_COLUMNS;
        foreach (self::WINDOWS as $days) {
            $columns[] = "last_{$days}d_qty";
            $columns[] = "avg_{$days}d_qty";
        }

        return [...$columns, 'last_shipped_at'];
    }
}
