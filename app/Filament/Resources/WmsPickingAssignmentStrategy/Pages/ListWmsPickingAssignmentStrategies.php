<?php

namespace App\Filament\Resources\WmsPickingAssignmentStrategy\Pages;

use App\Filament\Resources\WmsPickingAssignmentStrategy\WmsPickingAssignmentStrategyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWmsPickingAssignmentStrategies extends ListRecords
{
    protected static string $resource = WmsPickingAssignmentStrategyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
