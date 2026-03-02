<?php

namespace App\Filament\Resources\RealStocks\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\RealStocks\RealStockResource;
use App\Models\Sakemaru\Warehouse;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListRealStocks extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = RealStockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        $warehouses = cache()->remember('all_warehouses_for_tabs_'.auth()->id(), 60, function () {
            return Warehouse::query()->orderBy('code')->get(['id', 'code', 'name']);
        });

        $hasDefaultWarehouse = $userDefaultWarehouseId
            && $warehouses->contains('id', $userDefaultWarehouseId);

        $views = [
            'default' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse),
        ];

        foreach ($warehouses as $warehouse) {
            $isDefault = $hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId;
            $views["wh_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name)
                ->default($isDefault);
        }

        return $views;
    }
}
