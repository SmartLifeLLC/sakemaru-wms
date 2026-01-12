<?php

namespace App\Filament\Resources\WmsLocations;

use App\Enums\EMenu;
use App\Filament\Resources\WmsLocations\Pages\CreateWmsLocation;
use App\Filament\Resources\WmsLocations\Pages\EditWmsLocation;
use App\Filament\Resources\WmsLocations\Pages\ListWmsLocations;
use App\Filament\Resources\WmsLocations\Schemas\WmsLocationForm;
use App\Filament\Resources\WmsLocations\Tables\WmsLocationsTable;
use App\Models\WmsLocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsLocationResource extends Resource
{
    protected static ?string $model = WmsLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_LOCATIONS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_LOCATIONS->label();
    }

    public static function getModelLabel(): string
    {
        return 'WMSロケーション';
    }

    public static function getPluralModelLabel(): string
    {
        return 'WMSロケーション';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_LOCATIONS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return WmsLocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsLocationsTable::configure($table);
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
            'index' => ListWmsLocations::route('/'),
            'create' => CreateWmsLocation::route('/create'),
            'edit' => EditWmsLocation::route('/{record}/edit'),
        ];
    }
}
