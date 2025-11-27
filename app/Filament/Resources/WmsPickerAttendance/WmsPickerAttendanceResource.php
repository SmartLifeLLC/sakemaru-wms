<?php

namespace App\Filament\Resources\WmsPickerAttendance;

use App\Enums\EMenu;
use App\Filament\Resources\WmsPickerAttendance\Pages\ListWmsPickerAttendance;
use App\Filament\Resources\WmsPickerAttendance\Tables\WmsPickerAttendanceTable;
use App\Models\WmsPicker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsPickerAttendanceResource extends Resource
{
    protected static ?string $model = WmsPicker::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['defaultWarehouse', 'currentWarehouse']);
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = 'ピッカー勤怠管理';

    protected static ?string $pluralModelLabel = 'ピッカー勤怠管理';

    protected static ?string $slug = 'wms-picker-attendance';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_PICKER_ATTENDANCE->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_PICKER_ATTENDANCE->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_PICKER_ATTENDANCE->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsPickerAttendanceTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsPickerAttendance::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
