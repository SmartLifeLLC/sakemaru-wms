<?php

namespace App\Filament\Resources\WmsSundryInventoryCount\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsSundryInventoryCount\Tables\WmsSundryInventoryCountTable;
use App\Filament\Resources\WmsSundryInventoryCountResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsSundryInventoryCount;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListWmsSundryInventoryCounts extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsSundryInventoryCountResource::class;

    protected ?Collection $cachedWarehouses = null;

    protected function getHeaderActions(): array
    {
        return [
            WmsSundryInventoryCountTable::getCreateAction(),
        ];
    }

    public function getPresetViews(): array
    {
        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $warehouses = $this->getWarehousesForPresetViews($selectedWarehouseId);

        $selectedWarehouse = $selectedWarehouseId
            ? $warehouses->firstWhere('id', $selectedWarehouseId)
            : null;

        if ($selectedWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $selectedWarehouseId))
                    ->favorite()
                    ->label($selectedWarehouse->name)
                    ->default(),
                'all' => PresetView::make()
                    ->favorite()
                    ->label('全て'),
            ];
        } else {
            $views = [
                'default' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        foreach ($warehouses as $warehouse) {
            if ($selectedWarehouse && $warehouse->id === $selectedWarehouse->id) {
                continue;
            }

            $views["wh_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }

    protected function getWarehousesForPresetViews(?int $selectedWarehouseId): Collection
    {
        if ($this->cachedWarehouses !== null) {
            return $this->cachedWarehouses;
        }

        $warehouseIds = WmsSundryInventoryCount::query()
            ->distinct()
            ->pluck('warehouse_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($selectedWarehouseId) {
            $warehouseIds->push($selectedWarehouseId);
        }

        $this->cachedWarehouses = Warehouse::query()
            ->whereIn('id', $warehouseIds->unique()->all())
            ->orderBy('code')
            ->get(['id', 'name']);

        return $this->cachedWarehouses;
    }
}
