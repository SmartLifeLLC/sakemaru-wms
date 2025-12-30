<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Pages;

use App\Filament\Resources\WmsStockTransferCandidates\WmsStockTransferCandidateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsStockTransferCandidate extends EditRecord
{
    protected static string $resource = WmsStockTransferCandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['is_manually_modified'] = true;
        $data['modified_by'] = auth()->id();
        $data['modified_at'] = now();

        return $data;
    }
}
