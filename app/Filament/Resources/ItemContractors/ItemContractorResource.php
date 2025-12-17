<?php

namespace App\Filament\Resources\ItemContractors;

use App\Enums\EMenu;
use App\Filament\Resources\ItemContractors\Pages\CreateItemContractor;
use App\Filament\Resources\ItemContractors\Pages\EditItemContractor;
use App\Filament\Resources\ItemContractors\Pages\ListItemContractors;
use App\Filament\Resources\ItemContractors\Schemas\ItemContractorForm;
use App\Filament\Resources\ItemContractors\Tables\ItemContractorsTable;
use App\Models\Sakemaru\ItemContractor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ItemContractorResource extends Resource
{
    protected static ?string $model = ItemContractor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = '商品発注先';

    protected static ?string $pluralModelLabel = '商品発注先';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::ITEM_CONTRACTORS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::ITEM_CONTRACTORS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::ITEM_CONTRACTORS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return ItemContractorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ItemContractorsTable::configure($table);
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
            'index' => ListItemContractors::route('/'),
            'create' => CreateItemContractor::route('/create'),
            'edit' => EditItemContractor::route('/{record}/edit'),
        ];
    }
}
