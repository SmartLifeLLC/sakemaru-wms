<?php

namespace App\Filament\Resources\WmsContractorWarehouseMappings\Pages;

use App\Filament\Resources\WmsContractorWarehouseMappings\WmsContractorWarehouseMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsContractorWarehouseMapping extends EditRecord
{
    protected static string $resource = WmsContractorWarehouseMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
