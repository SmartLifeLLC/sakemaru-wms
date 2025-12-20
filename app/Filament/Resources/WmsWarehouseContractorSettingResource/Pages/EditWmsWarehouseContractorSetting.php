<?php

namespace App\Filament\Resources\WmsWarehouseContractorSettingResource\Pages;

use App\Filament\Resources\WmsWarehouseContractorSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWmsWarehouseContractorSetting extends EditRecord
{
    protected static string $resource = WmsWarehouseContractorSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
