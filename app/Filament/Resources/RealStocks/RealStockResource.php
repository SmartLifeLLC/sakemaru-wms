<?php

namespace App\Filament\Resources\RealStocks;

use App\Enums\EMenu;
use App\Filament\Resources\RealStocks\Pages\CreateRealStock;
use App\Filament\Resources\RealStocks\Pages\EditRealStock;
use App\Filament\Resources\RealStocks\Pages\ListRealStocks;
use App\Filament\Resources\RealStocks\Pages\ViewRealStock;
use App\Filament\Resources\RealStocks\Schemas\RealStockForm;
use App\Filament\Resources\RealStocks\Tables\RealStocksTable;
use App\Models\Sakemaru\RealStock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RealStockResource extends Resource
{
    protected static ?string $model = RealStock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCubeTransparent;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::REAL_STOCKS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::REAL_STOCKS->label();
    }

    public static function getModelLabel(): string
    {
        return '在庫';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::REAL_STOCKS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return RealStockForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RealStocksTable::configure($table);
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
            'index' => ListRealStocks::route('/'),
            'create' => CreateRealStock::route('/create'),
            'view' => ViewRealStock::route('/{record}'),
            'edit' => EditRealStock::route('/{record}/edit'),
        ];
    }
}
