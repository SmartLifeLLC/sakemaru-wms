<?php

namespace App\Filament\Resources\WmsShortagesApproved\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsShortagesApproved\WmsShortagesApprovedResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortage;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsShortagesApproved extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsShortagesApprovedResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $systemDate = ClientSetting::systemDateYMD();

        // 承認済み（is_confirmed=true）が存在する倉庫のみタブ表示（日付はユーザのフィルタ操作に委ねる）
        $warehouseIds = WmsShortage::where('is_confirmed', true)
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        $warehouses = Warehouse::whereIn('id', $warehouseIds)
            ->where('is_virtual', false)
            ->orderBy('name')
            ->get(['id', 'name']);

        $defaultFilterData = [
            'shipment_date' => ['shipment_date' => $systemDate],
        ];

        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        $views = [
            'default' => PresetView::make()
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse),
        ];

        if ($defaultWarehouse) {
            $views["default_{$defaultWarehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label($defaultWarehouse->name)
                ->default();
        }

        foreach ($warehouses as $warehouse) {
            if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }

            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'warehouse:id,code,name,latitude,longitude',
                    'location:id,code1,code2,code3',
                    'item:id,code,name,capacity_case,volume,volume_unit',
                    'trade:id,serial_id,partner_id',
                    'trade.partner:id,code,name,latitude,longitude',
                    'confirmedBy:id,name',
                    'confirmedUser:id,name',
                ])
                ->withSum('allocations as allocations_total_qty', 'assign_qty')
                ->withSum([
                    'allocations as allocations_case_qty' => function ($query) {
                        $query->where('assign_qty_type', 'CASE');
                    },
                ], 'assign_qty')
                ->withSum([
                    'allocations as allocations_piece_qty' => function ($query) {
                        $query->where('assign_qty_type', 'PIECE');
                    },
                ], 'assign_qty')
            )
            ->recordUrl(null);
    }
}
