<?php

namespace App\Filament\Resources\StatsItemWarehouseSalesSummaries;

use App\Enums\EMenu;
use App\Filament\Resources\StatsItemWarehouseSalesSummaries\Pages\ListStatsItemWarehouseSalesSummaries;
use App\Filament\Resources\StatsItemWarehouseSalesSummaries\Tables\StatsItemWarehouseSalesSummariesTable;
use App\Models\StatsItemWarehouseSalesSummary;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StatsItemWarehouseSalesSummaryResource extends Resource
{
    protected static ?string $model = StatsItemWarehouseSalesSummary::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::STATS_SALES_SUMMARIES->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::STATS_SALES_SUMMARIES->label();
    }

    public static function getModelLabel(): string
    {
        return '商品別出荷サマリ';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::STATS_SALES_SUMMARIES->sort();
    }

    public static function table(Table $table): Table
    {
        return StatsItemWarehouseSalesSummariesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['item', 'warehouse']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStatsItemWarehouseSalesSummaries::route('/'),
        ];
    }
}
