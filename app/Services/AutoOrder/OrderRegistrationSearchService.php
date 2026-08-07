<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\ItemCategory;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsContractorSetting;
use App\Models\WmsWarehouseAutoOrderSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderRegistrationSearchService
{
    /** @var array<string, string> */
    private array $defaultExpectedArrivalDateCache = [];

    /**
     * @return array<int, array{id: int, code: string, name: string, label: string}>
     */
    public function warehouseOptions(): array
    {
        return Warehouse::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Warehouse $warehouse): array => [
                'id' => (int) $warehouse->id,
                'code' => (string) $warehouse->code,
                'name' => (string) $warehouse->name,
                'label' => "[{$warehouse->code}]{$warehouse->name}",
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public function category2Options(): array
    {
        return ItemCategory::query()
            ->where('is_active', true)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('items')
                    ->whereColumn('items.item_category2_id', 'item_categories.id')
                    ->where('items.end_of_sale_type', 'NORMAL')
                    ->where('items.is_ended', false);
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (ItemCategory $category): array => [
                'id' => (int) $category->id,
                'code' => (string) $category->code,
                'name' => (string) $category->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int>
     */
    public function jxContractorIds(): array
    {
        $jxContractorIds = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::JX_FINET->value)
            ->pluck('contractor_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($jxContractorIds === []) {
            return [];
        }

        $aggregatedContractorIds = WmsContractorSetting::query()
            ->whereIn('transmission_contractor_id', $jxContractorIds)
            ->pluck('contractor_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($jxContractorIds, $aggregatedContractorIds)));
    }

    public function isJxContractor(int $contractorId): bool
    {
        return in_array($contractorId, $this->jxContractorIds(), true);
    }

    /**
     * @param  array{item_search?: string|null, contractor_search?: string|null, category2_id?: int|null, limit?: int|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function searchItems(int $warehouseId, OrderChannel $channel, array $filters = []): array
    {
        $incomingWarehouseId = $this->incomingWarehouseId($warehouseId);
        $jxContractorIds = $this->jxContractorIds();

        if ($channel === OrderChannel::EOS && $jxContractorIds === []) {
            return [];
        }

        $itemSearch = trim(mb_convert_kana((string) ($filters['item_search'] ?? ''), 'as'));
        $contractorSearch = trim(mb_convert_kana((string) ($filters['contractor_search'] ?? ''), 'as'));
        $category2Id = (int) ($filters['category2_id'] ?? 0);
        $limit = max(10, min(100, (int) ($filters['limit'] ?? 50)));
        $internalContractorIds = $this->internalContractorIds();

        $query = DB::connection('sakemaru')
            ->table('item_contractors as ic')
            ->join('items', 'items.id', '=', 'ic.item_id')
            ->join('contractors', 'contractors.id', '=', 'ic.contractor_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'ic.supplier_id')
            ->leftJoin('partners as supplier_partners', 'supplier_partners.id', '=', 'suppliers.partner_id')
            ->where('ic.warehouse_id', $incomingWarehouseId)
            ->where('items.end_of_sale_type', 'NORMAL')
            ->where('items.is_ended', false)
            ->where(fn ($query) => $query->whereNull('items.start_of_sale_date')->orWhere('items.start_of_sale_date', '<=', now()->toDateString()))
            ->where(fn ($query) => $query->whereNull('items.end_of_sale_date')->orWhere('items.end_of_sale_date', '>', now()->toDateString()))
            ->when($internalContractorIds !== [], fn ($query) => $query->whereNotIn('ic.contractor_id', $internalContractorIds))
            ->when($channel === OrderChannel::EOS, fn ($query) => $query->whereIn('ic.contractor_id', $jxContractorIds))
            ->when($category2Id > 0, fn ($query) => $query->where('items.item_category2_id', $category2Id));

        if ($itemSearch !== '') {
            $query->where(function ($query) use ($itemSearch): void {
                $query
                    ->where('items.code', 'like', "%{$itemSearch}%")
                    ->orWhere('items.name', 'like', "%{$itemSearch}%")
                    ->orWhereExists(function ($query) use ($itemSearch): void {
                        $query
                            ->selectRaw('1')
                            ->from('item_search_information as isi')
                            ->whereColumn('isi.item_id', 'items.id')
                            ->where('isi.search_string', 'like', "%{$itemSearch}%");
                    });
            });
        }

        if ($contractorSearch !== '') {
            $query->where(function ($query) use ($contractorSearch): void {
                $query
                    ->where('contractors.code', 'like', "{$contractorSearch}%")
                    ->orWhere('contractors.name', 'like', "%{$contractorSearch}%");
            });
        }

        return $query
            ->selectRaw('
                ic.item_id,
                ic.contractor_id,
                ic.supplier_id,
                COALESCE(ic.purchase_unit, 1) as purchase_unit,
                COALESCE(ic.safety_stock, 0) as safety_stock,
                items.code as item_code,
                items.name as item_name,
                items.packaging as item_packaging,
                COALESCE(items.capacity_case, 1) as capacity_case,
                contractors.code as contractor_code,
                contractors.name as contractor_name,
                supplier_partners.name as supplier_name
            ')
            ->orderBy('contractors.code')
            ->orderBy('items.code')
            ->limit($limit)
            ->get()
            ->map(function ($row) use ($warehouseId, $incomingWarehouseId): array {
                $itemId = (int) $row->item_id;
                $contractorId = (int) $row->contractor_id;
                $supplierId = (int) ($row->supplier_id ?? 0);

                return [
                    'key' => sha1("{$warehouseId}:{$itemId}:{$contractorId}:{$supplierId}:search"),
                    'warehouse_id' => $warehouseId,
                    'item_id' => $itemId,
                    'contractor_id' => $contractorId,
                    'supplier_id' => $supplierId,
                    'item_code' => (string) $row->item_code,
                    'item_name' => (string) $row->item_name,
                    'item_packaging' => (string) ($row->item_packaging ?? ''),
                    'capacity_case' => max(1, (int) ($row->capacity_case ?? 1)),
                    'contractor_code' => (string) $row->contractor_code,
                    'contractor_name' => (string) $row->contractor_name,
                    'supplier_name' => (string) ($row->supplier_name ?? '-'),
                    'purchase_unit' => max(1, (int) ($row->purchase_unit ?? 1)),
                    'safety_stock' => (int) ($row->safety_stock ?? 0),
                    'search_code' => $this->searchCodeForItem($itemId),
                    'ordering_code' => $this->orderingCodeForItem($itemId),
                    'default_expected_arrival_date' => $this->defaultExpectedArrivalDate($warehouseId, $contractorId),
                    'current_stock' => $this->availableStock($warehouseId, $itemId),
                    'incoming_quantity' => $this->incomingQuantity($incomingWarehouseId, $itemId),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{sales_start_date: string, sales_end_date: string, category2_id?: int|null, contractor_search?: string|null, limit?: int|null}  $conditions
     * @return array{rows: array<int, array<string, mixed>>, error: string|null}
     */
    public function salesHistoryCandidates(int $warehouseId, OrderChannel $channel, array $conditions): array
    {
        try {
            $startDate = Carbon::parse($conditions['sales_start_date'] ?? now()->subDays(2)->toDateString())->toDateString();
            $endDate = Carbon::parse($conditions['sales_end_date'] ?? now()->toDateString())->toDateString();
        } catch (\Throwable) {
            return ['rows' => [], 'error' => '販売実績期間を正しく指定してください。'];
        }

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $warehouseIds = $this->salesHistoryWarehouseIds($warehouseId);
        if ($warehouseIds === []) {
            return ['rows' => [], 'error' => '販売履歴の対象倉庫がありません。'];
        }

        $jxContractorIds = $this->jxContractorIds();
        if ($channel === OrderChannel::EOS && $jxContractorIds === []) {
            return ['rows' => [], 'error' => 'EOS発注対象の仕入先がありません。'];
        }

        $days = max(1, Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1);
        $category2Id = (int) ($conditions['category2_id'] ?? 0);
        $contractorSearch = trim(mb_convert_kana((string) ($conditions['contractor_search'] ?? ''), 'as'));
        $limit = max(10, min(300, (int) ($conditions['limit'] ?? 100)));
        $internalContractorIds = $this->internalContractorIds();

        $salesSubquery = DB::connection('sakemaru')
            ->table('stats_item_warehouse_daily_sales')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereBetween('business_date', [$startDate, $endDate])
            ->selectRaw('
                item_id,
                SUM(shipped_piece_qty) as sales_qty,
                SUM(sales_piece_qty) as sales_piece_qty,
                SUM(return_piece_qty) as return_piece_qty,
                SUM(transfer_piece_qty) as transfer_piece_qty
            ')
            ->groupBy('item_id')
            ->havingRaw('SUM(shipped_piece_qty) > 0');

        $stockSubquery = DB::connection('sakemaru')
            ->query()
            ->fromSub(
                DB::connection('sakemaru')
                    ->table('wms_v_stock_available')
                    ->where('warehouse_id', $warehouseId)
                    ->selectRaw('DISTINCT warehouse_id, item_id, real_stock_id, available_for_wms as stock_qty'),
                'dedup_stocks'
            )
            ->selectRaw('item_id, SUM(stock_qty) as effective_stock')
            ->groupBy('item_id');

        $incomingSubquery = DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', ['PENDING', 'PARTIAL'])
            ->selectRaw('item_id, SUM(expected_quantity - received_quantity) as incoming_qty')
            ->groupBy('item_id');

        $targetItemContractorsSubquery = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $warehouseId)
            ->when($internalContractorIds !== [], fn ($query) => $query->whereNotIn('contractor_id', $internalContractorIds))
            ->when($channel === OrderChannel::EOS, fn ($query) => $query->whereIn('contractor_id', $jxContractorIds))
            ->selectRaw('
                warehouse_id,
                item_id,
                contractor_id,
                MIN(supplier_id) as supplier_id,
                MAX(COALESCE(purchase_unit, 1)) as purchase_unit
            ')
            ->groupBy('warehouse_id', 'item_id', 'contractor_id');

        $query = DB::connection('sakemaru')
            ->query()
            ->fromSub($targetItemContractorsSubquery, 'item_contractors')
            ->join('items', 'item_contractors.item_id', '=', 'items.id')
            ->leftJoin('item_categories as item_category2', 'item_category2.id', '=', 'items.item_category2_id')
            ->join('contractors', 'item_contractors.contractor_id', '=', 'contractors.id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'item_contractors.supplier_id')
            ->leftJoin('partners as supplier_partners', 'supplier_partners.id', '=', 'suppliers.partner_id')
            ->joinSub($salesSubquery, 'sales', function ($join): void {
                $join->on('sales.item_id', '=', 'item_contractors.item_id');
            })
            ->leftJoinSub($stockSubquery, 'stocks', function ($join): void {
                $join->on('stocks.item_id', '=', 'item_contractors.item_id');
            })
            ->leftJoinSub($incomingSubquery, 'incoming', function ($join): void {
                $join->on('incoming.item_id', '=', 'item_contractors.item_id');
            })
            ->where('items.end_of_sale_type', 'NORMAL')
            ->where('items.is_ended', false)
            ->where(fn ($query) => $query->whereNull('items.start_of_sale_date')->orWhere('items.start_of_sale_date', '<=', now()->toDateString()))
            ->where(fn ($query) => $query->whereNull('items.end_of_sale_date')->orWhere('items.end_of_sale_date', '>', now()->toDateString()))
            ->where('contractors.is_auto_change_order', true)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('item_search_information as isi')
                    ->whereColumn('isi.item_id', 'item_contractors.item_id')
                    ->where('isi.is_used_for_ordering', true)
                    ->where('isi.is_active', true)
                    ->whereRaw("isi.search_string REGEXP '[1-9]'");
            })
            ->when($category2Id > 0, fn ($query) => $query->where('items.item_category2_id', $category2Id));

        if ($contractorSearch !== '') {
            $query->where(function ($query) use ($contractorSearch): void {
                $query
                    ->where('contractors.code', 'like', "{$contractorSearch}%")
                    ->orWhere('contractors.name', 'like', "%{$contractorSearch}%");
            });
        }

        $rows = $query
            ->selectRaw('
                item_contractors.warehouse_id as warehouse_id,
                item_contractors.item_id as item_id,
                item_contractors.contractor_id as contractor_id,
                item_contractors.supplier_id as supplier_id,
                item_category2.code as item_category2_code,
                items.code as item_code,
                items.name as item_name,
                items.packaging as item_packaging,
                items.capacity_case as capacity_case,
                contractors.code as contractor_code,
                contractors.name as contractor_name,
                supplier_partners.name as supplier_name,
                sales.sales_qty as sales_qty,
                sales.sales_piece_qty as sales_piece_qty,
                sales.return_piece_qty as return_piece_qty,
                sales.transfer_piece_qty as transfer_piece_qty,
                COALESCE(stocks.effective_stock, 0) as effective_stock,
                COALESCE(incoming.incoming_qty, 0) as incoming_qty,
                GREATEST(sales.sales_qty - (COALESCE(stocks.effective_stock, 0) + COALESCE(incoming.incoming_qty, 0)), 0) as shortage_qty,
                COALESCE(item_contractors.purchase_unit, 1) as purchase_unit
            ')
            ->orderBy('contractors.code')
            ->orderBy('supplier_partners.code')
            ->orderBy('items.code')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'key' => sha1("{$warehouseId}:{$row->item_id}:{$row->contractor_id}:{$row->supplier_id}:sales"),
                'warehouse_id' => (int) $row->warehouse_id,
                'item_id' => (int) $row->item_id,
                'contractor_id' => (int) $row->contractor_id,
                'supplier_id' => (int) ($row->supplier_id ?? 0),
                'item_category2_code' => (string) ($row->item_category2_code ?? ''),
                'item_code' => (string) $row->item_code,
                'item_name' => (string) $row->item_name,
                'item_packaging' => (string) ($row->item_packaging ?? ''),
                'capacity_case' => max(1, (int) ($row->capacity_case ?? 1)),
                'contractor_code' => (string) $row->contractor_code,
                'contractor_name' => (string) $row->contractor_name,
                'supplier_name' => (string) ($row->supplier_name ?? '-'),
                'sales_qty' => (int) $row->sales_qty,
                'sales_piece_qty' => (int) $row->sales_piece_qty,
                'return_piece_qty' => (int) $row->return_piece_qty,
                'transfer_piece_qty' => (int) $row->transfer_piece_qty,
                'daily_avg_qty' => round(((int) $row->sales_qty) / $days, 2),
                'effective_stock' => (int) $row->effective_stock,
                'incoming_quantity' => (int) $row->incoming_qty,
                'purchase_unit' => max(1, (int) $row->purchase_unit),
                'order_piece_qty' => (int) $row->shortage_qty,
                'search_code' => $this->searchCodeForItem((int) $row->item_id),
                'ordering_code' => $this->orderingCodeForItem((int) $row->item_id),
                'default_expected_arrival_date' => $this->defaultExpectedArrivalDate($warehouseId, (int) $row->contractor_id),
            ])
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'error' => $rows === [] ? '現在の条件に該当する候補がありません。' : null,
        ];
    }

    public function incomingWarehouseId(int $warehouseId): int
    {
        $warehouse = Warehouse::find($warehouseId);

        return ($warehouse?->is_virtual && $warehouse->stock_warehouse_id)
            ? (int) $warehouse->stock_warehouse_id
            : $warehouseId;
    }

    public function searchCodeForItem(int $itemId): ?string
    {
        return DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->whereRaw("search_string REGEXP '[1-9]'")
            ->orderBy('id')
            ->value('search_string');
    }

    public function orderingCodeForItem(int $itemId): ?string
    {
        $code = $this->searchCodeForItem($itemId);

        return filled($code) ? str_pad((string) $code, 13, '0', STR_PAD_LEFT) : null;
    }

    public function availableStock(int $warehouseId, int $itemId): int
    {
        return (int) DB::connection('sakemaru')
            ->table('wms_v_stock_available')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->sum('available_quantity');
    }

    public function incomingQuantity(int $warehouseId, int $itemId): int
    {
        return (int) (DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('status', ['PENDING', 'PARTIAL'])
            ->selectRaw('SUM(expected_quantity - received_quantity) as total_incoming')
            ->value('total_incoming') ?? 0);
    }

    public function defaultExpectedArrivalDate(int $warehouseId, int $contractorId): string
    {
        $cacheKey = "{$warehouseId}:{$contractorId}";

        if (isset($this->defaultExpectedArrivalDateCache[$cacheKey])) {
            return $this->defaultExpectedArrivalDateCache[$cacheKey];
        }

        $arrivalDate = app(JxOrderArrivalDateAdjustmentService::class)
            ->earliestArrivalDate($contractorId, $warehouseId);

        return $this->defaultExpectedArrivalDateCache[$cacheKey] = ($arrivalDate ?? Carbon::parse(ClientSetting::freshSystemDateYMD('order_registration:default_arrival'))->addDay())
            ->toDateString();
    }

    /**
     * @return array<int>
     */
    private function internalContractorIds(): array
    {
        return WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::INTERNAL->value)
            ->pluck('contractor_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int>
     */
    private function salesHistoryWarehouseIds(int $warehouseId): array
    {
        $enabledWarehouseIds = WmsWarehouseAutoOrderSetting::enabled()
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_intersect($enabledWarehouseIds, [$warehouseId]));
    }
}
