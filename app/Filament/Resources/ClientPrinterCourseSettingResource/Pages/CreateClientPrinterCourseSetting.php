<?php

namespace App\Filament\Resources\ClientPrinterCourseSettingResource\Pages;

use App\Filament\Resources\ClientPrinterCourseSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClientPrinterCourseSetting extends CreateRecord
{
    protected static string $resource = ClientPrinterCourseSettingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Set client_id from the selected warehouse
        if (isset($data['warehouse_id'])) {
            $warehouse = \App\Models\Sakemaru\Warehouse::find($data['warehouse_id']);
            if ($warehouse) {
                $data['client_id'] = $warehouse->client_id;
            }
        }

        return $data;
    }
}
