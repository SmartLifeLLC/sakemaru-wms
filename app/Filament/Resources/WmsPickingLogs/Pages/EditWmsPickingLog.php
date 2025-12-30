<?php

namespace App\Filament\Resources\WmsPickingLogs\Pages;

use App\Filament\Resources\WmsPickingLogs\WmsPickingLogResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsPickingLog extends EditRecord
{
    protected static string $resource = WmsPickingLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
