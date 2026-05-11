<?php

namespace App\Filament\Resources\WmsPickingTasks;

use App\Enums\PaginationOptions;
use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingItemEdits;
use App\Filament\Support\AdminResource;
use App\Models\Sakemaru\Partner;
use App\Models\Sakemaru\RealStock;
use App\Models\WmsPickingItemResult;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsPickingItemEditResource extends AdminResource
{
    protected static ?string $model = WmsPickingItemResult::class;

    protected static ?string $slug = 'wms-picking-item-edit';

    // Hide from navigation menu
    protected static bool $shouldRegisterNavigation = false;

    public static function table(Table $table): Table
    {

        return $table
            ->modifyQueryUsing(function (Builder $query, $livewire) {
                // リクエストから値を取得
                // Pageクラスのプロパティを参照
                // ※ $livewireがListWmsPickingItemEditsのインスタンスか念のためチェックしても良い
                if (isset($livewire->pickingTaskId) && filled($livewire->pickingTaskId)) {
                    $query->where('picking_task_id', $livewire->pickingTaskId);
                }

                $query->with([
                    'pickingTask.wave',
                    'trade',
                    'earning.buyer.partner',
                    'item',
                    'location',
                ])
                    ->addSelect([
                        'stock_location_display' => RealStock::query()
                            ->join('real_stock_lots as rsl', function ($join) {
                                $join->on('rsl.real_stock_id', '=', 'real_stocks.id')
                                    ->where('rsl.status', '=', 'ACTIVE')
                                    ->where('rsl.current_quantity', '<>', 0);
                            })
                            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
                            ->selectRaw("TRIM(CONCAT_WS(' ', l.code1, l.code2, l.code3))")
                            ->whereColumn('real_stocks.item_id', 'wms_picking_item_results.item_id')
                            ->whereRaw('real_stocks.warehouse_id = (
                                select warehouse_id
                                from wms_picking_tasks
                                where wms_picking_tasks.id = wms_picking_item_results.picking_task_id
                                limit 1
                            )')
                            ->orderByRaw('rsl.expiration_date IS NULL')
                            ->orderBy('rsl.expiration_date')
                            ->orderBy('rsl.created_at')
                            ->orderBy('rsl.id')
                            ->limit(1),
                        'real_stock_current_quantity' => RealStock::query()
                            ->selectRaw('COALESCE(SUM(current_quantity), 0)')
                            ->whereColumn('real_stocks.item_id', 'wms_picking_item_results.item_id')
                            ->whereRaw('real_stocks.warehouse_id = (
                                select warehouse_id
                                from wms_picking_tasks
                                where wms_picking_tasks.id = wms_picking_item_results.picking_task_id
                                limit 1
                            )'),
                    ]);
            })
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->striped()
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->alignCenter(),
                TextColumn::make('pickingTask.wave.id')->label('wave id'),
                TextColumn::make('location_display')
                    ->label('棚番')
                    ->state(function ($record) {
                        $allocatedLocation = $record->location_display;

                        return $allocatedLocation !== '-' ? $allocatedLocation : ($record->stock_location_display ?: '-');
                    })
                    ->default('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('trade.serial_id')
                    ->label('伝票番号')
                    ->alignCenter()
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('earning.buyer.partner.code')
                    ->label('得意先CD')
                    ->sortable()
                    ->searchable()
                    ->default('-')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('earning.buyer.partner.name')
                    ->label('得意先名')
                    ->sortable()
                    ->searchable()
                    ->default('-'),
                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('item.name')
                    ->label('商品名')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('real_stock_current_quantity')
                    ->label('総バラ数在庫')
                    ->numeric()
                    ->alignCenter()
                    ->default(0)
                    ->toggleable(isToggledHiddenByDefault: false),
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
                        \App\Models\WmsPickingTask::STATUS_PENDING,
                        \App\Models\WmsPickingTask::STATUS_PICKING_READY,
                    ]))
                    ->extraCellAttributes([
                        'class' => 'p-0',
                    ])
                    ->extraInputAttributes([
                        'class' => 'w-16 !h-7 !p-0 text-center border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 border focus:border-primary-500 focus:ring-primary-500 !text-xs',
                        'min' => '0',
                        'step' => '1',
                        'inputmode' => 'numeric',
                        'pattern' => '[0-9]*',
                    ])
                    ->afterStateUpdated(function ($record, $state) {
                        if (! is_numeric($state) || $state < 0) {
                            Notification::make()
                                ->title('エラー')
                                ->body('有効な数値を入力してください')
                                ->danger()
                                ->send();

                            return;
                        }

                        $newPlannedQty = (int) $state;

                        if ($newPlannedQty > $record->ordered_qty) {
                            Notification::make()
                                ->title('エラー')
                                ->body("引当数は受注数量（{$record->ordered_qty}）を超えることはできません")
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->planned_qty = $newPlannedQty;
                        // picked_qtyがplanned_qtyを超える場合は調整
                        if ($record->picked_qty > $newPlannedQty) {
                            $record->picked_qty = $newPlannedQty;
                        }
                        $record->shortage_qty = max(0, $newPlannedQty - $record->picked_qty);
                        $record->save();

                        Notification::make()
                            ->title('引当数を更新しました')
                            ->success()
                            ->send();
                    }),

                TextInputColumn::make('picked_qty')
                    ->width('50px')
                    ->label('ピック数')
                    ->rules(['required', 'numeric', 'min:0'])
                    ->type('number')
                    ->step(1)
                    ->alignCenter()
                    ->disabled(fn ($record) => ! $record->pickingTask || ! in_array($record->pickingTask->status, [
                        \App\Models\WmsPickingTask::STATUS_PICKING_READY,
                        'PICKING',
                        'COMPLETED',
                    ]))
                    ->extraCellAttributes([
                        'class' => 'p-0',
                    ])
                    ->extraInputAttributes([
                        'class' => 'w-16 !h-7 !p-0 text-center border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 border focus:border-primary-500 focus:ring-primary-500 !text-xs',
                        'min' => '0',
                        'step' => '1',
                        'inputmode' => 'numeric',
                        'pattern' => '[0-9]*',
                    ])
                    ->afterStateUpdated(function ($record, $state) {
                        if (! is_numeric($state) || $state < 0) {
                            Notification::make()
                                ->title('エラー')
                                ->body('有効な数値を入力してください')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($state > $record->planned_qty) {
                            Notification::make()
                                ->title('エラー')
                                ->body("ピック数は引当数（{$record->planned_qty}）を超えることはできません")
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->picked_qty = (int) $state;
                        $record->shortage_qty = max(0, $record->planned_qty - (int) $state);
                        $record->save();

                        Notification::make()
                            ->title('ピック数を更新しました')
                            ->success()
                            ->send();
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
                //                TernaryFilter::make('shortage')
                //                    ->label('欠品のみ')
                //                    ->queries(
                //                        true: fn (Builder $query) => $query->whereColumn('ordered_qty', '>', 'planned_qty'),
                //                        false: fn (Builder $query) => $query->whereColumn('ordered_qty', '<=', 'planned_qty'),
                //                        blank: fn (Builder $query) => $query,
                //                    ),
                SelectFilter::make('partner')
                    ->label('得意先')
                    ->options(function () {
                        // Get pickingTaskId from URL query parameters
                        $pickingTaskId = request()->input('tableFilters.picking_task_id.value');

                        if (! $pickingTaskId) {
                            return [];
                        }

                        // Get unique partners from this picking task

                        $partners = Partner::whereHas('trades.wmsPickingItemResults', function (Builder $query) use ($pickingTaskId) {
                            $query->where('picking_task_id', $pickingTaskId);
                        })->get();

                        return $partners->pluck('name', 'id')->toArray();
                    })
                    ->query(function (Builder $query, $state) {
                        if (! empty($state['value'])) {
                            return $query->whereHas('earning.buyer.partner', function ($q) use ($state) {
                                $q->where('id', $state['value']);
                            });
                        }

                        return $query;
                    }),
                SelectFilter::make('item')
                    ->label('商品')
                    ->relationship('item', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('picking_task_id')
                    ->label('タスクID')
                    ->searchable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsPickingItemEdits::route('/'),
        ];
    }
}
