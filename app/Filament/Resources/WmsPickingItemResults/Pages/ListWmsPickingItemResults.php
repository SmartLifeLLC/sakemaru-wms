<?php

namespace App\Filament\Resources\WmsPickingItemResults\Pages;

use App\Filament\Resources\WmsPickingItemResults\WmsPickingItemResultResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsPickingItemResults extends ListRecords
{
    protected static string $resource = WmsPickingItemResultResource::class;

    public function table(Table $table): Table
    {
        return WmsPickingItemResultResource::table($table)
            ->modifyQueryUsing(fn (Builder $query) =>
                $query->with([
                    'pickingTask',
                    'earning.buyer.partner',
                    'earning.warehouse',
                    'trade',
                    'item',
                    'location',
                ])
            );
    }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
