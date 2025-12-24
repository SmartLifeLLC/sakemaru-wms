<?php

namespace App\Filament\Resources\WmsContractorWarehouseMappings\Pages;

use App\Filament\Resources\WmsContractorWarehouseMappings\WmsContractorWarehouseMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWmsContractorWarehouseMappings extends ListRecords
{
    protected static string $resource = WmsContractorWarehouseMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
