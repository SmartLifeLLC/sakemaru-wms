<?php

namespace App\Filament\Resources\WmsShortages\Tables;

use App\Enums\QuantityType;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortage;
use App\Models\WmsShortageAllocation;
use App\Services\Shortage\ProxyShipmentService;
use App\Services\Shortage\ShortageConfirmationCancelService;
use App\Services\Shortage\ShortageConfirmationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsShortagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100, 500, 1000])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('is_confirmed')
                    ->label('承認')
                    ->badge()
                    ->formatStateUsing(fn(bool $state): string => $state ? '承認済み' : '未承認')
                    ->color(fn(bool $state): string => $state ? 'success' : 'gray')
                    ->alignment('center'),

                TextColumn::make('confirmedBy.name')
                    ->label('承認者')
                    ->default('-')
                    ->alignment('center'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'BEFORE' => 'danger',
                        'REALLOCATING' => 'warning',
                        'SHORTAGE' => 'info',
                        'PARTIAL_SHORTAGE' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'BEFORE' => '未対応',
                        'REALLOCATING' => '移動出荷',
                        'SHORTAGE' => '欠品確定',
                        'PARTIAL_SHORTAGE' => '部分欠品',
                        default => $state ?? '-',
                    })
                    ->alignment('center'),

                TextColumn::make('wave.shipping_date')
                    ->label('出荷日')
                    ->date('Y-m-d')
                    ->sortable()
                    ->alignment('center'),

                TextColumn::make('trade.serial_id')
                    ->label('識別ID')
                    ->searchable()
                    ->sortable()
                    ->default('-')
                    ->alignment('center'),

                TextColumn::make('trade.partner.code')
                    ->label('得意先コード')
                    ->searchable()
                    ->default('-')
                    ->alignment('center'),

                TextColumn::make('trade.partner.name')
                    ->label('得意先名')
                    ->searchable()
                    ->limit(20)
                    ->default('-')
                    ->alignment('center'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('trade.earning.delivery_course.code')
                    ->label('配送コード')
                    ->searchable()
                    ->default('-')
                    ->alignment('center'),

                TextColumn::make('trade.earning.delivery_course.name')
                    ->label('配送コース')
                    ->searchable()
                    ->limit(20)
                    ->default('-')
                    ->alignment('center'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable(),

                TextColumn::make('item.capacity_case')
                    ->label('入り数')
                    ->formatStateUsing(fn($state) => $state ? (string)$state : '-')
                    ->alignment('center'),

                TextColumn::make('item.volume')
                    ->label('容量')
                    ->formatStateUsing(function ($state, $record) {
                        if (!$state) {
                            return '-';
                        }
                        $volumeUnit = $record->item->volume_unit;
                        if (!$volumeUnit) {
                            return $state;
                        }
                        $unit = \App\Enums\EVolumeUnit::tryFrom($volumeUnit);

                        return $state . ($unit ? $unit->name() : '');
                    })
                    ->alignment('center'),

                TextColumn::make('qty_type_at_order')
                    ->label('受注単位')
                    ->formatStateUsing(fn(?string $state): string => $state ? (QuantityType::tryFrom($state)?->name() ?? $state) : '-'
                    )
                    ->badge()
                    ->alignment('center'),

                TextColumn::make('order_qty')
                    ->label('受注')
                    ->alignment('center'),

                TextColumn::make('shortage_qty')
                    ->label('欠品')
                    ->color(fn($record) => $record->shortage_qty > 0 ? 'danger' : 'gray')
                    ->weight('bold')
                    ->alignment('center'),

                TextColumn::make('allocations_total_qty')
                    ->label('移動出荷')
                    ->formatStateUsing(function ($record) {
                        $qty = $record->allocations_total_qty ?? 0;

                        return $qty > 0 ? (string)$qty : '-';
                    })
                    ->color(fn($record) => ($record->allocations_total_qty ?? 0) > 0 ? 'info' : 'gray')
                    ->alignment('center'),

                TextColumn::make('remaining_qty')
                    ->label('残欠品')
                    ->formatStateUsing(function ($record) {
                        $qty = $record->remaining_qty;

                        return $qty > 0 ? (string)$qty : '-';
                    })
                    ->color(fn($record) => $record->remaining_qty > 0 ? 'warning' : 'success')
                    ->alignment('center'),

                TextColumn::make('created_at')
                    ->label('発生日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->alignment('center'),

                TextColumn::make('trade.earning.buyer.current_detail.salesman.name')
                    ->label('担当営業')
                    ->default('-')
                    ->limit(15)
                    ->alignment('center'),

                TextColumn::make('confirmed_at')
                    ->label('確定日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('confirmedUser.name')
                    ->label('確定者')
                    ->default('-')
                    ->limit(15)
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'OPEN' => '未対応',
                        'REALLOCATING' => '移動出荷中',
                        'FULFILLED' => '充足',
                        'CONFIRMED' => '処理確定済み',
                        'CANCELLED' => 'キャンセル',
                    ]),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable(),

                SelectFilter::make('delivery_course_id')
                    ->label('配送コース')
                    ->relationship('trade.earning.delivery_course', 'name')
                    ->searchable()
                    ->query(function ($query, $data) {
                        if (!empty($data['value'])) {
                            $query->whereHas('trade.earning', function ($q) use ($data) {
                                $q->where('delivery_course_id', $data['value']);
                            });
                        }
                    }),

                SelectFilter::make('partner_id')
                    ->label('得意先')
                    ->relationship('trade.partner', 'name')
                    ->searchable()
                    ->query(function ($query, $data) {
                        if (!empty($data['value'])) {
                            $query->whereHas('trade', function ($q) use ($data) {
                                $q->where('partner_id', $data['value']);
                            });
                        }
                    }),

                SelectFilter::make('shipping_date')
                    ->label('出荷日')
                    ->options(function () {
                        return \App\Models\Wave::query()
                            ->select('shipping_date')
                            ->distinct()
                            ->orderBy('shipping_date', 'desc')
                            ->limit(30)
                            ->pluck('shipping_date', 'shipping_date')
                            ->map(fn($date) => $date ? $date->format('Y-m-d') : '-')
                            ->toArray();
                    })
                    ->query(function ($query, $data) {
                        if (!empty($data['value'])) {
                            $query->whereHas('wave', function ($q) use ($data) {
                                $q->whereDate('shipping_date', $data['value']);
                            });
                        }
                    }),

                SelectFilter::make('salesman_id')
                    ->label('担当営業')
                    ->relationship('trade.earning.buyer.current_detail.salesman', 'name')
                    ->searchable()
                    ->query(function ($query, $data) {
                        if (!empty($data['value'])) {
                            $query->whereHas('trade.earning.buyer.current_detail.salesman', function ($q) use ($data) {
                                $q->where('id', $data['value']);
                            });
                        }
                    }),
            ])
            ->recordAction(fn(WmsShortage $record) => $record->is_confirmed ? 'viewProxyShipment' : 'createProxyShipment'
            )
            ->recordActions([
                Action::make('createProxyShipment')
                    ->label('欠品処理')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->hidden(fn(WmsShortage $record) => $record->is_confirmed)
                    ->modalHeading('')
                    ->modalSubmitActionLabel('欠品処理確定')
                    ->schema([
                        Section::make()
                            ->schema([
                                View::make('filament.components.shortage-info-table')
                                    ->viewData(function (Get $get, WmsShortage $record): array {
                                        // リアルタイム計算値を取得
                                        $allocations = $get('allocations') ?? [];
                                        $allocatedQty = collect($allocations)->sum(function ($item) {
                                            $qty = $item['assign_qty'] ?? 0;
                                            return is_numeric($qty) ? (int)$qty : 0;
                                        });
                                        $remaining = max(0, $record->shortage_qty - $allocatedQty);

                                        // 容量
                                        $volumeValue = '-';
                                        if ($record->item->volume) {
                                            $unit = \App\Enums\EVolumeUnit::tryFrom($record->item->volume_unit);
                                            $volumeValue = $record->item->volume . ($unit ? $unit->name() : '');
                                        }

                                        $orderQtyValue = (string)$record->order_qty;
                                        $plannedQtyValue = (string)$record->picked_qty;

                                        $shortageDetailsParts = [];
                                        if ($record->allocation_shortage_qty > 0) {
                                            $shortageDetailsParts[] = "引当時: {$record->allocation_shortage_qty}";
                                        }
                                        if ($record->picking_shortage_qty > 0) {
                                            $shortageDetailsParts[] = "ピッキング時: {$record->picking_shortage_qty}";
                                        }
                                        $shortageDetailsValue = implode(' / ', $shortageDetailsParts);

                                        $shortageQtyValue = $record->shortage_qty > 0
                                            ? (string)$record->shortage_qty
                                            : '-';

                                        $allocatedQtyValue = $allocatedQty > 0
                                            ? (string)$allocatedQty
                                            : '-';

                                        $remainingValue = (string)$remaining;

                                        return [
                                            'data' => [
                                                [
                                                    'label' => '商品コード',
                                                    'value' => $record->item->code ?? '-',
                                                ],
                                                [
                                                    'label' => '商品名',
                                                    'value' => $record->item->name ?? '-',
                                                ],
                                                [
                                                    'label' => '入り数',
                                                    'value' => $record->item->capacity_case
                                                        ? (string)$record->item->capacity_case
                                                        : '-',
                                                ],
                                                [
                                                    'label' => '容量',
                                                    'value' => $volumeValue,
                                                ],
                                                [
                                                    'label' => '得意先コード',
                                                    'value' => $record->trade->partner->code ?? '-',
                                                ],
                                                [
                                                    'label' => '得意先名',
                                                    'value' => $record->trade->partner->name ?? '-',
                                                ],
                                                [
                                                    'label' => '元倉庫',
                                                    'value' => $record->warehouse->name ?? '-',
                                                ],
                                                [
                                                    'label' => '受注単位',
                                                    'value' => QuantityType::tryFrom($record->qty_type_at_order)?->name()
                                                        ?? $record->qty_type_at_order,
                                                ],
                                                [
                                                    'label' => '受注数',
                                                    'value' => $orderQtyValue,
                                                ],
                                                [
                                                    'label' => '引当数',
                                                    'value' => $plannedQtyValue,
                                                ],
                                                [
                                                    'label' => '欠品数',
                                                    'value' => $shortageQtyValue,
                                                ],
                                                [
                                                    'label' => '移動出荷数',
                                                    'value' => $allocatedQtyValue,
                                                    'color' => 'blue',
                                                ],
                                                [
                                                    'label' => '残欠品数',
                                                    'value' => $remainingValue,
                                                    'bold' => true,
                                                    'color' => 'red',
                                                ],
                                                [
                                                    'label' => '欠品内訳',
                                                    'value' => $shortageDetailsValue,
                                                ],
                                            ],
                                        ];
                                    })
                                    ->columnSpanFull(),
                            ])
                            ->compact(),

                        Repeater::make('allocations')
                            ->label('移動出荷指示')
                            ->live()
                            ->deletable(false)
                            ->reorderable(false)
                            ->validationAttribute('移動出荷指示')
                            ->rules([
                                function (WmsShortage $record) {
                                    return function (string $attribute, $value, \Closure $fail) use ($record) {
                                        if (!is_array($value)) {
                                            return;
                                        }

                                        $totalAllocated = collect($value)->sum(function ($item) {
                                            $qty = $item['assign_qty'] ?? 0;

                                            return is_numeric($qty) ? (int)$qty : 0;
                                        });

                                        if ($totalAllocated > $record->shortage_qty) {
                                            $qtyType = QuantityType::tryFrom($record->qty_type_at_order);
                                            $unit = $qtyType ? $qtyType->name() : $record->qty_type_at_order;
                                            $fail("移動出荷総数（{$totalAllocated}{$unit}）が欠品数（{$record->shortage_qty}{$unit}）を超えています。");
                                        }

                                        $selectedWarehouses = collect($value)
                                            ->pluck('from_warehouse_id')
                                            ->filter()
                                            ->toArray();

                                        $counts = array_count_values($selectedWarehouses);
                                        foreach ($counts as $warehouseId => $count) {
                                            if ($count > 1) {
                                                $warehouseName = Warehouse::find($warehouseId)?->name ?? '倉庫';
                                                $fail("{$warehouseName}が重複して選択されています。");
                                                break;
                                            }
                                        }
                                    };
                                },
                            ])
                            ->schema([
                                Hidden::make('id'),

                                Select::make('from_warehouse_id')
                                    ->label('移動出荷倉庫')
                                    ->options(function (WmsShortage $record) {
                                        return Warehouse::pluck('name', 'id')
                                            ->toArray();
                                    })
                                    ->required()
                                    ->searchable()
                                    ->validationAttribute('移動出荷倉庫'),

                                TextInput::make('assign_qty')
                                    ->label('移動出荷数量（0で削除）')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0)
                                    ->integer()
                                    ->inputMode('numeric')
                                    ->live(onBlur: true)
                                    ->extraInputAttributes([
                                        'pattern' => '[0-9]*',
                                        'type' => 'number',
                                        'min' => '0',
                                        'step' => '1',
                                        'oninput' => 'this.value = this.value.replace(/[０-９]/g, s => String.fromCharCode(s.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, "")',
                                        'onblur' => 'this.value = this.value.replace(/[０-９]/g, s => String.fromCharCode(s.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, "")',
                                        'onchange' => 'this.value = this.value.replace(/[０-９]/g, s => String.fromCharCode(s.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, "")',
                                    ])
                                    ->rule('regex:/^[0-9]+$/')
                                    ->dehydrateStateUsing(fn($state) => is_numeric($state) ? (int)$state : null)
                                    ->validationAttribute('移動出荷数量'),

                                Select::make('assign_qty_type')
                                    ->label('単位')
                                    ->options(function (WmsShortage $record) {
                                        $qtyType = QuantityType::tryFrom($record->qty_type_at_order);

                                        return [$qtyType->value => $qtyType->name()];
                                    })
                                    ->default(fn(WmsShortage $record) => $record->qty_type_at_order)
                                    ->disabled()
                                    ->dehydrated()
                                    ->required(),
                            ])
                            ->default(function (WmsShortage $record) {
                                $allocations = $record->allocations()
                                    ->with('targetWarehouse')
                                    ->get();

                                if ($allocations->isEmpty()) {
                                    return [];
                                }

                                return $allocations->map(function ($allocation) use ($record) {
                                    return [
                                        'id' => $allocation->id,
                                        'from_warehouse_id' => $allocation->target_warehouse_id,
                                        'assign_qty' => $allocation->assign_qty,
                                        'assign_qty_type' => $record->qty_type_at_order,
                                    ];
                                })->toArray();
                            })
                            ->columns(3),
                    ])
                    ->action(function (WmsShortage $record, array $data, Action $action): void {
                        try {
                            $service = app(ProxyShipmentService::class);
                            $createdCount = 0;
                            $updatedCount = 0;
                            $deletedCount = 0;

                            $formAllocationIds = collect($data['allocations'] ?? [])
                                ->pluck('id')
                                ->filter()
                                ->toArray();

                            $existingAllocations = $record->allocations()->get();

                            foreach ($existingAllocations as $existing) {
                                if (!in_array($existing->id, $formAllocationIds)) {
                                    $service->deleteProxyShipment($existing);
                                    $deletedCount++;
                                }
                            }

                            foreach ($data['allocations'] as $allocation) {
                                // 数量が0の場合は削除
                                if (isset($allocation['assign_qty']) && $allocation['assign_qty'] == 0) {
                                    if (!empty($allocation['id'])) {
                                        $existingAllocation = WmsShortageAllocation::find($allocation['id']);
                                        if ($existingAllocation) {
                                            $service->deleteProxyShipment($existingAllocation);
                                            $deletedCount++;
                                        }
                                    }
                                    continue;
                                }

                                if (!empty($allocation['id'])) {
                                    $existingAllocation = WmsShortageAllocation::find($allocation['id']);
                                    if ($existingAllocation && in_array($existingAllocation->status, ['PENDING', 'RESERVED'])) {
                                        $existingAllocation->update([
                                            'from_warehouse_id' => $allocation['from_warehouse_id'],
                                            'assign_qty' => $allocation['assign_qty'],
                                        ]);
                                        $updatedCount++;
                                    }
                                } else {
                                    $service->createProxyShipment(
                                        $record,
                                        $allocation['from_warehouse_id'],
                                        $allocation['assign_qty'],
                                        $allocation['assign_qty_type'],
                                        auth()->id() ?? 0
                                    );
                                    $createdCount++;
                                }
                            }

                            // ステータスを更新
                            $record->refresh();
                            $totalAllocated = $record->allocations()->sum('assign_qty');
                            $remainingShortage = max(0, $record->shortage_qty - $totalAllocated);

                            if ($totalAllocated === 0) {
                                // 移動出荷数が0の場合: SHORTAGE（欠品確定）
                                $record->status = WmsShortage::STATUS_SHORTAGE;
                            } elseif ($remainingShortage === 0) {
                                // 移動出荷で欠品がない場合: REALLOCATING（再引当中）
                                $record->status = WmsShortage::STATUS_REALLOCATING;
                            } else {
                                // 移動出荷と欠品が共存する場合: PARTIAL_SHORTAGE（部分欠品）
                                $record->status = WmsShortage::STATUS_PARTIAL_SHORTAGE;
                            }
                            $record->save();

                            $messages = [];
                            if ($createdCount > 0) {
                                $messages[] = "{$createdCount}件の新規指示を作成";
                            }
                            if ($updatedCount > 0) {
                                $messages[] = "{$updatedCount}件の既存指示を更新";
                            }
                            if ($deletedCount > 0) {
                                $messages[] = "{$deletedCount}件の指示を削除";
                            }

                            $statusLabel = match($record->status) {
                                WmsShortage::STATUS_SHORTAGE => '欠品確定',
                                WmsShortage::STATUS_REALLOCATING => '再引当中',
                                WmsShortage::STATUS_PARTIAL_SHORTAGE => '部分欠品',
                                default => $record->status,
                            };

                            Notification::make()
                                ->title('欠品処理を確定しました')
                                ->body(implode('、', $messages) . ($messages ? '。' : '') . "ステータス: {$statusLabel}")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラー')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                // viewProxyShipment はそのまま（省略可だがここでは元に近い形で維持）
                Action::make('viewProxyShipment')
                    ->label('欠品処理')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->visible(fn(WmsShortage $record): bool => $record->is_confirmed
                    )
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->schema([
                        Section::make('商品情報')
                            ->schema([
                                View::make('filament.components.shortage-info-table')
                                    ->viewData(function (WmsShortage $record): array {
                                        $volumeValue = '-';
                                        if ($record->item->volume) {
                                            $unit = \App\Enums\EVolumeUnit::tryFrom($record->item->volume_unit);
                                            $volumeValue = $record->item->volume . ($unit ? $unit->name() : '');
                                        }

                                        $orderQtyValue = (string)$record->order_qty;
                                        $plannedQtyValue = (string)$record->picked_qty;

                                        $shortageDetailsParts = [];
                                        if ($record->allocation_shortage_qty > 0) {
                                            $shortageDetailsParts[] = "引当時: {$record->allocation_shortage_qty}";
                                        }
                                        if ($record->picking_shortage_qty > 0) {
                                            $shortageDetailsParts[] = "ピッキング時: {$record->picking_shortage_qty}";
                                        }
                                        $shortageDetailsValue = implode(' / ', $shortageDetailsParts);

                                        $shortageQtyValue = $record->shortage_qty > 0
                                            ? (string)$record->shortage_qty
                                            : '-';

                                        $allocatedQtyValue = ($record->allocations_total_qty ?? 0) > 0
                                            ? (string)($record->allocations_total_qty ?? 0)
                                            : '-';

                                        $remainingQty = $record->remaining_qty;
                                        $remainingValue = $remainingQty > 0
                                            ? (string)$remainingQty
                                            : '-';

                                        return [
                                            'data' => [
                                                [
                                                    'label' => '商品コード',
                                                    'value' => $record->item->code ?? '-',
                                                ],
                                                [
                                                    'label' => '商品名',
                                                    'value' => $record->item->name ?? '-',
                                                ],
                                                [
                                                    'label' => '入り数',
                                                    'value' => $record->item->capacity_case
                                                        ? (string)$record->item->capacity_case
                                                        : '-',
                                                ],
                                                [
                                                    'label' => '容量',
                                                    'value' => $volumeValue,
                                                ],
                                                [
                                                    'label' => '得意先コード',
                                                    'value' => $record->trade->partner->code ?? '-',
                                                ],
                                                [
                                                    'label' => '得意先名',
                                                    'value' => $record->trade->partner->name ?? '-',
                                                ],
                                                [
                                                    'label' => '元倉庫',
                                                    'value' => $record->warehouse->name ?? '-',
                                                ],
                                                [
                                                    'label' => '受注単位',
                                                    'value' => QuantityType::tryFrom($record->qty_type_at_order)?->name()
                                                        ?? $record->qty_type_at_order,
                                                ],
                                                [
                                                    'label' => '受注数',
                                                    'value' => $orderQtyValue,
                                                ],
                                                [
                                                    'label' => '引当数',
                                                    'value' => $plannedQtyValue,
                                                ],
                                                [
                                                    'label' => '欠品数',
                                                    'value' => $shortageQtyValue,
                                                ],
                                                [
                                                    'label' => '移動出荷数',
                                                    'value' => $allocatedQtyValue,
                                                    'color' => 'blue',
                                                ],
                                                [
                                                    'label' => '残欠品数',
                                                    'value' => $remainingValue,
                                                    'bold' => true,
                                                    'color' => 'red',
                                                ],
                                                [
                                                    'label' => '欠品内訳',
                                                    'value' => $shortageDetailsValue,
                                                ],
                                            ],
                                        ];
                                    }),
                            ]),

                        Repeater::make('allocations')
                            ->label('移動出荷指示')
                            ->disabled()
                            ->columns(4)
                            ->schema([
                                TextInput::make('id')
                                    ->label('ID')
                                    ->disabled()
                                    ->columnSpan(1),

                                Select::make('from_warehouse_id')
                                    ->label('移動出荷倉庫')
                                    ->options(Warehouse::pluck('name', 'id')->toArray())
                                    ->disabled()
                                    ->columnSpan(1),

                                TextInput::make('assign_qty')
                                    ->label('移動出荷数量')
                                    ->disabled()
                                    ->columnSpan(1),

                                Select::make('assign_qty_type')
                                    ->label('単位')
                                    ->options(function (WmsShortage $record) {
                                        $qtyType = QuantityType::tryFrom($record->qty_type_at_order);

                                        return [$qtyType->value => $qtyType->name()];
                                    })
                                    ->disabled()
                                    ->columnSpan(1),
                            ])
                            ->default(function (WmsShortage $record) {
                                $allocations = $record->allocations()
                                    ->with('targetWarehouse')
                                    ->get();

                                return $allocations->map(function ($allocation) {
                                    return [
                                        'id' => $allocation->id,
                                        'from_warehouse_id' => $allocation->target_warehouse_id,
                                        'assign_qty' => $allocation->assign_qty,
                                        'assign_qty_type' => $allocation->assign_qty_type,
                                    ];
                                })->toArray();
                            }),
                    ]),
            ], position: RecordActionsPosition::BeforeColumns)
            ->selectCurrentPageOnly()
            ->defaultSort('created_at', 'desc');
    }
}
