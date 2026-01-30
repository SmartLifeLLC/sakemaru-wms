<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\WmsWarehouseAutoOrderSetting;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWarehouse extends EditRecord
{
    protected static string $resource = WarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $setting = WmsWarehouseAutoOrderSetting::where('warehouse_id', $this->record->id)->first();
        $data['auto_order_enabled'] = $setting?->is_auto_order_enabled ?? false;

        return $data;
    }

    protected function afterSave(): void
    {
        $autoOrderEnabled = $this->data['auto_order_enabled'] ?? false;

        WmsWarehouseAutoOrderSetting::updateOrCreate(
            ['warehouse_id' => $this->record->id],
            ['is_auto_order_enabled' => $autoOrderEnabled]
        );
    }
}
