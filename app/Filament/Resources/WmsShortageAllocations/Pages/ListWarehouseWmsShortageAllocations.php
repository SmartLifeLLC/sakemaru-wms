<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Models\Sakemaru\Warehouse;
use Archilex\AdvancedTables\Components\PresetView;
use Illuminate\Database\Eloquent\Builder;

class ListWarehouseWmsShortageAllocations extends ListWmsShortageAllocations
{
    protected static ?string $title = '倉庫別横持ち出荷依頼';

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
                ->defaultFilters([
                    'shipment_date' => ['shipment_date' => \App\Models\Sakemaru\ClientSetting::systemDateYMD()],
                ])
                ->favorite()
                ->label($warehouse->name)
                ->default(),
        ];
    }
}
