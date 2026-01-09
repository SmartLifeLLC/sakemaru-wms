<?php

namespace App\Filament\Resources\ClientPrinterCourseSettingResource\Pages;

use App\Filament\Resources\ClientPrinterCourseSettingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClientPrinterCourseSetting extends EditRecord
{
    protected static string $resource = ClientPrinterCourseSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
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
