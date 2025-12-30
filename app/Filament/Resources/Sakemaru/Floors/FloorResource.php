<?php

namespace App\Filament\Resources\Sakemaru\Floors;

use App\Enums\EMenuCategory;
use App\Filament\Resources\Sakemaru\Floors\Pages\CreateFloor;
use App\Filament\Resources\Sakemaru\Floors\Pages\EditFloor;
use App\Filament\Resources\Sakemaru\Floors\Pages\ListFloors;
use App\Filament\Resources\Sakemaru\Floors\Schemas\FloorForm;
use App\Filament\Resources\Sakemaru\Floors\Tables\FloorsTable;
use App\Models\Sakemaru\Floor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FloorResource extends Resource
{
    protected static ?string $model = Floor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'フロア管理';

    protected static ?string $modelLabel = 'フロア';

    protected static ?string $pluralModelLabel = 'フロア';

    protected static \UnitEnum|string|null $navigationGroup = EMenuCategory::MASTER_WAREHOUSE;

    public static function getNavigationGroup(): ?string
    {
        return self::$navigationGroup?->label();
    }

    public static function form(Schema $schema): Schema
    {
        return FloorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FloorsTable::configure($table);
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
            'index' => ListFloors::route('/'),
            'create' => CreateFloor::route('/create'),
            'edit' => EditFloor::route('/{record}/edit'),
        ];
    }
}
