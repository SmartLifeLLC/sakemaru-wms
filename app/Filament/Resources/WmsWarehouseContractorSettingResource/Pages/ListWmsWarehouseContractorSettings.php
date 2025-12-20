<?php

namespace App\Filament\Resources\WmsWarehouseContractorSettingResource\Pages;

use App\Filament\Resources\WmsWarehouseContractorSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWmsWarehouseContractorSettings extends ListRecords
{
    protected static string $resource = WmsWarehouseContractorSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
