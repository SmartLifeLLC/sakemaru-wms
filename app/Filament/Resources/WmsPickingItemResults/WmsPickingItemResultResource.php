<?php

namespace App\Filament\Resources\WmsPickingItemResults;

use App\Filament\Resources\WmsPickingItemResults\Pages\CreateWmsPickingItemResult;
use App\Filament\Resources\WmsPickingItemResults\Pages\EditWmsPickingItemResult;
use App\Filament\Resources\WmsPickingItemResults\Pages\ListWmsPickingItemResults;
use App\Filament\Resources\WmsPickingItemResults\Schemas\WmsPickingItemResultForm;
use App\Filament\Resources\WmsPickingItemResults\Tables\WmsPickingItemResultsTable;
use App\Models\WmsPickingItemResult;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WmsPickingItemResultResource extends Resource
{
    protected static ?string $model = WmsPickingItemResult::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = '出荷管理';

    protected static ?string $navigationLabel = 'ピッキング商品リスト';

    protected static ?string $modelLabel = 'ピッキング商品';

    protected static ?string $pluralModelLabel = 'ピッキング商品リスト';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return WmsPickingItemResultForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsPickingItemResultsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsPickingItemResults::route('/'),
        ];
    }
}
