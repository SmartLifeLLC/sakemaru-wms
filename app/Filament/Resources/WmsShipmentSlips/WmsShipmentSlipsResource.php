<?php

namespace App\Filament\Resources\WmsShipmentSlips;

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

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::OUTBOUND->label();
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
