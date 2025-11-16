<?php

namespace App\Filament\Resources\WmsShortages\Pages;

use App\Filament\Resources\WmsShortages\WmsShortageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsShortage extends EditRecord
{
    protected static string $resource = WmsShortageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
