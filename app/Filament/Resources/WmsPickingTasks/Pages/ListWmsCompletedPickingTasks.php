<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingTasks\Tables\WmsPickingTasksTable;
use App\Filament\Resources\WmsPickingTasks\WmsCompletedPickingTaskResource;
use App\Models\Sakemaru\Warehouse;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ListWmsCompletedPickingTasks extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsCompletedPickingTaskResource::class;

    public function getTitle(): string|Htmlable
    {
        $base = 'ピッキング完了履歴';
        $warehouseName = $this->getSelectedWarehouseName();

        return $warehouseName ? "{$base} ({$warehouseName})" : $base;
    }

    public function getPresetViews(): array
    {
        $userWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $defaultFilterData = $userWarehouseId
            ? ['warehouse_id' => ['value' => (string) $userWarehouseId]]
            : [];

        return [
            'default' => PresetView::make()
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('デフォルト')
                ->default(),
        ];
    }

    public function table(Table $table): Table
    {
        return WmsPickingTasksTable::configure($table, isCompletedView: true);
    }

    private function getSelectedWarehouseName(): ?string
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        return $warehouseId ? Warehouse::find($warehouseId)?->name : null;
    }
}
