<?php

namespace App\Filament\Resources\WmsRouteCalculationLogs\Pages;

use App\Filament\Resources\WmsRouteCalculationLogs\WmsRouteCalculationLogResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWmsRouteCalculationLog extends ViewRecord
{
    protected static string $resource = WmsRouteCalculationLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
