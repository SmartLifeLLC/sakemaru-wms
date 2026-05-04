<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Filament\Support\AdminPage;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WmsStockDifferenceAnalysis extends AdminPage
{
    protected static string $permissionResource = 'wms-stock-snapshot';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $slug = 'wms-stock-difference-analysis';

    protected string $view = 'filament.pages.wms-stock-difference-analysis';

    public string $itemSearch = '';

    public ?int $selectedItemId = null;

    public ?string $selectedWarehouseId = null;

    public ?string $fromSnapshot = null;

    public ?string $toSnapshot = null;

    public bool $hasSearched = false;

    public function mount(): void
    {
        $snapshots = $this->snapshotOptions();

        $this->toSnapshot = $snapshots->keys()->first();
        $this->fromSnapshot = $snapshots->keys()->skip(1)->first() ?? $this->toSnapshot;
    }

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_STOCK_DIFFERENCE_ANALYSIS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_STOCK_DIFFERENCE_ANALYSIS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_STOCK_DIFFERENCE_ANALYSIS->sort();
    }

    public function getTitle(): string
    {
        return EMenu::WMS_STOCK_DIFFERENCE_ANALYSIS->label();
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function selectItem(int $itemId): void
    {
        $this->selectedItemId = $itemId;
        $this->itemSearch = '';
        $this->hasSearched = false;
    }

    public function clearItem(): void
    {
        $this->selectedItemId = null;
        $this->hasSearched = false;
    }

    public function swapSnapshots(): void
    {
        [$this->fromSnapshot, $this->toSnapshot] = [$this->toSnapshot, $this->fromSnapshot];
        $this->hasSearched = false;
    }

    public function search(): void
    {
        if ($this->selectedItemId === null) {
            $this->selectedItemId = $this->findItemIdForSearch();
        }

        $this->hasSearched = true;
    }

    public function updatedItemSearch(): void
    {
        $this->selectedItemId = null;
        $this->hasSearched = false;
    }

    public function updatedSelectedWarehouseId(): void
    {
        $this->hasSearched = false;
    }

    public function updatedFromSnapshot(): void
    {
        $this->hasSearched = false;
    }

    public function updatedToSnapshot(): void
    {
        $this->hasSearched = false;
    }

    public function snapshotOptions(): Collection
    {
        return DB::connection('sakemaru')
            ->table('wms_stock_snapshots')
            ->select('snapshot_date', 'snapshot_time')
            ->distinct()
            ->orderByDesc('snapshot_date')
            ->orderByRaw("FIELD(snapshot_time, 'evening', 'morning')")
            ->limit(1000)
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $this->snapshotKey((string) $row->snapshot_date, (string) $row->snapshot_time) => $this->snapshotLabel((string) $row->snapshot_date, (string) $row->snapshot_time),
            ]);
    }

    public function warehouseOptions(): Collection
    {
        return DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_active', true)
            ->where('is_virtual', false)
            ->orderBy('code')
            ->limit(200)
            ->get(['id', 'code', 'name']);
    }

    public function itemCandidates(): Collection
    {
        $search = trim($this->itemSearch);

        if ($search === '' || mb_strlen($search) < 2) {
            return collect();
        }

        return DB::connection('sakemaru')
            ->table('items as i')
            ->join('wms_stock_snapshots as s', 's.item_id', '=', 'i.id')
            ->where(function ($query) use ($search): void {
                $query->where('i.code', 'like', "%{$search}%")
                    ->orWhere('i.name', 'like', "%{$search}%")
                    ->orWhere('i.kana', 'like', "%{$search}%");
            })
            ->distinct()
            ->orderByRaw('i.code = ? DESC', [$search])
            ->orderBy('i.code')
            ->limit(20)
            ->get(['i.id', 'i.code', 'i.name']);
    }

    public function selectedItem(): ?object
    {
        if ($this->selectedItemId === null) {
            return null;
        }

        return DB::connection('sakemaru')
            ->table('items')
            ->where('id', $this->selectedItemId)
            ->first(['id', 'code', 'name']);
    }

    public function comparison(): array
    {
        if ($this->selectedItemId === null || $this->fromSnapshot === null || $this->toSnapshot === null) {
            return $this->emptyComparison();
        }

        [$fromDate, $fromTime] = $this->parseSnapshotKey($this->fromSnapshot);
        [$toDate, $toTime] = $this->parseSnapshotKey($this->toSnapshot);

        $fromRows = $this->summaryRows($fromDate, $fromTime)->keyBy('warehouse_id');
        $toRows = $this->summaryRows($toDate, $toTime)->keyBy('warehouse_id');
        $warehouseIds = $fromRows->keys()->merge($toRows->keys())->unique()->sort()->values();

        $rows = $warehouseIds->map(function (int $warehouseId) use ($fromRows, $toRows): array {
            $from = $fromRows->get($warehouseId);
            $to = $toRows->get($warehouseId);

            return [
                'warehouse_id' => $warehouseId,
                'warehouse_code' => $to->warehouse_code ?? $from->warehouse_code ?? '-',
                'warehouse_name' => $to->warehouse_name ?? $from->warehouse_name ?? '-',
                'from_current' => (int) ($from->current_quantity ?? 0),
                'to_current' => (int) ($to->current_quantity ?? 0),
                'diff_current' => (int) ($to->current_quantity ?? 0) - (int) ($from->current_quantity ?? 0),
                'from_reserved' => (int) ($from->reserved_quantity ?? 0),
                'to_reserved' => (int) ($to->reserved_quantity ?? 0),
                'diff_reserved' => (int) ($to->reserved_quantity ?? 0) - (int) ($from->reserved_quantity ?? 0),
                'from_available' => (int) ($from->available_quantity ?? 0),
                'to_available' => (int) ($to->available_quantity ?? 0),
                'diff_available' => (int) ($to->available_quantity ?? 0) - (int) ($from->available_quantity ?? 0),
                'from_incoming' => (int) ($from->incoming_quantity ?? 0),
                'to_incoming' => (int) ($to->incoming_quantity ?? 0),
                'diff_incoming' => (int) ($to->incoming_quantity ?? 0) - (int) ($from->incoming_quantity ?? 0),
            ];
        })->values();

        return [
            'rows' => $rows,
            'totals' => [
                'from_current' => $rows->sum('from_current'),
                'to_current' => $rows->sum('to_current'),
                'diff_current' => $rows->sum('diff_current'),
                'from_reserved' => $rows->sum('from_reserved'),
                'to_reserved' => $rows->sum('to_reserved'),
                'diff_reserved' => $rows->sum('diff_reserved'),
                'from_available' => $rows->sum('from_available'),
                'to_available' => $rows->sum('to_available'),
                'diff_available' => $rows->sum('diff_available'),
                'from_incoming' => $rows->sum('from_incoming'),
                'to_incoming' => $rows->sum('to_incoming'),
                'diff_incoming' => $rows->sum('diff_incoming'),
            ],
        ];
    }

    public function timeline(): Collection
    {
        if ($this->selectedItemId === null || $this->fromSnapshot === null || $this->toSnapshot === null) {
            return collect();
        }

        $keys = $this->orderedSnapshotKeysBetween($this->fromSnapshot, $this->toSnapshot);

        if ($keys->isEmpty()) {
            return collect();
        }

        $rows = DB::connection('sakemaru')
            ->table('wms_stock_snapshots')
            ->where('item_id', $this->selectedItemId)
            ->when($this->warehouseId() !== null, fn ($query) => $query->where('warehouse_id', $this->warehouseId()))
            ->select('snapshot_date', 'snapshot_time')
            ->selectRaw('SUM(current_quantity) as current_quantity')
            ->selectRaw('SUM(reserved_quantity) as reserved_quantity')
            ->selectRaw('SUM(available_quantity) as available_quantity')
            ->selectRaw('SUM(incoming_quantity) as incoming_quantity')
            ->groupBy('snapshot_date', 'snapshot_time')
            ->get()
            ->keyBy(fn (object $row): string => $this->snapshotKey((string) $row->snapshot_date, (string) $row->snapshot_time));

        $previous = null;

        return $keys->map(function (string $key) use ($rows, &$previous): array {
            [$date, $time] = $this->parseSnapshotKey($key);
            $row = $rows->get($key);
            $current = (int) ($row->current_quantity ?? 0);

            $result = [
                'key' => $key,
                'label' => $this->snapshotLabel($date, $time),
                'current_quantity' => $current,
                'reserved_quantity' => (int) ($row->reserved_quantity ?? 0),
                'available_quantity' => (int) ($row->available_quantity ?? 0),
                'incoming_quantity' => (int) ($row->incoming_quantity ?? 0),
                'diff_from_previous' => $previous === null ? null : $current - $previous,
            ];

            $previous = $current;

            return $result;
        });
    }

    public function lotDifferences(): Collection
    {
        if ($this->selectedItemId === null || $this->fromSnapshot === null || $this->toSnapshot === null) {
            return collect();
        }

        [$fromDate, $fromTime] = $this->parseSnapshotKey($this->fromSnapshot);
        [$toDate, $toTime] = $this->parseSnapshotKey($this->toSnapshot);

        $fromRows = $this->lotRows($fromDate, $fromTime)->keyBy('comparison_key');
        $toRows = $this->lotRows($toDate, $toTime)->keyBy('comparison_key');

        return $fromRows->keys()
            ->merge($toRows->keys())
            ->unique()
            ->map(function (string $key) use ($fromRows, $toRows): array {
                $from = $fromRows->get($key);
                $to = $toRows->get($key);
                $fromCurrent = (int) ($from->current_quantity ?? 0);
                $toCurrent = (int) ($to->current_quantity ?? 0);
                $fromReserved = (int) ($from->reserved_quantity ?? 0);
                $toReserved = (int) ($to->reserved_quantity ?? 0);

                return [
                    'warehouse_code' => $to->warehouse_code ?? $from->warehouse_code ?? '-',
                    'warehouse_name' => $to->warehouse_name ?? $from->warehouse_name ?? '-',
                    'location_name' => $to->location_name ?? $from->location_name ?? '-',
                    'expiration_date' => $to->expiration_date ?? $from->expiration_date ?? null,
                    'lot_id' => $to->lot_id ?? $from->lot_id ?? null,
                    'from_current' => $fromCurrent,
                    'to_current' => $toCurrent,
                    'diff_current' => $toCurrent - $fromCurrent,
                    'from_reserved' => $fromReserved,
                    'to_reserved' => $toReserved,
                    'diff_reserved' => $toReserved - $fromReserved,
                ];
            })
            ->filter(fn (array $row): bool => $row['diff_current'] !== 0 || $row['diff_reserved'] !== 0)
            ->sortByDesc(fn (array $row): int => abs($row['diff_current']) + abs($row['diff_reserved']))
            ->take(200)
            ->values();
    }

    private function summaryRows(string $snapshotDate, string $snapshotTime): Collection
    {
        return DB::connection('sakemaru')
            ->table('wms_stock_snapshots as s')
            ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('s.snapshot_date', $snapshotDate)
            ->where('s.snapshot_time', $snapshotTime)
            ->where('s.item_id', $this->selectedItemId)
            ->when($this->warehouseId() !== null, fn ($query) => $query->where('s.warehouse_id', $this->warehouseId()))
            ->get([
                's.warehouse_id',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                's.current_quantity',
                's.reserved_quantity',
                's.available_quantity',
                's.incoming_quantity',
            ]);
    }

    private function lotRows(string $snapshotDate, string $snapshotTime): Collection
    {
        return DB::connection('sakemaru')
            ->table('wms_stock_snapshot_lots as l')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->leftJoin('locations as loc', 'loc.id', '=', 'l.location_id')
            ->where('l.snapshot_date', $snapshotDate)
            ->where('l.snapshot_time', $snapshotTime)
            ->where('l.item_id', $this->selectedItemId)
            ->when($this->warehouseId() !== null, fn ($query) => $query->where('l.warehouse_id', $this->warehouseId()))
            ->select('l.warehouse_id', 'w.code as warehouse_code', 'w.name as warehouse_name')
            ->selectRaw("CONCAT_WS('-', loc.code1, loc.code2, loc.code3) as location_name")
            ->selectRaw('COALESCE(l.location_id, 0) as location_key')
            ->selectRaw("COALESCE(CAST(l.expiration_date AS CHAR), '') as expiration_date")
            ->selectRaw('l.lot_id')
            ->selectRaw('SUM(l.current_quantity) as current_quantity')
            ->selectRaw('SUM(l.reserved_quantity) as reserved_quantity')
            ->selectRaw("CONCAT(l.warehouse_id, '|', COALESCE(l.location_id, 0), '|', COALESCE(CAST(l.expiration_date AS CHAR), ''), '|', l.lot_id) as comparison_key")
            ->groupBy('l.warehouse_id', 'w.code', 'w.name', 'l.location_id', 'loc.code1', 'loc.code2', 'loc.code3', 'l.expiration_date', 'l.lot_id')
            ->get();
    }

    private function orderedSnapshotKeysBetween(string $fromKey, string $toKey): Collection
    {
        $keys = $this->snapshotOptions()->keys()->reverse()->values();
        $fromIndex = $keys->search($fromKey);
        $toIndex = $keys->search($toKey);

        if ($fromIndex === false || $toIndex === false) {
            return collect();
        }

        if ($fromIndex > $toIndex) {
            [$fromIndex, $toIndex] = [$toIndex, $fromIndex];
        }

        return $keys->slice($fromIndex, $toIndex - $fromIndex + 1)->values();
    }

    private function warehouseId(): ?int
    {
        return filled($this->selectedWarehouseId) ? (int) $this->selectedWarehouseId : null;
    }

    private function findItemIdForSearch(): ?int
    {
        $search = trim($this->itemSearch);

        if ($search === '') {
            return null;
        }

        $item = DB::connection('sakemaru')
            ->table('items as i')
            ->join('wms_stock_snapshots as s', 's.item_id', '=', 'i.id')
            ->where(function ($query) use ($search): void {
                $query->where('i.code', 'like', "%{$search}%")
                    ->orWhere('i.name', 'like', "%{$search}%")
                    ->orWhere('i.kana', 'like', "%{$search}%");
            })
            ->select('i.id', 'i.code')
            ->groupBy('i.id', 'i.code')
            ->orderByRaw('i.code = ? DESC', [$search])
            ->orderBy('i.code')
            ->first();

        return $item === null ? null : (int) $item->id;
    }

    private function snapshotKey(string $date, string $time): string
    {
        return "{$date}|{$time}";
    }

    private function parseSnapshotKey(string $key): array
    {
        $parts = explode('|', $key);

        return [$parts[0] ?? '', $parts[1] ?? 'morning'];
    }

    private function snapshotLabel(string $date, string $time): string
    {
        return $date.' '.($time === 'morning' ? '朝' : '夕');
    }

    private function emptyComparison(): array
    {
        return [
            'rows' => collect(),
            'totals' => [
                'from_current' => 0,
                'to_current' => 0,
                'diff_current' => 0,
                'from_reserved' => 0,
                'to_reserved' => 0,
                'diff_reserved' => 0,
                'from_available' => 0,
                'to_available' => 0,
                'diff_available' => 0,
                'from_incoming' => 0,
                'to_incoming' => 0,
                'diff_incoming' => 0,
            ],
        ];
    }
}
