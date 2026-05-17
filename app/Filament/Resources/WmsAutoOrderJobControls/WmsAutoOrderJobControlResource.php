<?php

namespace App\Filament\Resources\WmsAutoOrderJobControls;

use App\Enums\EMenu;
use App\Filament\Resources\WmsAutoOrderJobControls\Pages\ListWmsAutoOrderJobControls;
use App\Filament\Resources\WmsAutoOrderJobControls\Tables\WmsAutoOrderJobControlsTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsAutoOrderJobControl;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsAutoOrderJobControlResource extends AdminResource
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

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return '候補生成履歴';
    }

    public static function getPluralModelLabel(): string
    {
        return '発注・移動候補生成履歴';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_AUTO_ORDER_JOBS->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['createdByUser', 'warehouse']);
    }

    public static function table(Table $table): Table
    {
        return WmsAutoOrderJobControlsTable::configure($table);
    }

    public static function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\DateFilterWidget::class,
            \App\Filament\Widgets\OrderStatusWidget::class,
        ];
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
