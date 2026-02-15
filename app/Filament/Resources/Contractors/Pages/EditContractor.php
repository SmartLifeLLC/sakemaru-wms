<?php

namespace App\Filament\Resources\Contractors\Pages;

use App\Filament\Resources\Contractors\ContractorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditContractor extends EditRecord
{
    protected static string $resource = ContractorResource::class;

    protected static ?string $title = '発注先編集';

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return '基本情報';
    }

    public function getContentTabIcon(): string|null
    {
        return 'heroicon-o-building-office';
    }

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
