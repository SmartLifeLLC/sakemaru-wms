<?php

namespace App\Filament\Resources\WmsIncomingImportLog\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsIncomingImportLog\WmsIncomingImportLogResource;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsIncomingImportLogs extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsIncomingImportLogResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['file', 'slip'])
                ->orderBy('id', 'desc')
            );
    }

    public function getPresetViews(): array
    {
        return [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(),

            'matched' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('match_status', 'MATCHED'))
                ->favorite()
                ->label('照合済み'),

            'shortage' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('match_status', ['SHORTAGE', 'PARTIAL']))
                ->favorite()
                ->label('欠品'),

            'unmatched' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('match_status', ['UNMATCHED', 'PENDING']))
                ->favorite()
                ->label('未照合'),
        ];
    }
}
