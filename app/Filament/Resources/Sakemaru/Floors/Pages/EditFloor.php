<?php

namespace App\Filament\Resources\Sakemaru\Floors\Pages;

use App\Filament\Resources\Sakemaru\Floors\FloorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFloor extends EditRecord
{
    protected static string $resource = FloorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
