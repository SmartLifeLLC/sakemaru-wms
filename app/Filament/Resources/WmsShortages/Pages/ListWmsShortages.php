<?php

namespace App\Filament\Resources\WmsShortages\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsShortages\WmsShortageResource;
use Archilex\AdvancedTables\AdvancedTables;
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
        return [];
    }

    public function table(Table $table): Table
    {
        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();

        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->when($selectedWarehouseId, fn (Builder $query) => $query->where('warehouse_id', $selectedWarehouseId))
                ->where('shortage_qty', '>', 0)
                ->with([
                    'warehouse:id,code,name,latitude,longitude',
                    'wave:id,created_at',
                    'location:id,code1,code2,code3',
                    'item:id,code,name,capacity_case,volume,volume_unit',
                    'trade:id,serial_id,partner_id',
                    'trade.partner:id,code,name,latitude,longitude',
                    'deliveryCourse:id,code,name',
                    'sourcePickResult:id,picking_task_id,stock_transfer_id',
                    'sourcePickResult.pickingTask:id,shipment_date,delivery_course_id',
                    'sourcePickResult.pickingTask.deliveryCourse:id,code,name',
                    'sourcePickResult.stockTransfer:id,from_warehouse_id,delivery_course_id',
                    'sourcePickResult.stockTransfer.from_warehouse:id,code,name',
                    'sourcePickResult.stockTransfer.deliveryCourse:id,code,name',
                    'earning:id,buyer_id,delivered_date',
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
