<?php

namespace App\Filament\Resources\WmsMonthlySafetyStocks;

use App\Enums\EMenu;
use App\Filament\Resources\WmsMonthlySafetyStocks\Pages\ListWmsMonthlySafetyStocks;
use App\Filament\Resources\WmsMonthlySafetyStocks\Tables\WmsMonthlySafetyStocksTable;
use App\Models\WmsMonthlySafetyStock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsMonthlySafetyStockResource extends Resource
{
    protected static ?string $model = WmsMonthlySafetyStock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_MONTHLY_SAFETY_STOCKS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_MONTHLY_SAFETY_STOCKS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_MONTHLY_SAFETY_STOCKS->sort();
    }

    public static function getModelLabel(): string
    {
        return '月別発注点';
    }

    public static function getPluralModelLabel(): string
    {
        return '月別発注点';
    }

    public static function table(Table $table): Table
    {
        return WmsMonthlySafetyStocksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsMonthlySafetyStocks::route('/'),
        ];
    }
}
