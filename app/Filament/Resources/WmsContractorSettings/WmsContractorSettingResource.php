<?php

namespace App\Filament\Resources\WmsContractorSettings;

use App\Filament\Resources\WmsContractorSettings\Pages\CreateWmsContractorSetting;
use App\Filament\Resources\WmsContractorSettings\Pages\EditWmsContractorSetting;
use App\Filament\Resources\WmsContractorSettings\Pages\ListWmsContractorSettings;
use App\Filament\Resources\WmsContractorSettings\Schemas\WmsContractorSettingForm;
use App\Filament\Resources\WmsContractorSettings\Tables\WmsContractorSettingsTable;
use App\Models\WmsContractorSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsContractorSettingResource extends Resource
{
    protected static ?string $model = WmsContractorSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'contractor-settings';

    protected static ?string $navigationLabel = '発注先設定';

    protected static ?string $modelLabel = '発注先設定';

    protected static ?string $pluralModelLabel = '発注先設定';

    public static function form(Schema $schema): Schema
    {
        return WmsContractorSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsContractorSettingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsContractorSettings::route('/'),
            'create' => CreateWmsContractorSetting::route('/create'),
            'edit' => EditWmsContractorSetting::route('/{record}/edit'),
        ];
    }
}
