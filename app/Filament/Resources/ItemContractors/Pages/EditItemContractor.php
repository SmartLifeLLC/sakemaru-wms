<?php

namespace App\Filament\Resources\ItemContractors\Pages;

use App\Filament\Resources\ItemContractors\ItemContractorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

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
        return Arr::except($data, ['safety_stock', 'max_stock', 'auto_order_quantity']);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
