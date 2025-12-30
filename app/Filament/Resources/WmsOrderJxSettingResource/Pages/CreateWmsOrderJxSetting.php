<?php

namespace App\Filament\Resources\WmsOrderJxSettingResource\Pages;

use App\Filament\Resources\WmsOrderJxSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWmsOrderJxSetting extends CreateRecord
{
    protected static string $resource = WmsOrderJxSettingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
