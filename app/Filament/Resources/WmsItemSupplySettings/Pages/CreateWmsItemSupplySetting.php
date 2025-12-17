<?php

namespace App\Filament\Resources\WmsItemSupplySettings\Pages;

use App\Filament\Resources\WmsItemSupplySettings\WmsItemSupplySettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWmsItemSupplySetting extends CreateRecord
{
    protected static string $resource = WmsItemSupplySettingResource::class;

    protected static ?string $title = '供給設定作成';

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
