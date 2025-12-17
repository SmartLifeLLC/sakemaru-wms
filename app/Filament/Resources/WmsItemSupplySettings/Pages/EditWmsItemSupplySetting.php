<?php

namespace App\Filament\Resources\WmsItemSupplySettings\Pages;

use App\Filament\Resources\WmsItemSupplySettings\WmsItemSupplySettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWmsItemSupplySetting extends EditRecord
{
    protected static string $resource = WmsItemSupplySettingResource::class;

    protected static ?string $title = '供給設定編集';

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
