<?php

namespace App\Filament\Resources\WmsContractorWarehouseSettings\Pages;

use App\Filament\Resources\WmsContractorWarehouseSettings\WmsContractorWarehouseSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWmsContractorWarehouseSettings extends ListRecords
{
    protected static string $resource = WmsContractorWarehouseSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
