<?php

namespace App\Filament\Resources\WmsIncomingImportLog;

use App\Enums\EMenu;
use App\Filament\Resources\WmsIncomingImportLog\Pages\ListWmsIncomingImportLogs;
use App\Filament\Resources\WmsIncomingImportLog\Tables\WmsIncomingImportLogsTable;
use App\Models\WmsIncomingReceivedDetail;
use BackedEnum;
use App\Filament\Support\AdminResource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsIncomingImportLogResource extends AdminResource
{
    protected static ?string $model = WmsIncomingReceivedDetail::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $slug = 'wms-incoming-import-logs';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_INCOMING_IMPORT_LOGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_INCOMING_IMPORT_LOGS->label();
    }

    public static function getModelLabel(): string
    {
        return '取込ログ';
    }

    public static function getPluralModelLabel(): string
    {
        return '取込ログ';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_INCOMING_IMPORT_LOGS->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsIncomingImportLogsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsIncomingImportLogs::route('/'),
        ];
    }
}
