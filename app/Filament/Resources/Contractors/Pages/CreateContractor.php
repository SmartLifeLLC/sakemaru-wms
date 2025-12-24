<?php

namespace App\Filament\Resources\Contractors\Pages;

use App\Filament\Resources\Contractors\ContractorResource;
use App\Models\WmsContractorWarehouseMapping;
use Filament\Resources\Pages\CreateRecord;

class CreateContractor extends CreateRecord
{
    protected static string $resource = ContractorResource::class;

    protected static ?string $title = '発注先作成';

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        $warehouseId = $this->data['mapped_warehouse_id'] ?? null;

        if ($warehouseId) {
            WmsContractorWarehouseMapping::create([
                'contractor_id' => $this->record->id,
                'warehouse_id' => $warehouseId,
            ]);
        }
    }
}
