<?php

namespace App\Filament\Resources\ItemContractors\Pages;

use App\Filament\Resources\ItemContractors\ItemContractorResource;
use App\Services\ItemContractorReorderPoint;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateItemContractor extends CreateRecord
{
    protected static string $resource = ItemContractorResource::class;

    protected static ?string $title = '商品発注先作成';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Preserve the defaults of the former form fields.
        return array_replace($data, ['max_stock' => 0, 'auto_order_quantity' => 0,
            'is_auto_order' => false, 'use_safety_stock_auto_update' => false]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::connection('sakemaru')->transaction(function () use ($data) {
            $point = $data['safety_stock'] ?? 0;
            unset($data['safety_stock']);
            $record = parent::handleRecordCreation($data);

            return app(ItemContractorReorderPoint::class)->update($record, ['safety_stock' => $point]);
        }, 3);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
