<?php

namespace App\Filament\Resources\WmsContractorHolidays;

use App\Enums\EMenuCategory;
use App\Filament\Resources\WmsContractorHolidays\Pages\CreateWmsContractorHoliday;
use App\Filament\Resources\WmsContractorHolidays\Pages\EditWmsContractorHoliday;
use App\Filament\Resources\WmsContractorHolidays\Pages\ListWmsContractorHolidays;
use App\Filament\Resources\WmsContractorHolidays\Schemas\WmsContractorHolidayForm;
use App\Filament\Resources\WmsContractorHolidays\Tables\WmsContractorHolidaysTable;
use App\Models\WmsContractorHoliday;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsContractorHolidayResource extends Resource
{
    protected static ?string $model = WmsContractorHoliday::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $navigationLabel = '発注先休日';

    protected static ?string $modelLabel = '発注先休日';

    protected static ?string $pluralModelLabel = '発注先休日';

    protected static \UnitEnum|string|null $navigationGroup = EMenuCategory::MASTER_ORDER;

    public static function getNavigationGroup(): ?string
    {
        return self::$navigationGroup?->label();
    }

    public static function form(Schema $schema): Schema
    {
        return WmsContractorHolidayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsContractorHolidaysTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsContractorHolidays::route('/'),
            'create' => CreateWmsContractorHoliday::route('/create'),
            'edit' => EditWmsContractorHoliday::route('/{record}/edit'),
        ];
    }
}
