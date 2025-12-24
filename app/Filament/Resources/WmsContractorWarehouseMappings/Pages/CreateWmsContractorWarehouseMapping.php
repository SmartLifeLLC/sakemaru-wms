<?php

namespace App\Filament\Resources\WmsContractorWarehouseMappings\Pages;

use App\Filament\Resources\WmsContractorWarehouseMappings\WmsContractorWarehouseMappingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWmsContractorWarehouseMapping extends CreateRecord
{
    protected static string $resource = WmsContractorWarehouseMappingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
