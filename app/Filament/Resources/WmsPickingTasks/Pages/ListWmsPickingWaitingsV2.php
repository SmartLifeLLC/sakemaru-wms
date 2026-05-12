<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingTasks\Tables\WmsPickingWaitingsV2Table;
use App\Filament\Resources\WmsPickingTasks\WmsPickingWaitingV2Resource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsPickingWaitingsV2 extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickingWaitingV2Resource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openVersion1')
                ->label('通常版')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->url('/admin/wms-picking-waitings'),
        ];
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $base = 'ピッキング調整 v2';
        $warehouseName = $this->getSelectedWarehouseName();

        return $warehouseName ? "{$base} ({$warehouseName})" : $base;
    }

    public function getPresetViews(): array
    {
        $userWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $defaultFilterData = $userWarehouseId
            ? ['warehouse_id' => ['value' => (string) $userWarehouseId]]
            : [];
        $systemDate = ClientSetting::systemDateYMD();

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('shipment_date', $systemDate))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('当日')
                ->default(),
            'with_shortage' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('shipment_date', $systemDate)
                    ->whereHas('pickingItemResults', fn ($q) => $q->where('has_soft_shortage', true)))
                ->defaultFilters($defaultFilterData)
                ->label('引当欠品あり')
                ->favorite(),
        ];
    }

    private function getSelectedWarehouseName(): ?string
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        return $warehouseId ? Warehouse::find($warehouseId)?->name : null;
    }

    public function table(Table $table): Table
    {
        return WmsPickingWaitingsV2Table::configure($table);
    }
}
