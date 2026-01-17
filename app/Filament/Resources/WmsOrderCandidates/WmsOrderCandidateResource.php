<?php

namespace App\Filament\Resources\WmsOrderCandidates;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\EMenu;
use App\Filament\Resources\WmsOrderCandidates\Pages\ListWmsOrderCandidates;
use App\Filament\Resources\WmsOrderCandidates\Tables\WmsOrderCandidatesTable;
use App\Models\WmsOrderCandidate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsOrderCandidateResource extends Resource
{
    protected static ?string $model = WmsOrderCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ORDER_CANDIDATES->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ORDER_CANDIDATES->label();
    }

    public static function getModelLabel(): string
    {
        return '発注候補';
    }

    public static function getPluralModelLabel(): string
    {
        return '発注候補';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ORDER_CANDIDATES->sort();
    }

    /**
     * 発注候補一覧では承認前（PENDING）と除外（EXCLUDED）のみ表示
     * 承認済み以降は「発注確定待ち」画面で管理
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::EXCLUDED]);
    }

    public static function table(Table $table): Table
    {
        return WmsOrderCandidatesTable::configure($table);
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
            'index' => ListWmsOrderCandidates::route('/'),
        ];
    }
}
