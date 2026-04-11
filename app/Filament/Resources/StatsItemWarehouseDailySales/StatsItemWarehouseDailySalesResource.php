<?php

namespace App\Filament\Resources\StatsItemWarehouseDailySales;

use App\Enums\EMenu;
use App\Filament\Resources\StatsItemWarehouseDailySales\Pages\ListStatsItemWarehouseDailySales;
use App\Filament\Resources\StatsItemWarehouseDailySales\Tables\StatsItemWarehouseDailySalesTable;
use App\Models\StatsItemWarehouseDailySales;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StatsItemWarehouseDailySalesResource extends Resource
{
    protected static ?string $model = StatsItemWarehouseDailySales::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::STATS_DAILY_SALES->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::STATS_DAILY_SALES->label();
    }

    public static function getModelLabel(): string
    {
        return '日別商品出荷データ';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::STATS_DAILY_SALES->sort();
    }

    public static function table(Table $table): Table
    {
        return StatsItemWarehouseDailySalesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['item', 'warehouse']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStatsItemWarehouseDailySales::route('/'),
        ];
    }
}
