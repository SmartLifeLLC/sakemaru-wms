<?php

namespace App\Filament\Resources\WarehouseContractors;

use App\Enums\EMenu;
use App\Filament\Resources\WarehouseContractors\Pages\CreateWarehouseContractor;
use App\Filament\Resources\WarehouseContractors\Pages\EditWarehouseContractor;
use App\Filament\Resources\WarehouseContractors\Pages\ListWarehouseContractors;
use App\Filament\Resources\WarehouseContractors\Schemas\WarehouseContractorForm;
use App\Filament\Resources\WarehouseContractors\Tables\WarehouseContractorsTable;
use App\Models\Sakemaru\WarehouseContractor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WarehouseContractorResource extends Resource
{
    protected static ?string $model = WarehouseContractor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WAREHOUSE_CONTRACTORS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WAREHOUSE_CONTRACTORS->label();
    }

    public static function getModelLabel(): string
    {
        return '発注先別ロット条件';
    }

    public static function getPluralModelLabel(): string
    {
        return '発注先別ロット条件';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WAREHOUSE_CONTRACTORS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return WarehouseContractorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WarehouseContractorsTable::configure($table);
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
            'index' => ListWarehouseContractors::route('/'),
            'create' => CreateWarehouseContractor::route('/create'),
            'edit' => EditWarehouseContractor::route('/{record}/edit'),
        ];
    }
}
