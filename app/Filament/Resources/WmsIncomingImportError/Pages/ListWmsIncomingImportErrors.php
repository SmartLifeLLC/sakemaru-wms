<?php

namespace App\Filament\Resources\WmsIncomingImportError\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsIncomingImportError\WmsIncomingImportErrorResource;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsIncomingImportErrors extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsIncomingImportErrorResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['receivedFile'])
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

            'errors' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('error_type', 'ERROR'))
                ->favorite()
                ->label('エラー'),

            'warnings' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('error_type', 'WARNING'))
                ->favorite()
                ->label('ワーニング'),

            'unresolved' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_resolved', false))
                ->favorite()
                ->label('未解決'),
        ];
    }
}
