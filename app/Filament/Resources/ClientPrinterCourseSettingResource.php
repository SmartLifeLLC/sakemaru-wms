<?php

namespace App\Filament\Resources;

use App\Enums\EMenu;
use App\Filament\Resources\ClientPrinterCourseSettingResource\Pages;
use App\Filament\Resources\ClientPrinterCourseSettingResource\Schemas\ClientPrinterCourseSettingForm;
use App\Filament\Resources\ClientPrinterCourseSettingResource\Tables\ClientPrinterCourseSettingsTable;
use App\Models\Sakemaru\ClientPrinterCourseSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ClientPrinterCourseSettingResource extends Resource
{
    protected static ?string $model = ClientPrinterCourseSetting::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-printer';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::CLIENT_PRINTER_COURSE_SETTINGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::CLIENT_PRINTER_COURSE_SETTINGS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::CLIENT_PRINTER_COURSE_SETTINGS->sort();
    }

    public static function getModelLabel(): string
    {
        return 'プリンター設定';
    }

    public static function getPluralModelLabel(): string
    {
        return 'プリンター設定';
    }

    public static function form(Schema $schema): Schema
    {
        return ClientPrinterCourseSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientPrinterCourseSettingsTable::configure($table);
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
            'index' => Pages\ListClientPrinterCourseSettings::route('/'),
            'create' => Pages\CreateClientPrinterCourseSetting::route('/create'),
            'edit' => Pages\EditClientPrinterCourseSetting::route('/{record}/edit'),
        ];
    }
}
