<?php

namespace App\Filament\Resources\WmsOrderJxSettingResource\Pages;

use App\Filament\Resources\WmsOrderJxSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWmsOrderJxSettings extends ListRecords
{
    protected static string $resource = WmsOrderJxSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
