<?php

namespace App\Filament\Resources\WmsExportLogs;

use App\Enums\EMenu;
use App\Filament\Resources\WmsExportLogs\Pages\ListWmsExportLogs;
use App\Filament\Resources\WmsExportLogs\Tables\WmsExportLogsTable;
use App\Models\WmsExportLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsExportLogResource extends Resource
{
    protected static ?string $model = WmsExportLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $navigationLabel = 'ダウンロードログ';

    protected static ?string $modelLabel = 'ダウンロードログ';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_EXPORT_LOGS->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_EXPORT_LOGS->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsExportLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsExportLogs::route('/'),
        ];
    }
}
