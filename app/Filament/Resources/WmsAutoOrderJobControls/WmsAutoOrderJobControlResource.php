<?php

namespace App\Filament\Resources\WmsAutoOrderJobControls;

use App\Enums\EMenu;
use App\Filament\Resources\WmsAutoOrderJobControls\Pages\ListWmsAutoOrderJobControls;
use App\Filament\Resources\WmsAutoOrderJobControls\Tables\WmsAutoOrderJobControlsTable;
use App\Models\WmsAutoOrderJobControl;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WmsAutoOrderJobControlResource extends Resource
{
    protected static ?string $model = WmsAutoOrderJobControl::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_AUTO_ORDER_JOBS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_AUTO_ORDER_JOBS->label();
    }

    public static function getModelLabel(): string
    {
        return 'ジョブ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'ジョブ履歴';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_AUTO_ORDER_JOBS->sort();
    }

    public static function table(Table $table): Table
    {
        return WmsAutoOrderJobControlsTable::configure($table);
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
            'index' => ListWmsAutoOrderJobControls::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
