<?php

namespace App\Filament\Resources\WmsOrderConfirmed\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Filament\Resources\WmsOrderConfirmed\WmsOrderConfirmedResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCandidate;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListWmsOrderConfirmed extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderConfirmedResource::class;

    /**
     * プリセットビュー用倉庫データのキャッシュ
     */
    protected ?Collection $cachedWarehouses = null;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'warehouse',
                    'item',
                    'contractor',
                ])
                ->orderBy('batch_code', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $paginator = parent::paginateTableQuery($query);
        $items = $paginator->getCollection();

        if ($items->isNotEmpty()) {
            WmsOrderConfirmationWaitingTable::preloadItemContractorOrderSettings($items);
        }

        return $paginator;
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // 倉庫データをキャッシュから取得
        $warehouses = $this->getWarehousesForPresetViews();
        $warehouseIds = $warehouses->pluck('id')->toArray();

        // デフォルト倉庫が発注確定済みに存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        // プリセットビュー構築（キーはdefaultプレフィックスで統一）
        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
                    ->favorite()
                    ->label($defaultWarehouse->name)
                    ->default(),
            ];
        } else {
            $views = [
                'default' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        $views['all'] = PresetView::make()
            ->favorite()
            ->label('全て');

        $views['default_confirmed'] = PresetView::make()
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CandidateStatus::CONFIRMED))
            ->favorite()
            ->label('確定済み');

        $views['default_executed'] = PresetView::make()
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CandidateStatus::EXECUTED))
            ->favorite()
            ->label('送信済み');

        // 倉庫タブを追加（データがある場合のみ）
        foreach ($warehouses as $warehouse) {
            if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }
            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }

    /**
     * プリセットビュー用の倉庫データを取得（キャッシュ付き）
     */
    protected function getWarehousesForPresetViews(): Collection
    {
        if ($this->cachedWarehouses !== null) {
            return $this->cachedWarehouses;
        }

        $warehouseIds = WmsOrderCandidate::whereIn('status', [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED])
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        $this->cachedWarehouses = Warehouse::whereIn('id', $warehouseIds)
            ->orderBy('code')
            ->get();

        return $this->cachedWarehouses;
    }
}
