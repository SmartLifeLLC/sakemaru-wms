<?php

namespace App\Filament\Resources\WmsOrderConfirmed;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\EMenu;
use App\Filament\Resources\WmsOrderConfirmed\Pages\ListWmsOrderConfirmed;
use App\Filament\Resources\WmsOrderConfirmed\Tables\WmsOrderConfirmedTable;
use App\Models\WmsOrderCandidate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsOrderConfirmedResource extends Resource
{
    protected static ?string $model = WmsOrderCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $slug = 'wms-order-confirmed';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ORDER_CONFIRMED->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ORDER_CONFIRMED->label();
    }

    public static function getModelLabel(): string
    {
        return '発注確定済み';
    }

    public static function getPluralModelLabel(): string
    {
        return '発注確定済み';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ORDER_CONFIRMED->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        // 発注確定済み（CONFIRMED）と送信済み（EXECUTED）を表示
        return parent::getEloquentQuery()
            ->whereIn('status', [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED])
            ->with([
                'warehouse',
                'item',
                'contractor',
            ]);
    }

    public static function table(Table $table): Table
    {
        return WmsOrderConfirmedTable::configure($table);
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
            'index' => ListWmsOrderConfirmed::route('/'),
        ];
    }
}
