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
        $views = [
            'all' => PresetView::make()
                ->favorite()
                ->label('全倉庫')
                ->default(),
        ];

        // 未完了の横持ち出荷依頼で使われている倉庫のみタブ表示
        $warehouseIds = WmsShortageAllocation::where('is_finished', false)
            ->distinct()
            ->pluck('target_warehouse_id')
            ->filter()
            ->toArray();

        if (! empty($warehouseIds)) {
            $warehouses = Warehouse::whereIn('id', $warehouseIds)
                ->orderBy('code')
                ->get();

            foreach ($warehouses as $warehouse) {
                $views['warehouse_'.$warehouse->id] = PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('target_warehouse_id', $warehouse->id))
                    ->favorite()
                    ->label($warehouse->name);
            }
        }

        return $views;
    }
}
