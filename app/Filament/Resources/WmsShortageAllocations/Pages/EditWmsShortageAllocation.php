<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Filament\Resources\WmsShortageAllocations\WmsShortageAllocationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsShortageAllocation extends EditRecord
{
    protected static string $resource = WmsShortageAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
