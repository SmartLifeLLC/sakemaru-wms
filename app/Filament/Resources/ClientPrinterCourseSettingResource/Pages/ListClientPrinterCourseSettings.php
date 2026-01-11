<?php

namespace App\Filament\Resources\ClientPrinterCourseSettingResource\Pages;

use App\Filament\Resources\ClientPrinterCourseSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClientPrinterCourseSettings extends ListRecords
{
    protected static string $resource = ClientPrinterCourseSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
