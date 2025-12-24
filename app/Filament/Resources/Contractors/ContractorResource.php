<?php

namespace App\Filament\Resources\Contractors;

use App\Enums\EMenu;
use App\Filament\Resources\Contractors\Pages\CreateContractor;
use App\Filament\Resources\Contractors\Pages\EditContractor;
use App\Filament\Resources\Contractors\Pages\ListContractors;
use App\Filament\Resources\Contractors\RelationManagers\ContractorSuppliersRelationManager;
use App\Filament\Resources\Contractors\RelationManagers\WarehouseContractorSettingsRelationManager;
use App\Filament\Resources\Contractors\Schemas\ContractorForm;
use App\Filament\Resources\Contractors\Tables\ContractorsTable;
use App\Models\Sakemaru\Contractor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ContractorResource extends Resource
{
    protected static ?string $model = Contractor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = '発注先';

    protected static ?string $pluralModelLabel = '発注先';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::CONTRACTORS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::CONTRACTORS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::CONTRACTORS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return ContractorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContractorsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ContractorSuppliersRelationManager::class,
            WarehouseContractorSettingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContractors::route('/'),
            'create' => CreateContractor::route('/create'),
            'edit' => EditContractor::route('/{record}/edit'),
        ];
    }
}
