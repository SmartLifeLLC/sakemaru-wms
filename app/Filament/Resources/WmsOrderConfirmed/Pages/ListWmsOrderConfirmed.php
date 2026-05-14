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
                    'jxDocument',
                ])
                ->orderBy((new WmsOrderCandidate)->getTable().'.modified_at', 'desc')
                ->orderBy('batch_code', 'desc')
                ->orderBy((new WmsOrderCandidate)->getTable().'.warehouse_id')
                ->orderBy((new WmsOrderCandidate)->getTable().'.item_id')
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
        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $warehouses = $this->getWarehousesForPresetViews();
        $warehouseIds = $warehouses->pluck('id')->toArray();

        $hasSelectedWarehouse = $selectedWarehouseId && in_array($selectedWarehouseId, $warehouseIds);
        $selectedWarehouse = $hasSelectedWarehouse ? $warehouses->firstWhere('id', $selectedWarehouseId) : null;

        if ($selectedWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where((new WmsOrderCandidate)->getTable().'.warehouse_id', $selectedWarehouseId))
                    ->favorite()
                    ->label($selectedWarehouse->name)
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

        foreach ($warehouses as $warehouse) {
            if ($hasSelectedWarehouse && $warehouse->id === $selectedWarehouseId) {
                continue;
            }
            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where((new WmsOrderCandidate)->getTable().'.warehouse_id', $warehouse->id))
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
