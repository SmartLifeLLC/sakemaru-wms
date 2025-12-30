<?php

namespace App\Filament\Resources\WmsShortagesWaitingApprovals\Pages;

use App\Filament\Resources\WmsShortagesWaitingApprovals\WmsShortagesWaitingApprovalResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsShortagesWaitingApproval extends EditRecord
{
    protected static string $resource = WmsShortagesWaitingApprovalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
