<?php

namespace App\Filament\Resources\WmsWarehouseCalendars;

use App\Enums\EMenuCategory;
use App\Filament\Resources\WmsWarehouseCalendars\Pages\CreateWmsWarehouseCalendar;
use App\Filament\Resources\WmsWarehouseCalendars\Pages\EditWmsWarehouseCalendar;
use App\Filament\Resources\WmsWarehouseCalendars\Pages\ListWmsWarehouseCalendars;
use App\Filament\Resources\WmsWarehouseCalendars\Schemas\WmsWarehouseCalendarForm;
use App\Filament\Resources\WmsWarehouseCalendars\Tables\WmsWarehouseCalendarsTable;
use App\Models\WmsWarehouseCalendar;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsWarehouseCalendarResource extends Resource
{
    protected static ?string $model = WmsWarehouseCalendar::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = '倉庫カレンダー';

    protected static ?string $modelLabel = '倉庫休日';

    protected static ?string $pluralModelLabel = '倉庫休日';

    protected static \UnitEnum|string|null $navigationGroup = EMenuCategory::MASTER_WAREHOUSE;

    public static function getNavigationGroup(): ?string
    {
        return self::$navigationGroup?->label();
    }

    public static function form(Schema $schema): Schema
    {
        return WmsWarehouseCalendarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WmsWarehouseCalendarsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsWarehouseCalendars::route('/'),
            'create' => CreateWmsWarehouseCalendar::route('/create'),
            'edit' => EditWmsWarehouseCalendar::route('/{record}/edit'),
        ];
    }
}
