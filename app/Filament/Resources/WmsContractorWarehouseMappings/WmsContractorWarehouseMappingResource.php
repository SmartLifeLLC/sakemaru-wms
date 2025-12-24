<?php

namespace App\Filament\Resources\WmsContractorWarehouseMappings;

use App\Enums\EMenuCategory;
use App\Filament\Resources\WmsContractorWarehouseMappings\Pages\CreateWmsContractorWarehouseMapping;
use App\Filament\Resources\WmsContractorWarehouseMappings\Pages\EditWmsContractorWarehouseMapping;
use App\Filament\Resources\WmsContractorWarehouseMappings\Pages\ListWmsContractorWarehouseMappings;
use App\Filament\Resources\WmsContractorWarehouseMappings\Schemas\WmsContractorWarehouseMappingForm;
use App\Filament\Resources\WmsContractorWarehouseMappings\Tables\WmsContractorWarehouseMappingsTable;
use App\Models\WmsContractorWarehouseMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsContractorWarehouseMappingResource extends Resource
{
    protected static ?string $model = WmsContractorWarehouseMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = '発注先-倉庫マッピング';

    protected static ?string $pluralModelLabel = '発注先-倉庫マッピング';

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::MASTER_ORDER->label();
    }

    public static function getNavigationLabel(): string
    {
        return '発注先-倉庫マッピング';
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    public static function form(Schema $schema): Schema
    {
        return WmsContractorWarehouseMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsContractorWarehouseMappingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsContractorWarehouseMappings::route('/'),
            'create' => CreateWmsContractorWarehouseMapping::route('/create'),
            'edit' => EditWmsContractorWarehouseMapping::route('/{record}/edit'),
        ];
    }
}
