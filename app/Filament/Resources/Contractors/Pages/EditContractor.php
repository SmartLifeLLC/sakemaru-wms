<?php

namespace App\Filament\Resources\Contractors\Pages;

use App\Filament\Resources\Contractors\ContractorResource;
use App\Models\WmsContractorWarehouseMapping;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditContractor extends EditRecord
{
    protected static string $resource = ContractorResource::class;

    protected static ?string $title = '発注先編集';

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $mapping = WmsContractorWarehouseMapping::where('contractor_id', $this->record->id)->first();
        $data['mapped_warehouse_id'] = $mapping?->warehouse_id;

        return $data;
    }

    protected function afterSave(): void
    {
        $warehouseId = $this->data['mapped_warehouse_id'] ?? null;
        $contractorId = $this->record->id;

        if ($warehouseId) {
            WmsContractorWarehouseMapping::updateOrCreate(
                ['contractor_id' => $contractorId],
                ['warehouse_id' => $warehouseId]
            );
        } else {
            WmsContractorWarehouseMapping::where('contractor_id', $contractorId)->delete();
        }
    }
}
