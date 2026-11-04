<?php

namespace App\Filament\Resources\ItemContractors\Pages;

use App\Filament\Resources\ItemContractors\ItemContractorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateItemContractor extends CreateRecord
{
    protected static string $resource = ItemContractorResource::class;

    protected static ?string $title = '商品発注先作成';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Preserve the defaults of the former form fields.
        return array_replace($data, ['safety_stock' => 0, 'max_stock' => 0, 'auto_order_quantity' => 0]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
