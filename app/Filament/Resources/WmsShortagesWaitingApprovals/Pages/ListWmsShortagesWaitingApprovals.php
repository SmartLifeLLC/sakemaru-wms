<?php

namespace App\Filament\Resources\WmsShortagesWaitingApprovals\Pages;

use App\Filament\Resources\WmsShortagesWaitingApprovals\WmsShortagesWaitingApprovalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWmsShortagesWaitingApprovals extends ListRecords
{
    protected static string $resource = WmsShortagesWaitingApprovalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
