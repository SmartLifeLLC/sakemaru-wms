<?php

namespace App\Filament\Resources\WmsPickingItemResults;

use App\Enums\EMenu;
use App\Filament\Resources\WmsPickingItemResults\Pages\ListWmsPickingItemResults;
use App\Filament\Resources\WmsPickingItemResults\Schemas\WmsPickingItemResultForm;
use App\Filament\Resources\WmsPickingItemResults\Tables\WmsPickingItemResultsTable;
use App\Models\WmsPickingItemResult;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsPickingItemResultResource extends Resource
{
    protected static ?string $model = WmsPickingItemResult::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'pickingTask',
                'trade',
                'item',
                'earning.buyer.partner',
                'earning.delivery_course',
            ]);
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'ピッキング商品リスト';

    protected static ?string $modelLabel = 'ピッキング商品';

    protected static ?string $pluralModelLabel = 'ピッキング商品リスト';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_PICKING_ITEM_RESULTS->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_PICKING_ITEM_RESULTS->sort();
    }

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
