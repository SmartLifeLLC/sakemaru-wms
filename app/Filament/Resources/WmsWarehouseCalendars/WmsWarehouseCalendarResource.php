<?php

namespace App\Filament\Resources\WmsWarehouseCalendars;

use App\Enums\EMenu;
use App\Filament\Resources\WmsWarehouseCalendars\Pages\CreateWmsWarehouseCalendar;
use App\Filament\Resources\WmsWarehouseCalendars\Pages\ListWmsWarehouseCalendars;
use App\Filament\Resources\WmsWarehouseCalendars\Schemas\WmsWarehouseCalendarForm;
use App\Filament\Resources\WmsWarehouseCalendars\Tables\WmsWarehouseCalendarsTable;
use App\Models\WmsWarehouseCalendar;
use BackedEnum;
use App\Filament\Support\AdminResource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class WmsWarehouseCalendarResource extends AdminResource
{
    protected static ?string $model = WmsWarehouseCalendar::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $modelLabel = '倉庫休日';

    protected static ?string $pluralModelLabel = '倉庫休日';

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_WAREHOUSE_CALENDARS->label();
    }

    public static function getNavigationIcon(): ?string
    {
        return EMenu::WMS_WAREHOUSE_CALENDARS->icon();
    }

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_WAREHOUSE_CALENDARS->category()?->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_WAREHOUSE_CALENDARS->sort();
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
        ];
    }
}
