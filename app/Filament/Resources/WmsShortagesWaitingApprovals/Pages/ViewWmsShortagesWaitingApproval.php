<?php

namespace App\Filament\Resources\WmsShortagesWaitingApprovals\Pages;

use App\Filament\Resources\WmsShortagesWaitingApprovals\WmsShortagesWaitingApprovalResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWmsShortagesWaitingApproval extends ViewRecord
{
    protected static string $resource = WmsShortagesWaitingApprovalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
