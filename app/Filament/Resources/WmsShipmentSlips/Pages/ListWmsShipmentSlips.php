<?php

namespace App\Filament\Resources\WmsShipmentSlips\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsShipmentSlips\Tables\WmsShipmentSlipsTable;
use App\Filament\Resources\WmsShipmentSlips\WmsShipmentSlipsResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsPickingTask;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

class ListWmsShipmentSlips extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsShipmentSlipsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected ?array $presetViewWarehouseData = null;

    protected function getWarehouseDataForPresetViews(): array
    {
        if ($this->presetViewWarehouseData !== null) {
            return $this->presetViewWarehouseData;
        }

        $systemDate = ClientSetting::systemDateYMD();
        $cacheKey = 'shipment_slips_warehouses_'.auth()->id();
        $this->presetViewWarehouseData = cache()->remember($cacheKey, 30, function () use ($systemDate) {
            $warehouseIds = WmsPickingTask::where('shipment_date', $systemDate)
                ->distinct()
                ->pluck('warehouse_id')
                ->toArray();

            $warehouses = Warehouse::whereIn('id', $warehouseIds)
                ->where('is_virtual', false)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);

            return [
                'ids' => $warehouseIds,
                'warehouses' => $warehouses,
            ];
        });

        return $this->presetViewWarehouseData;
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;
        $systemDate = ClientSetting::systemDateYMD();

        $warehouseData = $this->getWarehouseDataForPresetViews();
        $warehouses = $warehouseData['warehouses'];

        $defaultWarehouse = $userDefaultWarehouseId
            ? $warehouses->firstWhere('id', $userDefaultWarehouseId)
            : null;

        $defaultFilterData = [
            'shipment_date' => ['shipment_date' => $systemDate],
        ];

        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
                    ->defaultFilters($defaultFilterData)
                    ->favorite()
                    ->label($defaultWarehouse->name)
                    ->default(),
            ];
        } else {
            $views = [
                'default' => PresetView::make()
                    ->defaultFilters($defaultFilterData)
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        $views['all'] = PresetView::make()
            ->defaultFilters($defaultFilterData)
            ->label('全て')
            ->favorite();

        $views['past'] = PresetView::make()
            ->modifyQueryUsing(fn (Builder $query) => $query->where('shipment_date', '<', $systemDate))
            ->label('過去')
            ->favorite();

        foreach ($warehouses as $warehouse) {
            if ($defaultWarehouse && $warehouse->id === $defaultWarehouse->id) {
                continue;
            }
            $views["wh_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $paginator = parent::paginateTableQuery($query);

        // ページネーション結果のレコードにグループ化されたタスクをロード
        $records = collect($paginator->items());
        WmsShipmentSlipsTable::loadGroupedTasks($records);

        return $paginator;
    }
}
