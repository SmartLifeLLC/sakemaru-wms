<?php

namespace App\Filament\Resources\WmsOrderDataFiles;

use App\Enums\EMenu;
use App\Filament\Resources\WmsOrderDataFiles\Pages\ListWmsOrderDataFiles;
use App\Filament\Resources\WmsOrderDataFiles\Tables\WmsOrderDataFilesTable;
use App\Models\WmsOrderDataFile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsOrderDataFileResource extends Resource
{
    protected static ?string $model = WmsOrderDataFile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $slug = 'wms-order-data-files';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ORDER_DATA_FILES->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ORDER_DATA_FILES->sort();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ORDER_DATA_FILES->label();
    }

    public static function getModelLabel(): string
    {
        return '発注データファイル';
    }

    public static function getPluralModelLabel(): string
    {
        return '発注データファイル';
    }

    public static function table(Table $table): Table
    {
        return WmsOrderDataFilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsOrderDataFiles::route('/'),
        ];
    }
}
