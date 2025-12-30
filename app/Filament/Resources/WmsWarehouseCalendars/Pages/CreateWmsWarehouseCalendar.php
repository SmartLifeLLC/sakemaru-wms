<?php

namespace App\Filament\Resources\WmsWarehouseCalendars\Pages;

use App\Filament\Resources\WmsWarehouseCalendars\WmsWarehouseCalendarResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWmsWarehouseCalendar extends CreateRecord
{
    protected static string $resource = WmsWarehouseCalendarResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['is_manual_override'] = true;

        return $data;
    }
}
