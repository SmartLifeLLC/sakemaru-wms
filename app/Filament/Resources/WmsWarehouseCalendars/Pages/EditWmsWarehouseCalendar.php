<?php

namespace App\Filament\Resources\WmsWarehouseCalendars\Pages;

use App\Filament\Resources\WmsWarehouseCalendars\WmsWarehouseCalendarResource;
use Filament\Resources\Pages\EditRecord;

class EditWmsWarehouseCalendar extends EditRecord
{
    protected static string $resource = WmsWarehouseCalendarResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['is_manual_override'] = true;

        return $data;
    }
}
