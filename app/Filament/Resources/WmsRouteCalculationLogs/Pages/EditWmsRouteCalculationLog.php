<?php

namespace App\Filament\Resources\WmsRouteCalculationLogs\Pages;

use App\Filament\Resources\WmsRouteCalculationLogs\WmsRouteCalculationLogResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsRouteCalculationLog extends EditRecord
{
    protected static string $resource = WmsRouteCalculationLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
