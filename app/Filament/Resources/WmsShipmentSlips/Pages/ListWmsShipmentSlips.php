<?php

namespace App\Filament\Resources\WmsShipmentSlips\Pages;

use App\Filament\Resources\WmsShipmentSlips\WmsShipmentSlipsResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWmsShipmentSlips extends ListRecords
{
    protected static string $resource = WmsShipmentSlipsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
