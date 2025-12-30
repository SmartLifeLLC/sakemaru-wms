<?php

namespace App\Filament\Resources\ItemContractors\Pages;

use App\Filament\Resources\ItemContractors\ItemContractorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateItemContractor extends CreateRecord
{
    protected static string $resource = ItemContractorResource::class;

    protected static ?string $title = '商品発注先作成';

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
