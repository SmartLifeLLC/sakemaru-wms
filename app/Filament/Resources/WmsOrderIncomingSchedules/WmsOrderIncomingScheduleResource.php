<?php

namespace App\Filament\Resources\WmsOrderIncomingSchedules;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\EMenu;
use App\Filament\Resources\WmsOrderIncomingSchedules\Pages\ListWmsOrderIncomingSchedules;
use App\Filament\Resources\WmsOrderIncomingSchedules\Tables\WmsOrderIncomingSchedulesTable;
use App\Models\WmsOrderIncomingSchedule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsOrderIncomingScheduleResource extends Resource
{
    protected static ?string $model = WmsOrderIncomingSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ORDER_INCOMING_SCHEDULES->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ORDER_INCOMING_SCHEDULES->label();
    }

    public static function getModelLabel(): string
    {
        return '入庫予定';
    }

    public static function getPluralModelLabel(): string
    {
        return '入庫予定';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ORDER_INCOMING_SCHEDULES->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        // 未入庫（PENDING）と一部入庫（PARTIAL）のみ表示
        return parent::getEloquentQuery()
            ->whereIn('status', [IncomingScheduleStatus::PENDING, IncomingScheduleStatus::PARTIAL])
            ->with([
                'warehouse',
                'item',
                'contractor',
                'supplier',
            ]);
    }

    public static function table(Table $table): Table
    {
        return WmsOrderIncomingSchedulesTable::configure($table);
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
            'index' => ListWmsOrderIncomingSchedules::route('/'),
        ];
    }
}
