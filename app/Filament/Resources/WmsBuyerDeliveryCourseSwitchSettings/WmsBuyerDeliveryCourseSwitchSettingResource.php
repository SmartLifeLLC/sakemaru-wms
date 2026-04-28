<?php

namespace App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings;

use App\Enums\EMenu;
use App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Pages\CreateWmsBuyerDeliveryCourseSwitchSetting;
use App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Pages\EditWmsBuyerDeliveryCourseSwitchSetting;
use App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Pages\ListWmsBuyerDeliveryCourseSwitchSettings;
use App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Schemas\WmsBuyerDeliveryCourseSwitchSettingForm;
use App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Tables\WmsBuyerDeliveryCourseSwitchSettingsTable;
use App\Models\WmsBuyerDeliveryCourseSwitchSetting;
use BackedEnum;
use App\Filament\Support\AdminResource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsBuyerDeliveryCourseSwitchSettingResource extends AdminResource
{
    protected static ?string $model = WmsBuyerDeliveryCourseSwitchSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::DELIVERY_COURSE_SWITCH_SETTINGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::DELIVERY_COURSE_SWITCH_SETTINGS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::DELIVERY_COURSE_SWITCH_SETTINGS->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return WmsBuyerDeliveryCourseSwitchSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsBuyerDeliveryCourseSwitchSettingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsBuyerDeliveryCourseSwitchSettings::route('/'),
            'create' => CreateWmsBuyerDeliveryCourseSwitchSetting::route('/create'),
            'edit' => EditWmsBuyerDeliveryCourseSwitchSetting::route('/{record}/edit'),
        ];
    }
}
