<?php

namespace App\Filament\Resources\WmsPickingAssignmentStrategy\Pages;

use App\Filament\Resources\WmsPickingAssignmentStrategy\WmsPickingAssignmentStrategyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWmsPickingAssignmentStrategy extends CreateRecord
{
    protected static string $resource = WmsPickingAssignmentStrategyResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
