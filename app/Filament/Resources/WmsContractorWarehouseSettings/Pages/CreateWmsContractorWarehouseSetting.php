<?php

namespace App\Filament\Resources\WmsContractorWarehouseSettings\Pages;

use App\Filament\Resources\WmsContractorWarehouseSettings\WmsContractorWarehouseSettingResource;
use App\Filament\Support\ContractorWarehouseSettingCreation;
use App\Models\WmsContractorWarehouseSetting;
use Filament\Resources\Pages\CreateRecord;

class CreateWmsContractorWarehouseSetting extends CreateRecord
{
    protected static string $resource = WmsContractorWarehouseSettingResource::class;

    protected function handleRecordCreation(array $data): WmsContractorWarehouseSetting
    {
        return ContractorWarehouseSettingCreation::create($data, $this->form->getStatePath());
    }
}
