<?php

namespace App\Filament\Widgets;

use App\Enums\QuantityType;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortageAllocation;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class DashboardShortageAllocationsWidget extends Widget
{
    protected string $view = 'filament.widgets.dashboard-shortage-allocations';

    protected int|string|array $columnSpan = 'full';

    public string $activeWarehouse = 'all';

    public array $warehouses = [];

    public array $allocations = [];

    public ?int $defaultWarehouseId = null;

    public string $filterDate = '';

    public function mount(): void
    {
        $this->filterDate = ClientSetting::systemDateYMD();
        $this->defaultWarehouseId = auth()->user()?->getSelectedWarehouseId();

        $this->loadWarehouses();
        $this->loadAllocations();
    }

    #[On('filter-date-updated')]
    public function onFilterDateUpdated(string $filterDate): void
    {
        $this->filterDate = $filterDate;
        $this->loadWarehouses();
        $this->loadAllocations();
    }

    protected function loadWarehouses(): void
    {
        $warehouseIds = WmsShortageAllocation::query()
            ->where('shipment_date', $this->filterDate)
            ->distinct()
            ->pluck('target_warehouse_id')
            ->filter()
            ->toArray();

        // デフォルト倉庫がデータになくてもタブに含める
        if ($this->defaultWarehouseId && ! in_array($this->defaultWarehouseId, $warehouseIds)) {
            $warehouseIds[] = $this->defaultWarehouseId;
        }

        $warehouseModels = Warehouse::whereIn('id', $warehouseIds)
            ->where('is_virtual', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $this->warehouses = $warehouseModels->map(fn ($w) => [
            'id' => $w->id,
            'name' => $w->name,
        ])->toArray();

        // デフォルト倉庫を優先選択、なければ最初の倉庫
        if ($this->defaultWarehouseId && $warehouseModels->firstWhere('id', $this->defaultWarehouseId)) {
            $this->activeWarehouse = (string) $this->defaultWarehouseId;
        } elseif (! empty($this->warehouses)) {
            $this->activeWarehouse = (string) $this->warehouses[0]['id'];
        }
    }

    public function setWarehouse(string $warehouseId): void
    {
        $this->activeWarehouse = $warehouseId;
        $this->loadAllocations();
    }

    public function loadAllocations(): void
    {
        $query = WmsShortageAllocation::query()
            ->where('shipment_date', $this->filterDate)
            ->with([
                'shortage.warehouse',
                'shortage.item.piece_jan_code_information',
                'shortage.trade.partner',
                'targetWarehouse',
                'deliveryCourse',
                'finishedUser',
            ]);

        if ($this->activeWarehouse && $this->activeWarehouse !== 'all') {
            $query->where('target_warehouse_id', (int) $this->activeWarehouse);
        }

        $query->orderByRaw("CASE WHEN is_finished = 0 THEN 0 ELSE 1 END ASC")
            ->orderBy('created_at', 'desc');

        $records = $query->get();

        $this->allocations = $records->map(function (WmsShortageAllocation $record) {
            $statusLabel = match ($record->status) {
                'PENDING' => '承認待ち',
                'RESERVED' => '引当済み',
                'PICKING' => 'ピッキング中',
                'FULFILLED' => '完了',
                'SHORTAGE' => '代理側欠品',
                default => $record->status,
            };
            $statusColor = match ($record->status) {
                'PENDING' => 'gray',
                'RESERVED' => 'blue',
                'PICKING' => 'yellow',
                'FULFILLED' => 'green',
                'SHORTAGE' => 'red',
                default => 'gray',
            };

            $item = $record->shortage?->item;
            $janCode = $item ? $item->posCode() : '-';

            return [
                'id' => $record->id,
                'target_warehouse' => $record->targetWarehouse?->name ?? '-',
                'shipment_date' => $record->shipment_date?->format('Y-m-d') ?? '-',
                'delivery_course' => $record->deliveryCourse?->name ?? '-',
                'item_code' => $item?->code ?? '-',
                'jan_code' => $janCode,
                'item_name' => $item?->name ?? '-',
                'partner_name' => $record->shortage?->trade?->partner?->name ?? '-',
                'qty_type' => QuantityType::tryFrom($record->assign_qty_type)?->name() ?? $record->assign_qty_type ?? '-',
                'assign_qty' => $record->assign_qty,
                'picked_qty' => $record->picked_qty,
                'remaining_qty' => $record->remaining_qty,
                'status_label' => $statusLabel,
                'status_color' => $statusColor,
                'is_finished' => $record->is_finished,
            ];
        })->toArray();
    }
}
