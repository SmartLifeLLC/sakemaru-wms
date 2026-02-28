<?php

namespace App\Filament\Resources\WmsAutoOrderExecutionLogs;

use App\Enums\EMenu;
use App\Filament\Resources\WmsAutoOrderExecutionLogs\Pages\ListWmsAutoOrderExecutionLogs;
use App\Filament\Resources\WmsAutoOrderExecutionLogs\Tables\WmsAutoOrderExecutionLogsTable;
use App\Models\WmsAutoOrderExecutionLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsAutoOrderExecutionLogResource extends Resource
{
    protected static ?string $model = WmsAutoOrderExecutionLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_AUTO_ORDER_EXECUTION_LOG->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_AUTO_ORDER_EXECUTION_LOG->label();
    }

    public static function getModelLabel(): string
    {
        return '自動発注実行ログ';
    }

    public static function getPluralModelLabel(): string
    {
        return '自動発注実行ログ';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_AUTO_ORDER_EXECUTION_LOG->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsAutoOrderExecutionLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsAutoOrderExecutionLogs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
