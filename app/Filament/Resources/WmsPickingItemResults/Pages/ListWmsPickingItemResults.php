<?php

namespace App\Filament\Resources\WmsPickingItemResults\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingItemResults\WmsPickingItemResultResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListWmsPickingItemResults extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickingItemResultResource::class;

    public function getTitle(): string|Htmlable
    {
        $base = 'ピッキング商品リスト';
        $warehouseName = $this->getSelectedWarehouseName();

        return $warehouseName ? "{$base} ({$warehouseName})" : $base;
    }

    public function table(Table $table): Table
    {
        return WmsPickingItemResultResource::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'pickingTask',
                'earning.buyer.partner',
                'earning.warehouse',
                'earning.delivery_course',
                'trade',
                'item',
                'location',
            ]));
    }

    public function getPresetViews(): array
    {
        $systemDate = ClientSetting::systemDate();
        $userWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $defaultFilterData = $userWarehouseId
            ? ['warehouse_id' => ['value' => (string) $userWarehouseId]]
            : [];

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas(
                    'pickingTask',
                    fn (Builder $q) => $q->whereDate('shipment_date', $systemDate)
                ))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('当日')
                ->default(),

            'pending' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('pickingTask', fn (Builder $q) => $q->whereDate('shipment_date', $systemDate))
                    ->where('status', 'PENDING'))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('未着手'),

            'picking' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('pickingTask', fn (Builder $q) => $q->whereDate('shipment_date', $systemDate))
                    ->where('status', 'PICKING'))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('ピッキング中'),

            'completed' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('pickingTask', fn (Builder $q) => $q->whereDate('shipment_date', $systemDate))
                    ->where('status', 'COMPLETED'))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('完了'),

            'has_shortage' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('pickingTask', fn (Builder $q) => $q->whereDate('shipment_date', $systemDate))
                    ->where('shortage_qty', '>', 0))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('欠品あり'),
        ];
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
