<?php

namespace App\Filament\Resources\Sakemaru\Locations;

use App\Enums\EMenuCategory;
use App\Filament\Resources\Sakemaru\Locations\Pages\CreateLocation;
use App\Filament\Resources\Sakemaru\Locations\Pages\EditLocation;
use App\Filament\Resources\Sakemaru\Locations\Pages\ListLocations;
use App\Filament\Resources\Sakemaru\Locations\Schemas\LocationForm;
use App\Filament\Resources\Sakemaru\Locations\Tables\LocationsTable;
use App\Models\Sakemaru\Location;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'ロケーション管理';

    protected static ?string $modelLabel = 'ロケーション';

    protected static ?string $pluralModelLabel = 'ロケーション';

    protected static \UnitEnum|string|null $navigationGroup = EMenuCategory::MASTER;

    public static function getNavigationGroup(): ?string
    {
        return self::$navigationGroup?->label();
    }

    public static function form(Schema $schema): Schema
    {
        return LocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LocationsTable::configure($table);
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
            'index' => ListLocations::route('/'),
            'create' => CreateLocation::route('/create'),
            'edit' => EditLocation::route('/{record}/edit'),
        ];
    }
}
