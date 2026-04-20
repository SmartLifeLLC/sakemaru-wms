<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Models\Sakemaru\Warehouse;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWarehouseWmsShortageAllocations extends ListWmsShortageAllocations
{
    protected static ?string $title = '倉庫別横持ち出荷依頼';

    protected function resolveWarehouseIdFromPresetView(): ?int
    {
        return auth()->user()?->getSelectedWarehouseId();
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        $columns = $table->getColumns();
        $keys = array_keys($columns);
        $idIndex = array_search('id', $keys);
        $position = $idIndex !== false ? $idIndex + 1 : 0;

        $shipmentDateColumn = TextColumn::make('shipment_date')
            ->label('出荷日')
            ->date('m/d')
            ->sortable()
            ->alignment('center');
        $shipmentDateColumn->table($table);

        $before = array_slice($columns, 0, $position, true);
        $after = array_slice($columns, $position, null, true);
        $newColumns = $before + ['shipment_date' => $shipmentDateColumn] + $after;

        $table->columns(array_values($newColumns));

        return $table;
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
