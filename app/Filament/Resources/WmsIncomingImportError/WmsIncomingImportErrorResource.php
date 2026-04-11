<?php

namespace App\Filament\Resources\WmsIncomingImportError;

use App\Enums\EMenu;
use App\Filament\Resources\WmsIncomingImportError\Pages\ListWmsIncomingImportErrors;
use App\Filament\Resources\WmsIncomingImportError\Tables\WmsIncomingImportErrorsTable;
use App\Models\WmsIncomingImportError;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsIncomingImportErrorResource extends Resource
{
    protected static ?string $model = WmsIncomingImportError::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationCircle;

    protected static ?string $slug = 'wms-incoming-import-errors';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_INCOMING_IMPORT_ERRORS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_INCOMING_IMPORT_ERRORS->label();
    }

    public static function getModelLabel(): string
    {
        return '取込エラー';
    }

    public static function getPluralModelLabel(): string
    {
        return '取込エラー';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_INCOMING_IMPORT_ERRORS->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsIncomingImportErrorsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsIncomingImportErrors::route('/'),
        ];
    }
}
