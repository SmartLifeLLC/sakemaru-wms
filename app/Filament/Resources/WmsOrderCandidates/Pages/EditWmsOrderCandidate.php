<?php

namespace App\Filament\Resources\WmsOrderCandidates\Pages;

use App\Filament\Resources\WmsOrderCandidates\WmsOrderCandidateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWmsOrderCandidate extends EditRecord
{
    protected static string $resource = WmsOrderCandidateResource::class;

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
