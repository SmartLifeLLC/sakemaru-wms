<?php

namespace App\Filament\Resources\WmsIncomingTransmitted;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\EMenu;
use App\Filament\Resources\WmsIncomingTransmitted\Pages\ListWmsIncomingTransmitted;
use App\Filament\Resources\WmsIncomingTransmitted\Tables\WmsIncomingTransmittedTable;
use App\Models\WmsOrderIncomingSchedule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsIncomingTransmittedResource extends Resource
{
    protected static ?string $model = WmsOrderIncomingSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowUp;

    protected static ?string $slug = 'wms-incoming-transmitted';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_INCOMING_TRANSMITTED->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_INCOMING_TRANSMITTED->label();
    }

    public static function getModelLabel(): string
    {
        return '仕入連携済み';
    }

    public static function getPluralModelLabel(): string
    {
        return '仕入連携済み';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_INCOMING_TRANSMITTED->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        // 連携済み（TRANSMITTED）のみ表示
        return parent::getEloquentQuery()
            ->where('status', IncomingScheduleStatus::TRANSMITTED)
            ->with([
                'warehouse',
                'item',
                'contractor',
                'supplier',
            ]);
    }

    public static function table(Table $table): Table
    {
        return WmsIncomingTransmittedTable::configure($table);
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
            'index' => ListWmsIncomingTransmitted::route('/'),
        ];
    }
}
