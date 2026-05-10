<?php

namespace App\Filament\Resources\WmsStockTransferConfirmed\Pages;

use App\Filament\Resources\WmsStockTransferConfirmed\WmsStockTransferConfirmedResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsStockTransferConfirmed extends ListRecords
{
    protected static string $resource = WmsStockTransferConfirmedResource::class;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByDesc('created_at'));
    }
}
