<?php

namespace App\Filament\Resources\WmsItemSupplySettings;

use App\Enums\EMenu;
use App\Filament\Resources\WmsItemSupplySettings\Pages\CreateWmsItemSupplySetting;
use App\Filament\Resources\WmsItemSupplySettings\Pages\EditWmsItemSupplySetting;
use App\Filament\Resources\WmsItemSupplySettings\Pages\ListWmsItemSupplySettings;
use App\Filament\Resources\WmsItemSupplySettings\Schemas\WmsItemSupplySettingForm;
use App\Filament\Resources\WmsItemSupplySettings\Tables\WmsItemSupplySettingsTable;
use App\Models\WmsItemSupplySetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsItemSupplySettingResource extends Resource
{
    protected static ?string $model = WmsItemSupplySetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = '供給設定';

    protected static ?string $pluralModelLabel = '供給設定';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ITEM_SUPPLY_SETTINGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ITEM_SUPPLY_SETTINGS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ITEM_SUPPLY_SETTINGS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return WmsItemSupplySettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsItemSupplySettingsTable::configure($table);
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
            'index' => ListWmsItemSupplySettings::route('/'),
            'create' => CreateWmsItemSupplySetting::route('/create'),
            'edit' => EditWmsItemSupplySetting::route('/{record}/edit'),
        ];
    }
}
