<?php

namespace App\Filament\Resources\WmsContractorWarehouseSettings;

use App\Enums\EMenu;
use App\Filament\Resources\WmsContractorWarehouseSettings\Pages\CreateWmsContractorWarehouseSetting;
use App\Filament\Resources\WmsContractorWarehouseSettings\Pages\EditWmsContractorWarehouseSetting;
use App\Filament\Resources\WmsContractorWarehouseSettings\Pages\ListWmsContractorWarehouseSettings;
use App\Filament\Resources\WmsContractorWarehouseSettings\Schemas\WmsContractorWarehouseSettingForm;
use App\Filament\Resources\WmsContractorWarehouseSettings\Tables\WmsContractorWarehouseSettingsTable;
use App\Models\WmsContractorWarehouseSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsContractorWarehouseSettingResource extends Resource
{
    protected static ?string $model = WmsContractorWarehouseSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = '発注先指定倉庫コード';

    protected static ?string $pluralModelLabel = '発注先指定倉庫コード';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_CONTRACTOR_WAREHOUSE_SETTINGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_CONTRACTOR_WAREHOUSE_SETTINGS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_CONTRACTOR_WAREHOUSE_SETTINGS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return WmsContractorWarehouseSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsContractorWarehouseSettingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsContractorWarehouseSettings::route('/'),
            'create' => CreateWmsContractorWarehouseSetting::route('/create'),
            'edit' => EditWmsContractorWarehouseSetting::route('/{record}/edit'),
        ];
    }
}
