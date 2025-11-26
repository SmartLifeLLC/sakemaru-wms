<?php

namespace App\Filament\Resources\WmsShortagesWaitingApprovals\Tables;

use App\Actions\Wms\ConfirmShortageAllocations;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortage;
use App\Models\WmsShortageAllocation;
use App\Services\QuantityUpdate\QuantityUpdateQueueService;
use App\Services\Shortage\ProxyShipmentService;
use App\Services\Shortage\ShortageApprovalService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
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
use Illuminate\Database\Eloquent\Builder;

class WmsShortagesWaitingApprovalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
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
                    ->searchable()
                    ->alignment('center')
                    ->toggleable(),

                TextColumn::make('confirmed_at')
                    ->label('承認日時')
                    ->dateTime('Y-m-d H:i')
                    ->alignment('center')
                    ->toggleable(),

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
                        'REALLOCATING' => '横持ち出荷',
                        'SHORTAGE' => '欠品確定',
                        'PARTIAL_SHORTAGE' => '部分欠品',
                        default => $state ?? '-',
                    })
                    ->sortable()
                    ->alignment('center'),

                TextColumn::make('wave_id')
                    ->label('ウェーブID')
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('wave.shipping_date')
                    ->label('納品日')
                    ->date('Y-m-d')
                    ->sortable()
                    ->alignment('center'),

                TextColumn::make('trade.partner.code')
                    ->label('得意先CD')
                    ->sortable()
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('trade.partner.name')
                    ->label('得意先名')
                    ->sortable()
                    ->searchable()
                    ->limit(20)
                    ->alignment('center'),

                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->sortable()
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->sortable()
                    ->searchable()
                    ->limit(30)
                    ->alignment('center'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->sortable()
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('order_qty')
                    ->label('受注数')
                    ->numeric()
                    ->alignment('center'),

                TextColumn::make('picked_qty')
                    ->label('引当数')
                    ->numeric()
                    ->alignment('center'),

                TextColumn::make('shortage_qty')
                    ->label('欠品数')
                    ->numeric()
                    ->alignment('center')
                    ->color('danger'),

                TextColumn::make('allocations_total_qty')
                    ->label('横持ち出荷数')
                    ->numeric()
                    ->alignment('center')
                    ->color('info'),

                TextColumn::make('remaining_qty')
                    ->label('残欠品数')
                    ->numeric()
                    ->alignment('center')
                    ->color('warning'),

                TextColumn::make('allocation_shortage_qty')
                    ->label('引当時欠品')
                    ->numeric()
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('picking_shortage_qty')
                    ->label('ピッキング時欠品')
                    ->numeric()
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(WmsShortage::STATUS_LABELS)
                    ->multiple(),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->multiple(),

                SelectFilter::make('trade.partner_id')
                    ->label('得意先')
                    ->query(function ($query, $data) {
                        if (!empty($data['value'])) {
                            $query->whereHas('trade', function ($q) use ($data) {
                                $q->where('partner_id', $data['value']);
                            });
                        }
                    }),
            ])
            ->recordAction('editProxyShipment')
            ->recordActions([
                // 編集アクション（承認済みは編集不可）
                Action::make('editProxyShipment')
                    ->label('欠品編集')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->hidden(fn(WmsShortage $record) => $record->is_confirmed)
                    ->modalHeading('欠品対応')
                    ->modalSubmitActionLabel('保存')
                    ->fillForm(function (WmsShortage $record): array {
                        $allocations = $record->allocations()
                            ->get()
                            ->map(function ($allocation) {
                                return [
                                    'id' => $allocation->id,
                                    'from_warehouse_id' => $allocation->target_warehouse_id,
                                    'assign_qty' => $allocation->assign_qty,
                                    'qty_type' => $allocation->assign_qty_type,
                                ];
                            })
                            ->toArray();

                        return [
                            'allocations' => $allocations,
                        ];
                    })
                    ->schema([
                        \Filament\Forms\Components\ViewField::make('allocations')
                            ->label('横持ち出荷指示')
                            ->live()
                            ->view('filament.forms.components.proxy-shipment-allocations')
                            ->viewData(function (WmsShortage $record): array {
                                // 該当商品の全倉庫在庫を取得（在庫がある倉庫のみ）
                                $stocks = \DB::connection('sakemaru')
                                    ->table('real_stocks')
                                    ->select([
                                        'real_stocks.warehouse_id',
                                        \DB::raw('warehouses.name as warehouse_name'),
                                        \DB::raw('SUM(real_stocks.current_quantity) as total_pieces'),
                                    ])
                                    ->join('warehouses', 'warehouses.id', '=', 'real_stocks.warehouse_id')
                                    ->where('real_stocks.item_id', $record->item_id)
                                    ->where('real_stocks.current_quantity', '>', 0)
                                    ->groupBy('real_stocks.warehouse_id', 'warehouses.name')
                                    ->orderBy('warehouses.name')
                                    ->get();

                                $caseSize = $record->item->capacity_case ?? 1;

                                $stockData = $stocks->map(function ($stock) use ($caseSize) {
                                    $totalPieces = (int) $stock->total_pieces;
                                    $cases = floor($totalPieces / $caseSize);

                                    return [
                                        'warehouse_id' => $stock->warehouse_id,
                                        'warehouse_name' => $stock->warehouse_name,
                                        'cases' => $cases,
                                        'total_pieces' => $totalPieces,
                                    ];
                                })->toArray();

                                $qtyType = QuantityType::tryFrom($record->qty_type_at_order);
                                
                                // 容量
                                $volumeValue = '-';
                                if ($record->item->volume) {
                                    $unit = \App\Enums\EVolumeUnit::tryFrom($record->item->volume_unit);
                                    $volumeValue = $record->item->volume . ($unit ? $unit->name() : '');
                                }

                                // 欠品内訳
                                $shortageDetailsParts = [];
                                if ($record->allocation_shortage_qty > 0) {
                                    $shortageDetailsParts[] = "引当時: {$record->allocation_shortage_qty}";
                                }
                                if ($record->picking_shortage_qty > 0) {
                                    $shortageDetailsParts[] = "ピッキング時: {$record->picking_shortage_qty}";
                                }
                                $shortageDetailsValue = implode(' / ', $shortageDetailsParts);

                                return [
                                    'stocks' => $stockData,
                                    'warehouses' => Warehouse::pluck('name', 'id')->toArray(),
                                    'shortage_qty' => $record->shortage_qty,
                                    'qty_type' => $record->qty_type_at_order,
                                    'qty_type_label' => $qtyType ? $qtyType->name() : $record->qty_type_at_order,
                                    // Info Table Data
                                    'item_code' => $record->item->code ?? '-',
                                    'item_name' => $record->item->name ?? '-',
                                    'capacity_case' => $record->item->capacity_case ? (string)$record->item->capacity_case : '-',
                                    'volume_value' => $volumeValue,
                                    'partner_code' => $record->trade->partner->code ?? '-',
                                    'partner_name' => $record->trade->partner->name ?? '-',
                                    'warehouse_name' => $record->warehouse->name ?? '-',
                                    'order_qty' => (string)$record->order_qty,
                                    'picked_qty' => (string)$record->picked_qty,
                                    'shortage_details' => $shortageDetailsValue,
                                ];
                            })
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
                                            $fail("横持ち出荷総数（{$totalAllocated}{$unit}）が欠品数（{$record->shortage_qty}{$unit}）を超えています。");
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
                            ]),
                    ])
                    ->action(function (WmsShortage $record, array $data) {
                        $service = app(ProxyShipmentService::class);
                        $deletedCount = 0;

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

                            // 既存のレコードを更新または新規作成
                            if (!empty($allocation['id'])) {
                                // 更新
                                $existingAllocation = WmsShortageAllocation::find($allocation['id']);
                                if ($existingAllocation) {
                                    $service->updateProxyShipment(
                                        $existingAllocation,
                                        $allocation['from_warehouse_id'],
                                        $allocation['assign_qty'],
                                        auth()->id() ?? 0
                                    );
                                }
                            } else {
                                // 新規作成
                                $service->createProxyShipment(
                                    $record,
                                    $allocation['from_warehouse_id'],
                                    $allocation['assign_qty'],
                                    $record->qty_type_at_order,  // 受注単位を使用
                                    auth()->id() ?? 0
                                );
                            }
                        }

                        // ステータスを更新
                        $record->refresh();
                        $totalAllocated = $record->allocations()->sum('assign_qty');
                        $remainingShortage = max(0, $record->shortage_qty - $totalAllocated);

                        if ($totalAllocated === 0) {
                            $record->status = WmsShortage::STATUS_SHORTAGE;
                        } elseif ($remainingShortage === 0) {
                            $record->status = WmsShortage::STATUS_REALLOCATING;
                        } else {
                            $record->status = WmsShortage::STATUS_PARTIAL_SHORTAGE;
                        }
                        $record->save();

                        Notification::make()
                            ->title('保存しました')
                            ->body('欠品対応を更新しました' . ($deletedCount > 0 ? "（{$deletedCount}件削除）" : ''))
                            ->success()
                            ->send();
                    }),

                // 閲覧アクション（承認済みのみ）
                Action::make('viewProxyShipment')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->visible(fn(WmsShortage $record) => $record->is_confirmed)
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
                                                    'label' => '欠品詳細',
                                                    'value' => $shortageDetailsValue ?: '-',
                                                ],
                                                [
                                                    'label' => '横持ち出荷数',
                                                    'value' => $allocatedQtyValue,
                                                ],
                                                [
                                                    'label' => '残欠品数',
                                                    'value' => $remainingValue,
                                                ],
                                            ],
                                        ];
                                    }),
                            ]),

                        Section::make('横持ち出荷指示')
                            ->schema([
                                Repeater::make('allocations')
                                    ->label('')
                                    ->relationship('allocations')
                                    ->disabled()
                                    ->schema([
                                        Select::make('target_warehouse_id')
                                            ->label('横持ち出荷倉庫')
                                            ->relationship('targetWarehouse', 'name')
                                            ->disabled(),

                                        TextInput::make('assign_qty')
                                            ->label('横持ち出荷数量')
                                            ->disabled(),

                                        TextInput::make('qty_type')
                                            ->label('単位')
                                            ->disabled()
                                            ->formatStateUsing(fn($state) => QuantityType::tryFrom($state)?->name() ?? $state),
                                    ])
                                    ->columns(3)
                                    ->defaultItems(0),
                            ]),
                    ]),

                // 承認アクション
                Action::make('confirmShortage')
                    ->label('承認')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(WmsShortage $record) => !$record->is_confirmed)
                    ->requiresConfirmation()
                    ->modalHeading('欠品対応を承認しますか？')
                    ->modalDescription('この欠品の横持ち出荷指示を承認します。')
                    ->action(function (WmsShortage $record) {
                        try {
                            $record->is_confirmed = true;
                            $record->confirmed_by = auth()->id();
                            $record->confirmed_at = now();
                            $record->confirmed_user_id = auth()->id();
                            $record->save();

                            // 関連する代理出荷も承認
                            $confirmedAllocationsCount = ConfirmShortageAllocations::execute(
                                wmsShortageId: $record->id,
                                confirmedUserId: auth()->id() ?? 0
                            );

                            // quantity_update_queueにレコードを作成
                            $queueService = app(QuantityUpdateQueueService::class);
                            $queue = $queueService->createQueueForShortageApproval($record);

                            // ピッキングタスクのステータスを更新
                            $approvalService = app(ShortageApprovalService::class);
                            $approvalService->updatePickingTaskStatusAfterApproval($record);

                            $message = '欠品対応を承認しました。';
                            if ($confirmedAllocationsCount > 0) {
                                $message .= "代理出荷{$confirmedAllocationsCount}件を承認しました。";
                            }
                            if ($queue) {
                                $message .= '在庫更新キューを作成しました。';
                            }

                            Notification::make()
                                ->title('承認しました')
                                ->body($message)
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
            ], position: RecordActionsPosition::BeforeColumns)
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('confirmShortage')
                        ->label('承認')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('欠品対応を承認しますか？')
                        ->modalDescription('選択された欠品の横持ち出荷指示を承認します。')
                        ->action(function ($records) {
                            $count = 0;
                            $skipped = 0;
                            $queueCreated = 0;
                            $totalAllocationsConfirmed = 0;

                            $queueService = app(QuantityUpdateQueueService::class);
                            $approvalService = app(ShortageApprovalService::class);

                            foreach ($records as $shortage) {
                                // BEFOREまたは既に確定済みの場合はスキップ
                                if ($shortage->status === WmsShortage::STATUS_BEFORE || $shortage->is_confirmed) {
                                    $skipped++;
                                    continue;
                                }

                                try {
                                    $shortage->is_confirmed = true;
                                    $shortage->confirmed_by = auth()->id();
                                    $shortage->confirmed_at = now();
                                    $shortage->confirmed_user_id = auth()->id();
                                    $shortage->save();
                                    $count++;

                                    // 関連する代理出荷も承認
                                    $confirmedAllocationsCount = ConfirmShortageAllocations::execute(
                                        wmsShortageId: $shortage->id,
                                        confirmedUserId: auth()->id() ?? 0
                                    );
                                    $totalAllocationsConfirmed += $confirmedAllocationsCount;

                                    // quantity_update_queueにレコードを作成
                                    $queue = $queueService->createQueueForShortageApproval($shortage);
                                    if ($queue) {
                                        $queueCreated++;
                                    }

                                    // ピッキングタスクのステータスを更新
                                    $approvalService->updatePickingTaskStatusAfterApproval($shortage);
                                } catch (\Exception $e) {
                                    Notification::make()
                                        ->title('エラー')
                                        ->body("欠品ID {$shortage->id} の処理に失敗: {$e->getMessage()}")
                                        ->danger()
                                        ->send();
                                }
                            }

                            if ($count > 0) {
                                $message = "{$count}件の欠品対応を承認しました";
                                if ($totalAllocationsConfirmed > 0) {
                                    $message .= "（代理出荷{$totalAllocationsConfirmed}件承認）";
                                }
                                if ($queueCreated > 0) {
                                    $message .= "（在庫更新キュー{$queueCreated}件作成）";
                                }
                                if ($skipped > 0) {
                                    $message .= "（{$skipped}件スキップ）";
                                }

                                Notification::make()
                                    ->title('承認しました')
                                    ->body($message)
                                    ->success()
                                    ->send();
                            } elseif ($skipped > 0) {
                                Notification::make()
                                    ->title('承認対象なし')
                                    ->body('選択されたレコードは既に承認済みまたは承認可能な状態ではありません')
                                    ->warning()
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->selectCurrentPageOnly()
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(function (Builder $query) {
                // 承認待ちのレコードのみ表示: is_confirmed = 0 かつ status != BEFORE
                $query->where('is_confirmed', false)
                    ->where('status', '!=', WmsShortage::STATUS_BEFORE);
            });
    }
}
