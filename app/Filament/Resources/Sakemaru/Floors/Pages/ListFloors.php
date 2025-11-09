<?php

namespace App\Filament\Resources\Sakemaru\Floors\Pages;

use App\Filament\Resources\Sakemaru\Floors\FloorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFloors extends ListRecords
{
    protected static string $resource = FloorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
