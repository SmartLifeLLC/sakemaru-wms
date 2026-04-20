<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Models\Sakemaru\Warehouse;
use Archilex\AdvancedTables\Components\PresetView;
use Illuminate\Database\Eloquent\Builder;

class ListWarehouseFinishedWmsShortageAllocations extends ListFinishedWmsShortageAllocations
{
    protected static ?string $title = '倉庫別横持ち出荷完了';

    protected function resolveWarehouseIdFromPresetView(): ?int
    {
        return auth()->user()?->getSelectedWarehouseId();
    }

    public function getPresetViews(): array
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();
        if (! $warehouseId) {
            return [];
        }

        $warehouse = Warehouse::find($warehouseId);
        if (! $warehouse) {
            return [];
        }

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('target_warehouse_id', $warehouseId))
                ->favorite()
                ->label($warehouse->name)
                ->default(),
        ];
    }
}
