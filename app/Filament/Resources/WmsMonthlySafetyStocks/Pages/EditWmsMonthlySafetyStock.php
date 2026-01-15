<?php

namespace App\Filament\Resources\WmsMonthlySafetyStocks\Pages;

use App\Filament\Resources\WmsMonthlySafetyStocks\WmsMonthlySafetyStockResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsMonthlySafetyStock extends EditRecord
{
    protected static string $resource = WmsMonthlySafetyStockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
