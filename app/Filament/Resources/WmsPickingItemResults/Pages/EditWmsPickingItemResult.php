<?php

namespace App\Filament\Resources\WmsPickingItemResults\Pages;

use App\Filament\Resources\WmsPickingItemResults\WmsPickingItemResultResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsPickingItemResult extends EditRecord
{
    protected static string $resource = WmsPickingItemResultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
