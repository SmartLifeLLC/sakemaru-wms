<?php

namespace App\Filament\Resources\WmsRouteCalculationLogs\Pages;

use App\Filament\Resources\WmsRouteCalculationLogs\WmsRouteCalculationLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWmsRouteCalculationLogs extends ListRecords
{
    protected static string $resource = WmsRouteCalculationLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
