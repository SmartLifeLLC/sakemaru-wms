<?php

namespace App\Filament\Resources\WmsShipmentSlips;

use App\Enums\EMenu;
use App\Enums\EMenuCategory;
use App\Filament\Resources\WmsShipmentSlips\Pages\ListWmsShipmentSlips;
use App\Filament\Resources\WmsShipmentSlips\Tables\WmsShipmentSlipsTable;
use App\Models\WmsPickingTask;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsShipmentSlipsResource extends Resource
{
    protected static ?string $model = WmsPickingTask::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = '出荷伝票管理';

    protected static ?string $modelLabel = '出荷伝票';

    protected static ?string $pluralModelLabel = '出荷伝票一覧';

    protected static ?string $slug = 'wms-shipment-slips';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_SHIPMENT_SLIPS->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_SHIPMENT_SLIPS->sort();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return WmsShipmentSlipsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsShipmentSlips::route('/'),
        ];
    }
}
