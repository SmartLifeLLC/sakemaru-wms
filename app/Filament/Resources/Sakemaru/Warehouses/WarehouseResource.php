<?php

namespace App\Filament\Resources\Sakemaru\Warehouses;

use App\Enums\EMenuCategory;
use App\Filament\Resources\Sakemaru\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Sakemaru\Warehouses\Pages\EditWarehouse;
use App\Filament\Resources\Sakemaru\Warehouses\Pages\ListWarehouses;
use App\Filament\Resources\Sakemaru\Warehouses\Schemas\WarehouseForm;
use App\Filament\Resources\Sakemaru\Warehouses\Tables\WarehousesTable;
use App\Models\Sakemaru\Warehouse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = '倉庫管理';

    protected static ?string $modelLabel = '倉庫';

    protected static ?string $pluralModelLabel = '倉庫';

    protected static \UnitEnum|string|null $navigationGroup = EMenuCategory::MASTER_WAREHOUSE;

    public static function getNavigationGroup(): ?string
    {
        return self::$navigationGroup?->label();
    }

    public static function form(Schema $schema): Schema
    {
        return WarehouseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WarehousesTable::configure($table);
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
            'index' => ListWarehouses::route('/'),
            'create' => CreateWarehouse::route('/create'),
            'edit' => EditWarehouse::route('/{record}/edit'),
        ];
    }
}
