<?php

namespace App\Filament\Resources\WmsPickingLogs\Pages;

use App\Filament\Resources\WmsPickingLogs\WmsPickingLogResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWmsPickingLogs extends ListRecords
{
    protected static string $resource = WmsPickingLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getTableQuery(): ?Builder
    {
        // Default filter: last 7 days
        return parent::getTableQuery()
            ->where('created_at', '>=', now()->subDays(7));
    }
}
