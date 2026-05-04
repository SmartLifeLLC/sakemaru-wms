<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingTasks\Tables\WmsPickingTasksTable;
use App\Filament\Resources\WmsPickingTasks\WmsPickingTaskResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListWmsPickingTasks extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickingTaskResource::class;

    public function getTitle(): string|Htmlable
    {
        $base = 'ピッキング作業一覧';
        $warehouseName = $this->getSelectedWarehouseName();

        return $warehouseName ? "{$base} ({$warehouseName})" : $base;
    }

    public function getPresetViews(): array
    {
        $userWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $defaultFilterData = $userWarehouseId
            ? ['warehouse_id' => ['value' => (string) $userWarehouseId]]
            : [];

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'PICKING_READY'))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('ピッキング前')
                ->default(),
            'PICKING' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'PICKING'))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('ピッキング中'),
            'SHORTAGE' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn ($q) => $q
                    ->whereHas('pickingItemResults', fn ($sq) => $sq->where('has_soft_shortage', true))
                    ->orWhere('status', 'SHORTAGE')))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('欠品対応待ち'),
            'COMPLETED_TODAY' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'COMPLETED')->whereDate('shipment_date', ClientSetting::systemDateYMD()))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('ピッキング完了(本日出荷)'),
            'COMPLETED_ALL' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'COMPLETED'))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('ピッキング完了(すべて)'),
        ];
    }

    public function table(Table $table): Table
    {
        return WmsPickingTasksTable::configure($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'floor',
                'warehouse',
                'deliveryCourse',
                'picker',
                'pickingItemResults.trade',
                'pickingItemResults.earning.buyer.partner',
            ])
                ->withCount('pickingItemResults')
                ->withCount(['pickingItemResults as soft_shortage_count' => function ($q) {
                    $q->where('has_soft_shortage', true);
                }])
            );
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    private function getSelectedWarehouseName(): ?string
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        return $warehouseId ? Warehouse::find($warehouseId)?->name : null;
    }
}
