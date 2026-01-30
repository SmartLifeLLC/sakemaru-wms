<?php

namespace App\Filament\Resources\WmsImportLogs\Pages;

use App\Filament\Resources\WmsImportLogs\WmsImportLogResource;
use Filament\Resources\Pages\ListRecords;

class ListWmsImportLogs extends ListRecords
{
    protected static string $resource = WmsImportLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
