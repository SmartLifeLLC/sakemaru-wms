<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsShortageAllocations\WmsShortageAllocationResource;
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
    use \App\Filament\Concerns\HasProxyShipmentPickingListAction;

    protected static string $resource = WmsShortageAllocationResource::class;

    protected static ?string $title = '横持ち出荷依頼';

    /**
     * アクティブなプリセットビューから倉庫IDを解決
     */
    protected function resolveWarehouseIdFromPresetView(): ?int
    {
        $activeView = $this->activePresetView;

        if (! $activeView) {
            return auth()->user()?->default_warehouse_id;
        }

        // "wh_{id}" 形式のキー
        if (str_starts_with($activeView, 'wh_')) {
            return (int) str_replace('wh_', '', $activeView);
        }

        // "default" キーはユーザーのデフォルト倉庫、またはデータの最初の倉庫
        if ($activeView === 'default') {
            $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;
            if ($userDefaultWarehouseId) {
                return $userDefaultWarehouseId;
            }

            // デフォルト倉庫がない場合、データの最初の倉庫
            return WmsShortageAllocation::where('is_finished', false)
                ->distinct()
                ->pluck('target_warehouse_id')
                ->filter()
                ->first();
        }

        return auth()->user()?->default_warehouse_id;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('is_finished', false)->activeRequest()
                ->with([
                    'shortage.wave',
                    'shortage.warehouse',
                    'shortage.item',
                    'shortage.trade.partner',
                    'targetWarehouse',
                    'sourceWarehouse',
                    'deliveryCourse',
                ])
            )
            ->pushToolbarActions([
                $this->getProxyShipmentPickingListAction(),
            ]);
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

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
                ->favorite()
                ->label($defaultWarehouse->name)
                ->default();
        } elseif ($warehouses->isNotEmpty()) {
            $first = $warehouses->first();
            $views['default'] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('target_warehouse_id', $first->id))
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
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
