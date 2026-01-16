<?php

namespace App\Filament\Resources\WmsImportLogs;

use App\Enums\EMenu;
use App\Filament\Resources\WmsImportLogs\Pages\ListWmsImportLogs;
use App\Filament\Resources\WmsImportLogs\Tables\WmsImportLogsTable;
use App\Models\WmsImportLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsImportLogResource extends Resource
{
    protected static ?string $model = WmsImportLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_IMPORT_LOGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_IMPORT_LOGS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_IMPORT_LOGS->sort();
    }

    public static function getModelLabel(): string
    {
        return 'インポート履歴';
    }

    public static function getPluralModelLabel(): string
    {
        return 'インポート履歴';
    }

    public static function table(Table $table): Table
    {
        return WmsImportLogsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsImportLogs::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
