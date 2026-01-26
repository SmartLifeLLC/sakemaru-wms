<?php

namespace App\Filament\Resources\WmsOrderDocuments\Pages;

use App\Filament\Resources\WmsOrderDocuments\WmsOrderDocumentResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWmsOrderDocuments extends ListRecords
{
    protected static string $resource = WmsOrderDocumentResource::class;

    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['warehouse', 'contractor'])
                ->orderBy('created_at', 'desc')
            );
    }
}
