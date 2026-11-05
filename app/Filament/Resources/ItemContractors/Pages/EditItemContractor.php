<?php

namespace App\Filament\Resources\ItemContractors\Pages;

use App\Filament\Resources\ItemContractors\ItemContractorResource;
use App\Services\ItemContractorReorderPoint;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Model;

class EditItemContractor extends EditRecord
{
    protected static string $resource = ItemContractorResource::class;

    protected static ?string $title = '商品発注先編集';

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Arr::except($data, ['max_stock', 'auto_order_quantity', 'is_auto_order', 'use_safety_stock_auto_update', 'is_auto_order_active']);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return array_key_exists('safety_stock', $data)
            ? app(ItemContractorReorderPoint::class)->update($record, $data)
            : parent::handleRecordUpdate($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
