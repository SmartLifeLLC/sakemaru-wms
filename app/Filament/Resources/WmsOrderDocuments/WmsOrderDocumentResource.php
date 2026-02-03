<?php

namespace App\Filament\Resources\WmsOrderDocuments;

use App\Enums\EMenu;
use App\Filament\Resources\WmsOrderDocuments\Pages\ListWmsOrderDocuments;
use App\Filament\Resources\WmsOrderDocuments\Tables\WmsOrderDocumentsTable;
use App\Models\WmsOrderJxDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsOrderDocumentResource extends Resource
{
    protected static ?string $model = WmsOrderJxDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static ?string $slug = 'wms-order-documents';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ORDER_DOCUMENTS->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ORDER_DOCUMENTS->sort();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ORDER_DOCUMENTS->label();
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
        return WmsOrderDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsOrderDocuments::route('/'),
        ];
    }
}
