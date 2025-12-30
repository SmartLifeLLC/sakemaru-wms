<?php

namespace App\Filament\Resources\WmsReceiptInspections;

use App\Enums\EMenu;
use App\Filament\Resources\WmsReceiptInspections\Pages\CreateWmsReceiptInspection;
use App\Filament\Resources\WmsReceiptInspections\Pages\EditWmsReceiptInspection;
use App\Filament\Resources\WmsReceiptInspections\Pages\ListWmsReceiptInspections;
use App\Filament\Resources\WmsReceiptInspections\Schemas\WmsReceiptInspectionForm;
use App\Filament\Resources\WmsReceiptInspections\Tables\WmsReceiptInspectionsTable;
use App\Models\WmsReceiptInspection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsReceiptInspectionResource extends Resource
{
    protected static ?string $model = WmsReceiptInspection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::RECEIPT_INSPECTIONS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::RECEIPT_INSPECTIONS->label();
    }

    public static function getModelLabel(): string
    {
        return '入荷検品';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::RECEIPT_INSPECTIONS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return WmsReceiptInspectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsReceiptInspectionsTable::configure($table);
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
            'index' => ListWmsReceiptInspections::route('/'),
            'create' => CreateWmsReceiptInspection::route('/create'),
            'edit' => EditWmsReceiptInspection::route('/{record}/edit'),
        ];
    }
}
