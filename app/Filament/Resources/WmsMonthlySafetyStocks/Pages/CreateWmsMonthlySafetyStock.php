<?php

namespace App\Filament\Resources\WmsMonthlySafetyStocks\Pages;

use App\Filament\Resources\WmsMonthlySafetyStocks\WmsMonthlySafetyStockResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWmsMonthlySafetyStock extends CreateRecord
{
    protected static string $resource = WmsMonthlySafetyStockResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
