<?php

namespace App\Filament\Resources\WmsShortageAllocations;

use App\Filament\Resources\WmsShortageAllocations\Pages\CreateWmsShortageAllocation;
use App\Filament\Resources\WmsShortageAllocations\Pages\EditWmsShortageAllocation;
use App\Filament\Resources\WmsShortageAllocations\Pages\ListWmsShortageAllocations;
use App\Filament\Resources\WmsShortageAllocations\Schemas\WmsShortageAllocationForm;
use App\Filament\Resources\WmsShortageAllocations\Tables\WmsShortageAllocationsTable;
use App\Models\WmsShortageAllocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WmsShortageAllocationResource extends Resource
{
    protected static ?string $model = WmsShortageAllocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = '出荷管理';

    protected static ?string $navigationLabel = '移動出荷';

    protected static ?string $modelLabel = '移動出荷';

    protected static ?string $pluralModelLabel = '移動出荷一覧';

    protected static ?int $navigationSort = 13;

    public static function form(Schema $schema): Schema
    {
        return WmsShortageAllocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsShortageAllocationsTable::configure($table);
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
            'index' => ListWmsShortageAllocations::route('/'),
            'create' => CreateWmsShortageAllocation::route('/create'),
            'edit' => EditWmsShortageAllocation::route('/{record}/edit'),
        ];
    }
}
