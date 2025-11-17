<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Filament\Resources\WmsShortageAllocations\WmsShortageAllocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsShortageAllocations extends ListRecords
{
    protected static string $resource = WmsShortageAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'shortage.wave',
                    'shortage.warehouse',
                    'shortage.item',
                    'fromWarehouse',
                ])
            );
    }
}
