<?php

namespace App\Filament\Resources\WmsIncomingTransmitted\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsIncomingTransmitted\WmsIncomingTransmittedResource;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsIncomingTransmitted extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsIncomingTransmittedResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->orderBy('updated_at', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    public function getPresetViews(): array
    {
        return [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(),

            'today' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('actual_arrival_date', today()))
                ->favorite()
                ->label('本日入庫'),

            'auto' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_source', 'AUTO'))
                ->favorite()
                ->label('自動発注'),

            'manual' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('order_source', 'MANUAL'))
                ->favorite()
                ->label('手動発注'),
        ];
    }
}
