<?php

namespace App\Filament\Resources\WmsWarehouseContractorSettingResource\Pages;

use App\Filament\Resources\WmsWarehouseContractorSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWmsWarehouseContractorSetting extends CreateRecord
{
    protected static string $resource = WmsWarehouseContractorSettingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
