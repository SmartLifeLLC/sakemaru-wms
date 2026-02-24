<?php

namespace App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Pages;

use App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\WmsBuyerDeliveryCourseSwitchSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWmsBuyerDeliveryCourseSwitchSettings extends ListRecords
{
    protected static string $resource = WmsBuyerDeliveryCourseSwitchSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
