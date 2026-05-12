<?php

namespace App\Filament\Resources\WmsShortages\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsShortages\WmsShortageResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortage;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsShortages extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsShortageResource::class;

    protected static ?string $title = '';

    protected string $view = 'filament.resources.wms-shortages.pages.list-wms-shortages';

    protected ?array $presetViewsCache = null;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getPresetViews(): array
    {
        if ($this->presetViewsCache !== null) {
            return $this->presetViewsCache;
        }

        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;
        $systemDate = ClientSetting::systemDateYMD();

        // system_date の欠品が存在する倉庫のみタブ表示
        $warehouseIds = WmsShortage::where('shipment_date', $systemDate)
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        $warehouses = Warehouse::whereIn('id', $warehouseIds)
            ->where('is_virtual', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $defaultFilterData = [
            'shipment_date' => ['shipment_date' => $systemDate],
        ];
        $applySystemDate = fn (Builder $query) => $query->where('shipment_date', $systemDate);

        $defaultWarehouse = $userDefaultWarehouseId
            ? $warehouses->firstWhere('id', $userDefaultWarehouseId)
            : null;

        if ($defaultWarehouse) {
            $this->presetViewsCache = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $applySystemDate($query)
                        ->where('warehouse_id', $userDefaultWarehouseId))
                    ->defaultFilters($defaultFilterData)
                    ->favorite()
                    ->label($defaultWarehouse->name)
                    ->default(),
            ];
        } else {
            $this->presetViewsCache = [
                'default' => PresetView::make()
                    ->modifyQueryUsing($applySystemDate)
                    ->defaultFilters($defaultFilterData)
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        $this->presetViewsCache['all'] = PresetView::make()
            ->modifyQueryUsing($applySystemDate)
            ->defaultFilters($defaultFilterData)
            ->label('全て')
            ->favorite();

        foreach ($warehouses as $warehouse) {
            if ($defaultWarehouse && $warehouse->id === $defaultWarehouse->id) {
                continue;
            }
            $this->presetViewsCache["wh_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $applySystemDate($query)
                    ->where('warehouse_id', $warehouse->id))
                ->defaultFilters($defaultFilterData)
                ->favorite()
                ->label($warehouse->name);
        }

        return $this->presetViewsCache;
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
                    'deliveryCourse:id,code,name',
                    'earning:id,buyer_id',
                    'earning.buyer:id',
                    'earning.buyer.current_detail:id,buyer_id,salesman_id,start_date',
                    'earning.buyer.current_detail.salesman:id,name',
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
