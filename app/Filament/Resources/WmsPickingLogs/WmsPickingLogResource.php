<?php

namespace App\Filament\Resources\WmsPickingLogs;

use App\Filament\Resources\WmsPickingLogs\Pages\ListWmsPickingLogs;
use App\Filament\Resources\WmsPickingLogs\Tables\WmsPickingLogsTable;
use App\Models\WmsPickingLog;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsPickingLogResource extends Resource
{
    protected static ?string $model = WmsPickingLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'ログ';

    protected static ?string $navigationLabel = 'ピッキングログ';

    protected static ?string $modelLabel = 'ピッキングログ';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return WmsPickingLogsTable::configure($table);
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
            'index' => ListWmsPickingLogs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
