<?php

namespace App\Filament\Resources\WmsOrderJxSettingResource\Pages;

use App\Filament\Resources\WmsOrderJxSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWmsOrderJxSetting extends EditRecord
{
    protected static string $resource = WmsOrderJxSettingResource::class;

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
