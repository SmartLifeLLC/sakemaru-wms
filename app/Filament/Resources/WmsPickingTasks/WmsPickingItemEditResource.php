<?php

namespace App\Filament\Resources\WmsPickingTasks;

use App\Enums\EWMSLogOperationType;
use App\Enums\EWMSLogTargetType;
use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingItemEdits;
use App\Filament\Support\AdminResource;
use App\Models\Sakemaru\Partner;
use App\Models\Sakemaru\RealStock;
use App\Models\WmsAdminOperationLog;
use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Enums\RecordActionsPosition;
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
                    'tradeItem',
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
                TextColumn::make('latest_order_qty')
                    ->label('最新受注')
                    ->state(fn ($record) => static::formatLatestOrderQuantity($record))
                    ->color(fn ($record) => static::hasLatestOrderQuantityChanged($record) ? 'warning' : 'gray')
                    ->weight(fn ($record) => static::hasLatestOrderQuantityChanged($record) ? 'bold' : 'normal')
                    ->tooltip(fn ($record) => static::hasLatestOrderQuantityChanged($record)
                        ? '基幹の受注明細が変更されています。「受注反映」でWMS明細へ反映してください。'
                        : 'WMS明細と基幹の受注明細は一致しています'),

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

                        if (static::quantityAsPieces($newPlannedQty, $record->planned_qty_type, $record) > static::quantityAsPieces((int) $record->ordered_qty, $record->ordered_qty_type, $record)) {
                            Notification::make()
                                ->title('エラー')
                                ->body('引当数は受注数量を超えることはできません')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->planned_qty = $newPlannedQty;
                        // picked_qtyがplanned_qtyを超える場合は調整
                        if ($record->picked_qty > $newPlannedQty) {
                            $record->picked_qty = $newPlannedQty;
                        }
                        $record->shortage_qty = static::calculateAllocationShortage($record);
                        $record->save();

                        Notification::make()
                            ->title('引当数を更新しました')
                            ->success()
                            ->send();
                    }),
                SelectColumn::make('planned_qty_type')
                    ->label('引当区分')
                    ->options(static::quantityTypeOptions())
                    ->alignCenter()
                    ->disabled(fn ($record) => ! $record->pickingTask || ! in_array($record->pickingTask->status, [
                        WmsPickingTask::STATUS_PENDING,
                        WmsPickingTask::STATUS_PICKING_READY,
                    ]))
                    ->afterStateUpdated(function ($record, $state) {
                        if (! array_key_exists($state, static::quantityTypeOptions())) {
                            Notification::make()
                                ->title('エラー')
                                ->body('有効な数量区分を選択してください')
                                ->danger()
                                ->send();

                            return;
                        }

                        $oldType = $record->getOriginal('planned_qty_type');
                        if (static::quantityAsPieces((int) $record->planned_qty, $state, $record) > static::quantityAsPieces((int) $record->ordered_qty, $record->ordered_qty_type, $record)) {
                            $record->planned_qty_type = $oldType;
                            $record->save();

                            Notification::make()
                                ->title('エラー')
                                ->body('引当数は受注数量を超えることはできません')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->planned_qty_type = $state;
                        if ((int) $record->picked_qty === 0) {
                            $record->picked_qty_type = $state;
                        }
                        $record->shortage_qty = static::calculateAllocationShortage($record);
                        $record->save();

                        static::logQuantityChange(
                            $record,
                            (int) $record->planned_qty,
                            (int) $record->planned_qty,
                            $oldType,
                            $state,
                            '引当区分を更新'
                        );

                        Notification::make()
                            ->title('引当区分を更新しました')
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
                        WmsPickingTask::STATUS_PICKING_READY,
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
                    ->state(fn ($record) => static::calculateAllocationShortage($record))
                    ->alignment('center')
                    ->color(fn ($record) => static::calculateAllocationShortage($record) > 0 ? 'danger' : 'success')
                    ->weight(fn ($record) => static::calculateAllocationShortage($record) > 0 ? 'bold' : 'normal')
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state : '-'),
            ])
            ->recordActions([
                Action::make('sync_latest_order')
                    ->label('受注反映')
                    ->icon('heroicon-o-arrow-path')
                    ->color(fn ($record) => static::hasLatestOrderQuantityChanged($record) ? 'warning' : 'gray')
                    ->requiresConfirmation()
                    ->modalHeading('最新受注を反映')
                    ->modalDescription(fn ($record) => '基幹の受注明細をWMS明細に反映します。最新受注: '.static::formatLatestOrderQuantity($record).' / 現在: '.static::formatQuantity((int) $record->ordered_qty, $record->ordered_qty_type))
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitActionLabel('受注を反映')
                    ->modalCancelActionLabel('反映せず閉じる')
                    ->disabled(fn ($record) => ! $record->pickingTask || ! in_array($record->pickingTask->status, [
                        WmsPickingTask::STATUS_PENDING,
                        WmsPickingTask::STATUS_PICKING_READY,
                    ]))
                    ->action(function (WmsPickingItemResult $record) {
                        $latest = static::latestOrderQuantity($record);

                        if ($latest === null) {
                            Notification::make()
                                ->title('受注明細が見つかりません')
                                ->body('基幹側の受注明細が削除または参照不可のため反映できません')
                                ->danger()
                                ->send();

                            return;
                        }

                        $oldOrderedQty = (int) $record->ordered_qty;
                        $oldOrderedType = $record->ordered_qty_type;
                        $oldPlannedType = $record->planned_qty_type;
                        $oldPickedType = $record->picked_qty_type;

                        $record->ordered_qty = $latest['quantity'];
                        $record->ordered_qty_type = $latest['quantity_type'];

                        if ($oldPlannedType === $oldOrderedType) {
                            $record->planned_qty_type = $latest['quantity_type'];
                        }

                        if ((int) $record->picked_qty === 0 && $oldPickedType === $oldOrderedType) {
                            $record->picked_qty_type = $latest['quantity_type'];
                        }

                        if (static::quantityAsPieces((int) $record->planned_qty, $record->planned_qty_type, $record) > static::quantityAsPieces($latest['quantity'], $latest['quantity_type'], $record)) {
                            $record->planned_qty = $latest['quantity'];
                            $record->planned_qty_type = $latest['quantity_type'];
                        }

                        if ((int) $record->picked_qty > (int) $record->planned_qty) {
                            $record->picked_qty = (int) $record->planned_qty;
                        }

                        $record->shortage_qty = static::calculateAllocationShortage($record);
                        $record->save();

                        static::logQuantityChange(
                            $record,
                            $oldOrderedQty,
                            (int) $record->ordered_qty,
                            $oldOrderedType,
                            $record->ordered_qty_type,
                            '最新受注をWMS明細へ反映'
                        );

                        Notification::make()
                            ->title('最新受注を反映しました')
                            ->body('引当数は必要に応じて手動で調整してください')
                            ->success()
                            ->send();
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
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

    protected static function latestOrderQuantity(WmsPickingItemResult $record): ?array
    {
        $tradeItem = $record->tradeItem;

        if (! $tradeItem || ($tradeItem->is_deleted ?? false)) {
            return null;
        }

        if (! $tradeItem->quantity_type) {
            return null;
        }

        return [
            'quantity' => (int) $tradeItem->quantity,
            'quantity_type' => (string) $tradeItem->quantity_type,
        ];
    }

    protected static function hasLatestOrderQuantityChanged(WmsPickingItemResult $record): bool
    {
        $latest = static::latestOrderQuantity($record);

        if ($latest === null) {
            return true;
        }

        return (int) $record->ordered_qty !== $latest['quantity']
            || (string) $record->ordered_qty_type !== $latest['quantity_type'];
    }

    protected static function formatLatestOrderQuantity(WmsPickingItemResult $record): string
    {
        $latest = static::latestOrderQuantity($record);

        if ($latest === null) {
            return '確認不可';
        }

        return static::formatQuantity($latest['quantity'], $latest['quantity_type']);
    }

    protected static function formatQuantity(int $quantity, ?string $quantityType): string
    {
        $label = QuantityType::tryFrom((string) $quantityType)?->name() ?? (string) $quantityType;

        return "{$quantity} {$label}";
    }

    protected static function quantityTypeOptions(): array
    {
        return [
            QuantityType::CASE->value => QuantityType::CASE->name(),
            QuantityType::PIECE->value => QuantityType::PIECE->name(),
        ];
    }

    protected static function calculateAllocationShortage(WmsPickingItemResult $record): int
    {
        $ordered = static::quantityAsPieces((int) $record->ordered_qty, $record->ordered_qty_type, $record);
        $planned = static::quantityAsPieces((int) $record->planned_qty, $record->planned_qty_type, $record);

        return max(0, $ordered - $planned);
    }

    protected static function quantityAsPieces(int $quantity, ?string $quantityType, WmsPickingItemResult $record): int
    {
        $type = QuantityType::tryFrom((string) $quantityType) ?? QuantityType::PIECE;
        $capacity = $record->item?->capacityOfQuantityType($type);

        return $quantity * max(1, (int) ($capacity ?? 1));
    }

    protected static function logQuantityChange(
        WmsPickingItemResult $record,
        int $qtyBefore,
        int $qtyAfter,
        ?string $typeBefore,
        ?string $typeAfter,
        string $note
    ): void {
        WmsAdminOperationLog::log(
            EWMSLogOperationType::ADJUST_PICKING_QTY,
            [
                'target_type' => EWMSLogTargetType::PICKING_ITEM,
                'target_id' => $record->id,
                'picking_task_id' => $record->picking_task_id,
                'picking_item_result_id' => $record->id,
                'wave_id' => $record->pickingTask?->wave_id,
                'earning_id' => $record->earning_id,
                'qty_before' => $qtyBefore,
                'qty_after' => $qtyAfter,
                'qty_type' => $typeAfter,
                'operation_details' => [
                    'qty_type_before' => $typeBefore,
                    'qty_type_after' => $typeAfter,
                    'source_type' => $record->source_type,
                    'stock_transfer_id' => $record->stock_transfer_id,
                    'trade_item_id' => $record->trade_item_id,
                ],
                'operation_note' => $note,
            ]
        );
    }
}
