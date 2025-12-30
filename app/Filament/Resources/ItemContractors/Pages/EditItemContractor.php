<?php

namespace App\Filament\Resources\ItemContractors\Pages;

use App\Filament\Resources\ItemContractors\ItemContractorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

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

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
