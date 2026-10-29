<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsNationalHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bounded SELECT-only reads of past daily product sales (store totals) for event learning.
 *
 * 2024-05-05 onwards: stats_item_warehouse_daily_sales (WMS). Earlier: stats_sales_csv_item_lines
 * (legacy retail sales CSV originals, one import run per period; duplicate runs are resolved per month).
 */
class DemandEventHistoryReader
{
    public const WMS_STATS_FROM = '2024-05-05';

    private array $cache = [];

    private int $client = 0;

    /** Active store warehouses (non-virtual, excluding the preserved hub). */
    public function storeWarehouseIds(int $client): array
    {
        return $this->cache['stores:'.$client] ??= (function () use ($client) {
            $preserved = config('replenishment.initial_reset_preserve_warehouse_codes.'.config('wms.client'), []);

            return DB::connection('sakemaru')->table('warehouses')->where(['client_id' => $client, 'is_active' => 1, 'is_virtual' => 0])
                ->whereNotIn('code', $preserved ?: [-1])->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        })();
    }

    /** date => name for one year: registered holidays first, generated Japanese holidays as the fallback. */
    public function holidays(int $year): array
    {
        $generated = array_column(WmsNationalHoliday::generateJapaneseHolidays($year), 'holiday_name', 'holiday_date');
        $registered = WmsNationalHoliday::query()->forYear($year)->pluck('holiday_name', 'holiday_date')
            ->mapWithKeys(fn ($name, $date) => [CarbonImmutable::parse($date)->toDateString() => (string) $name])->all();

        return $registered + $generated;
    }

    /** item => category3 id for the client's products. */
    public function itemCategories(int $client): array
    {
        return $this->cache['categories:'.$client] ??= DB::connection('sakemaru')->table('items')->where('client_id', $client)
            ->whereNotNull('item_category3_id')->pluck('item_category3_id', 'id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Daily product totals over the stores for [start, end]: item => [date => pieces].
     * Dates without any row are left out of 'observed' and must be treated as unobserved, not zero.
     *
     * @return array{items: array<int, array<string, int>>, observed: array<string, true>}
     */
    public function productTotals(int $client, string $start, string $end, ?array $itemIds = null): array
    {
        $this->client = $client;
        $stores = $this->storeWarehouseIds($client);
        throw_unless($stores, \RuntimeException::class, '対象の店舗倉庫がありません。');
        $items = [];
        $observed = [];
        $cursor = CarbonImmutable::parse($start);
        $last = CarbonImmutable::parse($end);
        while ($cursor->lte($last)) {
            $segmentEnd = $cursor->endOfMonth()->min($last);
            $rows = $cursor->toDateString() >= self::WMS_STATS_FROM
                ? $this->wmsRows($stores, $cursor->toDateString(), $segmentEnd->toDateString(), $itemIds)
                : $this->legacyRows($stores, $cursor->toDateString(), $segmentEnd->min(CarbonImmutable::parse(self::WMS_STATS_FROM)->subDay())->toDateString(), $itemIds);
            if ($cursor->toDateString() < self::WMS_STATS_FROM && $segmentEnd->toDateString() >= self::WMS_STATS_FROM) {
                $rows = array_merge($rows, $this->wmsRows($stores, self::WMS_STATS_FROM, $segmentEnd->toDateString(), $itemIds));
            }
            foreach ($rows as $row) {
                $items[(int) $row->item_id][$row->date] = ($items[(int) $row->item_id][$row->date] ?? 0) + (int) round((float) $row->pieces);
                $observed[$row->date] = true;
            }
            $cursor = $segmentEnd->addDay();
        }

        return ['items' => $items, 'observed' => $observed];
    }

    private function wmsRows(array $stores, string $start, string $end, ?array $itemIds): array
    {
        return DB::connection('sakemaru')->table('stats_item_warehouse_daily_sales')->whereIn('warehouse_id', $stores)
            ->whereBetween('business_date', [$start, $end])->when($itemIds !== null, fn ($q) => $q->whereIn('item_id', $itemIds))
            ->groupBy('item_id', 'business_date')->selectRaw('item_id, business_date date, SUM(sales_piece_qty) pieces')->get()->all();
    }

    private function legacyRows(array $stores, string $start, string $end, ?array $itemIds): array
    {
        if ($start > $end) {
            return [];
        }
        $db = DB::connection('sakemaru');
        $base = fn () => $db->table('stats_sales_csv_item_lines')->where(['source_system' => 'legacy_t2_retail', 'period_type' => 'current'])
            ->where(fn ($q) => $q->where('client_id', $this->client)->orWhereNull('client_id'))->whereBetween('business_date', [$start, $end]);
        // Several import runs carry the same period; use the run with the fullest coverage of this month.
        $run = $base()->groupBy('run_id')->selectRaw('run_id, COUNT(*) c')->orderByDesc('c')->orderByDesc('run_id')->value('run_id');
        if ($run === null) {
            return [];
        }

        return $base()->where('run_id', $run)->whereIn('warehouse_id', $stores)->whereNotNull('item_id')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('item_id', $itemIds))
            ->groupBy('item_id', 'business_date')->selectRaw('item_id, business_date date, SUM(sales_quantity) pieces')->get()->all();
    }
}
