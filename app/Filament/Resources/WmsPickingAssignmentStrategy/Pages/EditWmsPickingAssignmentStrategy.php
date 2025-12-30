<?php

namespace App\Filament\Resources\WmsPickingAssignmentStrategy\Pages;

use App\Filament\Resources\WmsPickingAssignmentStrategy\WmsPickingAssignmentStrategyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsPickingAssignmentStrategy extends EditRecord
{
    protected static string $resource = WmsPickingAssignmentStrategyResource::class;

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
