<?php

namespace App\Filament\Resources\WmsItemSupplySettings\Pages;

use App\Filament\Resources\WmsItemSupplySettings\WmsItemSupplySettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWmsItemSupplySettings extends ListRecords
{
    protected static string $resource = WmsItemSupplySettingResource::class;

    protected static ?string $title = '供給設定一覧';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('新規作成'),
        ];
    }
}
