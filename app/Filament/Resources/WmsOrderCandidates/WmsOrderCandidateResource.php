<?php

namespace App\Filament\Resources\WmsOrderCandidates;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\EMenu;
use App\Filament\Resources\WmsOrderCandidates\Pages\ListWmsOrderCandidates;
use App\Filament\Resources\WmsOrderCandidates\Tables\WmsOrderCandidatesTable;
use App\Filament\Support\AdminResource;
use App\Models\WmsOrderCandidate;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsOrderCandidateResource extends AdminResource
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
        $locationSub = \App\Models\Sakemaru\Location::query()
            ->from('real_stock_lots as rsl')
            ->join('real_stocks as rs', 'rs.id', '=', 'rsl.real_stock_id')
            ->join('locations', 'locations.id', '=', 'rsl.location_id')
            ->whereColumn('rs.warehouse_id', 'wms_order_candidates.warehouse_id')
            ->whereColumn('rs.item_id', 'wms_order_candidates.item_id')
            ->where('rsl.status', 'ACTIVE')
            ->where('rsl.current_quantity', '>', 0)
            ->orderByRaw('CASE WHEN rsl.current_quantity > rsl.reserved_quantity THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN rsl.expiration_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('rsl.expiration_date')
            ->orderBy('rsl.created_at')
            ->orderBy('rsl.id')
            ->selectRaw("CONCAT_WS('-', NULLIF(locations.code1, ''), NULLIF(locations.code2, ''), NULLIF(locations.code3, ''))")
            ->limit(1);

        return parent::getEloquentQuery()
            ->select('wms_order_candidates.*')
            ->selectSub($locationSub, 'default_location')
            ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::EXCLUDED])
            ->with(['item.current_price', 'contractor', 'modifiedByUser']);
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
