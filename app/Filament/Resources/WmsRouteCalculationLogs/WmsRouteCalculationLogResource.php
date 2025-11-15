<?php

namespace App\Filament\Resources\WmsRouteCalculationLogs;

use App\Filament\Resources\WmsRouteCalculationLogs\Pages\CreateWmsRouteCalculationLog;
use App\Filament\Resources\WmsRouteCalculationLogs\Pages\EditWmsRouteCalculationLog;
use App\Filament\Resources\WmsRouteCalculationLogs\Pages\ListWmsRouteCalculationLogs;
use App\Filament\Resources\WmsRouteCalculationLogs\Pages\ViewWmsRouteCalculationLog;
use App\Filament\Resources\WmsRouteCalculationLogs\Schemas\WmsRouteCalculationLogForm;
use App\Filament\Resources\WmsRouteCalculationLogs\Schemas\WmsRouteCalculationLogInfolist;
use App\Filament\Resources\WmsRouteCalculationLogs\Tables\WmsRouteCalculationLogsTable;
use App\Models\WmsRouteCalculationLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsRouteCalculationLogResource extends Resource
{
    protected static ?string $model = WmsRouteCalculationLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public static function getNavigationLabel(): string
    {
        return '経路計算履歴';
    }

    public static function getNavigationGroup(): ?string
    {
        return \App\Enums\EMenuCategory::OUTBOUND->label();
    }

    public static function getNavigationSort(): ?int
    {
        return 61;
    }

    public static function form(Schema $schema): Schema
    {
        return WmsRouteCalculationLogForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WmsRouteCalculationLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsRouteCalculationLogsTable::configure($table);
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
            'index' => ListWmsRouteCalculationLogs::route('/'),
            'create' => CreateWmsRouteCalculationLog::route('/create'),
            'view' => ViewWmsRouteCalculationLog::route('/{record}'),
            'edit' => EditWmsRouteCalculationLog::route('/{record}/edit'),
        ];
    }
}
