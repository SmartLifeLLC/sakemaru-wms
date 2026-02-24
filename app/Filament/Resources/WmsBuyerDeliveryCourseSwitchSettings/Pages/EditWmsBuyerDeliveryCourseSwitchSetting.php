<?php

namespace App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Pages;

use App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\WmsBuyerDeliveryCourseSwitchSettingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsBuyerDeliveryCourseSwitchSetting extends EditRecord
{
    protected static string $resource = WmsBuyerDeliveryCourseSwitchSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
