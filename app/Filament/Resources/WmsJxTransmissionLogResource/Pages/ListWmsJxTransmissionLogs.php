<?php

namespace App\Filament\Resources\WmsJxTransmissionLogResource\Pages;

use App\Filament\Resources\WmsJxTransmissionLogResource;
use Filament\Resources\Pages\ListRecords;

class ListWmsJxTransmissionLogs extends ListRecords
{
    protected static string $resource = WmsJxTransmissionLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
