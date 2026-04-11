<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsShortageAllocations\WmsShortageAllocationResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortageAllocation;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsShortageAllocations extends ListRecords
{
    use AdvancedTables, HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsShortageAllocationResource::class;

    protected static ?string $title = '横持ち出荷依頼';

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('is_finished', false)
                ->with([
                    'shortage.wave',
                    'shortage.warehouse',
                    'shortage.item',
                    'shortage.trade.partner',
                    'targetWarehouse',
                    'sourceWarehouse',
                    'deliveryCourse',
                ])
            );
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;
        $systemDate = ClientSetting::systemDateYMD();

        $defaultFilterData = [
            'shipment_date' => ['shipment_date' => $systemDate],
        ];

        // 未完了の横持ち出荷依頼で使われている倉庫を取得
        $warehouseIds = WmsShortageAllocation::where('is_finished', false)
            ->distinct()
            ->pluck('target_warehouse_id')
            ->filter()
            ->toArray();

        // デフォルト倉庫がデータになくても含める
        if ($userDefaultWarehouseId && ! in_array($userDefaultWarehouseId, $warehouseIds)) {
            $warehouseIds[] = $userDefaultWarehouseId;
        }

        $warehouses = Warehouse::whereIn('id', $warehouseIds)
            ->where('is_virtual', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $defaultWarehouse = $userDefaultWarehouseId
            ? $warehouses->firstWhere('id', $userDefaultWarehouseId)
            : null;

        $views = [];

        if ($defaultWarehouse) {
            $views['default'] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('target_warehouse_id', $userDefaultWarehouseId))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label($defaultWarehouse->name)
                ->default();
        } elseif ($warehouses->isNotEmpty()) {
            $first = $warehouses->first();
            $views['default'] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('target_warehouse_id', $first->id))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label($first->name)
                ->default();
        }

        foreach ($warehouses as $warehouse) {
            if ($defaultWarehouse && $warehouse->id === $defaultWarehouse->id) {
                continue;
            }
            if (! $defaultWarehouse && $warehouse->id === $warehouses->first()->id) {
                continue;
            }
            $views["wh_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('target_warehouse_id', $warehouse->id))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
