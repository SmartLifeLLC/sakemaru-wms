<?php

namespace App\Filament\Resources\Contractors\Pages;

use App\Filament\Resources\Contractors\ContractorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContractor extends CreateRecord
{
    protected static string $resource = ContractorResource::class;

    protected static ?string $title = '発注先作成';

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
