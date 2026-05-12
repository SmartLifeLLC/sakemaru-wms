<?php

namespace App\Filament\Resources\WmsPickingTasks;

use App\Enums\PaginationOptions;
use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingItemEditsV2;
use App\Filament\Support\AdminResource;
use App\Models\Sakemaru\Partner;
use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsPickingItemEditV2Resource extends AdminResource
{
    protected static ?string $model = WmsPickingItemResult::class;

    protected static string $permissionResource = 'wms-picking-item-edit';

    protected static ?string $slug = 'wms-picking-item-edit-v2';

    protected static bool $shouldRegisterNavigation = false;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query, $livewire) {
                $warehouseId = $livewire->warehouseId ?? request()->input('warehouse_id');
                $deliveryCourseId = $livewire->deliveryCourseId ?? request()->input('delivery_course_id');
                $shipmentDate = $livewire->shipmentDate ?? request()->input('shipment_date');

                $query
                    ->join('wms_picking_tasks as pt', 'pt.id', '=', 'wms_picking_item_results.picking_task_id')
                    ->leftJoin('locations as l', 'l.id', '=', 'wms_picking_item_results.location_id')
                    ->leftJoin('floors as f', 'f.id', '=', 'l.floor_id')
                    ->leftJoin('items as sort_items', 'sort_items.id', '=', 'wms_picking_item_results.item_id')
                    ->leftJoin('earnings as e', 'e.id', '=', 'wms_picking_item_results.earning_id')
                    ->leftJoin('stock_transfers as st', 'st.id', '=', 'wms_picking_item_results.stock_transfer_id')
                    ->select('wms_picking_item_results.*')
                    ->selectRaw('COALESCE(l.floor_id, pt.floor_id) as list_floor_id')
                    ->selectRaw('f.name as list_floor_name')
                    ->selectRaw('l.code1 as list_location_code1')
                    ->selectRaw('l.code2 as list_location_code2')
                    ->selectRaw('l.code3 as list_location_code3')
                    ->selectRaw('sort_items.code as list_item_code')
                    ->selectRaw('COALESCE(e.id, st.id, 0) as list_source_id')
                    ->with([
                        'pickingTask.deliveryCourse',
                        'pickingTask.warehouse',
                        'trade',
                        'earning.buyer.partner',
                        'stockTransfer.to_warehouse',
                        'item',
                        'location.floor',
                    ])
                    ->whereIn('pt.status', [
                        WmsPickingTask::STATUS_PENDING,
                        WmsPickingTask::STATUS_PICKING_READY,
                    ])
                    ->when($warehouseId, fn (Builder $q) => $q->where('pt.warehouse_id', $warehouseId))
                    ->when($deliveryCourseId, fn (Builder $q) => $q->where('pt.delivery_course_id', $deliveryCourseId))
                    ->when($shipmentDate, fn (Builder $q) => $q->whereDate('pt.shipment_date', $shipmentDate))
                    ->where(function ($q) {
                        $q->where('wms_picking_item_results.planned_qty', '>', 0)
                            ->orWhere('wms_picking_item_results.shortage_qty', '>', 0);
                    })
                    ->where(function ($q) {
                        $q->whereNull('wms_picking_item_results.is_ready_to_shipment')
                            ->orWhere('wms_picking_item_results.is_ready_to_shipment', false);
                    })
                    ->orderByRaw(static::pickingListAreaOrderSql())
                    ->orderByRaw("CASE WHEN l.code1 LIKE 'Y%' THEN l.code1 END DESC")
                    ->orderByRaw("CASE WHEN l.code1 LIKE 'Y%' THEN NULL ELSE COALESCE(l.floor_id, pt.floor_id, 999999) END")
                    ->orderByRaw("CASE WHEN l.code1 LIKE 'Y%' THEN NULL ELSE COALESCE(l.code1, 'ZZZ') END")
                    ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
                    ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
                    ->orderBy('sort_items.code')
                    ->orderByRaw('COALESCE(e.id, st.id, 0)')
                    ->orderBy('wms_picking_item_results.id');
            })
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->striped()
            ->columns([
                TextColumn::make('picking_task_id')
                    ->label('タスクID')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('list_floor_name')
                    ->label('フロア')
                    ->state(fn ($record) => $record->location?->floor?->name ?? $record->list_floor_name ?? '-')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('list_floor_id', $direction)),

                TextColumn::make('location_code')
                    ->label('ロケーション')
                    ->state(fn ($record) => $record->location?->joined_location ?? '-')
                    ->sortable(query: fn (Builder $query, string $direction) => $query
                        ->orderBy('list_location_code1', $direction)
                        ->orderBy('list_location_code2', $direction)
                        ->orderBy('list_location_code3', $direction)),

                TextColumn::make('trade.serial_id')
                    ->label('伝票番号')
                    ->alignCenter()
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('destination')
                    ->label('得意先/移動先')
                    ->state(function ($record): string {
                        if ($record->stock_transfer_id) {
                            return '[移動] '.($record->stockTransfer?->to_warehouse?->name ?? '-');
                        }

                        return $record->earning?->buyer?->partner?->name ?? '-';
                    })
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->where(function ($q) use ($search) {
                            $q->whereHas('earning.buyer.partner', fn ($subQ) => $subQ->where('name', 'like', "%{$search}%"))
                                ->orWhereHas('stockTransfer.to_warehouse', fn ($subQ) => $subQ->where('name', 'like', "%{$search}%"));
                        });
                    }),

                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->sortable()
                    ->searchable()
                    ->grow(),

                TextColumn::make('ordered_qty_case')
                    ->label('ケース')
                    ->state(fn ($record) => $record->ordered_qty_type === 'CASE' ? $record->ordered_qty : '-')
                    ->alignment('center'),

                TextColumn::make('ordered_qty_piece')
                    ->label('バラ')
                    ->state(fn ($record) => $record->ordered_qty_type === 'PIECE' ? $record->ordered_qty : '-')
                    ->alignment('center'),

                TextInputColumn::make('planned_qty')
                    ->width('50px')
                    ->label('引当数')
                    ->rules(['required', 'numeric', 'min:0'])
                    ->type('number')
                    ->step(1)
                    ->alignCenter()
                    ->disabled(fn ($record) => ! $record->pickingTask || ! in_array($record->pickingTask->status, [
                        WmsPickingTask::STATUS_PENDING,
                        WmsPickingTask::STATUS_PICKING_READY,
                    ]))
                    ->extraCellAttributes(['class' => 'p-0'])
                    ->extraInputAttributes([
                        'class' => 'w-16 !h-7 !p-0 text-center border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 border focus:border-primary-500 focus:ring-primary-500 !text-xs',
                        'min' => '0',
                        'step' => '1',
                        'inputmode' => 'numeric',
                        'pattern' => '[0-9]*',
                    ])
                    ->afterStateUpdated(function ($record, $state) {
                        if (! is_numeric($state) || $state < 0) {
                            Notification::make()->title('エラー')->body('有効な数値を入力してください')->danger()->send();

                            return;
                        }

                        $newPlannedQty = (int) $state;
                        if ($newPlannedQty > $record->ordered_qty) {
                            Notification::make()->title('エラー')->body("引当数は受注数量（{$record->ordered_qty}）を超えることはできません")->danger()->send();

                            return;
                        }

                        $record->planned_qty = $newPlannedQty;
                        if ($record->picked_qty > $newPlannedQty) {
                            $record->picked_qty = $newPlannedQty;
                        }
                        $record->shortage_qty = max(0, $newPlannedQty - $record->picked_qty);
                        $record->save();

                        Notification::make()->title('引当数を更新しました')->success()->send();
                    }),

                TextInputColumn::make('picked_qty')
                    ->width('50px')
                    ->label('ピック数')
                    ->rules(['required', 'numeric', 'min:0'])
                    ->type('number')
                    ->step(1)
                    ->alignCenter()
                    ->disabled(fn ($record) => ! $record->pickingTask || ! in_array($record->pickingTask->status, [
                        WmsPickingTask::STATUS_PICKING_READY,
                        WmsPickingTask::STATUS_PICKING,
                        WmsPickingTask::STATUS_COMPLETED,
                    ]))
                    ->extraCellAttributes(['class' => 'p-0'])
                    ->extraInputAttributes([
                        'class' => 'w-16 !h-7 !p-0 text-center border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 border focus:border-primary-500 focus:ring-primary-500 !text-xs',
                        'min' => '0',
                        'step' => '1',
                        'inputmode' => 'numeric',
                        'pattern' => '[0-9]*',
                    ])
                    ->afterStateUpdated(function ($record, $state) {
                        if (! is_numeric($state) || $state < 0) {
                            Notification::make()->title('エラー')->body('有効な数値を入力してください')->danger()->send();

                            return;
                        }

                        if ($state > $record->planned_qty) {
                            Notification::make()->title('エラー')->body("ピック数は引当数（{$record->planned_qty}）を超えることはできません")->danger()->send();

                            return;
                        }

                        $record->picked_qty = (int) $state;
                        $record->shortage_qty = max(0, $record->planned_qty - (int) $state);
                        $record->save();

                        Notification::make()->title('ピック数を更新しました')->success()->send();
                    }),

                TextColumn::make('shortage_qty')
                    ->label('欠品数')
                    ->state(fn ($record) => max(0, $record->ordered_qty - $record->planned_qty))
                    ->alignment('center')
                    ->color(fn ($record) => ($record->ordered_qty - $record->planned_qty) > 0 ? 'danger' : 'success')
                    ->weight(fn ($record) => ($record->ordered_qty - $record->planned_qty) > 0 ? 'bold' : 'normal')
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state : '-'),
            ])
            ->filters([
                SelectFilter::make('partner')
                    ->label('得意先')
                    ->options(function () {
                        $warehouseId = request()->input('warehouse_id');
                        $deliveryCourseId = request()->input('delivery_course_id');
                        $shipmentDate = request()->input('shipment_date');

                        return Partner::query()
                            ->join('buyers as b', 'b.partner_id', '=', 'partners.id')
                            ->join('earnings as e', 'e.buyer_id', '=', 'b.id')
                            ->join('wms_picking_item_results as pir', 'pir.earning_id', '=', 'e.id')
                            ->join('wms_picking_tasks as pt', 'pt.id', '=', 'pir.picking_task_id')
                            ->when($warehouseId, fn (Builder $q) => $q->where('pt.warehouse_id', $warehouseId))
                            ->when($deliveryCourseId, fn (Builder $q) => $q->where('pt.delivery_course_id', $deliveryCourseId))
                            ->when($shipmentDate, fn (Builder $q) => $q->whereDate('pt.shipment_date', $shipmentDate))
                            ->select('partners.id', 'partners.name', 'partners.code')
                            ->orderBy('partners.code')
                            ->distinct()
                            ->get()
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->query(function (Builder $query, $state) {
                        if (! empty($state['value'])) {
                            $query->whereHas('earning.buyer.partner', fn ($q) => $q->where('id', $state['value']));
                        }

                        return $query;
                    }),

                SelectFilter::make('item')
                    ->label('商品')
                    ->relationship('item', 'name')
                    ->searchable()
                    ->preload(),
            ]);
    }

    private static function pickingListAreaOrderSql(): string
    {
        return <<<'SQL'
CASE
    WHEN l.code1 LIKE 'Y%' THEN 3
    WHEN f.name LIKE '%1F%' THEN 1
    WHEN f.name LIKE '%2F%' THEN 2
    ELSE 4
END
SQL;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsPickingItemEditsV2::route('/'),
        ];
    }
}
