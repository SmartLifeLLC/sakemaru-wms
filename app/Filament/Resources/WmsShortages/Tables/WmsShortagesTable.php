<?php

namespace App\Filament\Resources\WmsShortages\Tables;

use App\Enums\QuantityType;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortage;
use App\Models\WmsShortageAllocation;
use App\Services\Shortage\ProxyShipmentService;
use App\Services\Shortage\ShortageConfirmationService;
use App\Services\Shortage\ShortageConfirmationCancelService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
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

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'OPEN' => 'danger',
                        'REALLOCATING' => 'warning',
                        'FULFILLED' => 'success',
                        'CONFIRMED' => 'info',
                        'CANCELLED' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'OPEN' => '未対応',
                        'REALLOCATING' => '移動出荷中',
                        'FULFILLED' => '充足',
                        'CONFIRMED' => '処理確定済み',
                        'CANCELLED' => 'キャンセル',
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
                    ->formatStateUsing(fn ($state) => $state ? (string)$state : '-')
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
                    ->formatStateUsing(fn (?string $state): string =>
                        $state ? (QuantityType::tryFrom($state)?->name() ?? $state) : '-'
                    )
                    ->badge()
                    ->alignment('center'),

                TextColumn::make('order_qty_case')
                    ->label('受注' . QuantityType::CASE->name())
                    ->state(function ($record) {
                        $display = $record->convertToCaseDisplay($record->order_qty_each);
                        return $display['case'];
                    })
                    ->formatStateUsing(fn ($state) => $state > 0 ? (string)$state : '-')
                    ->alignment('center'),

                TextColumn::make('order_qty_piece')
                    ->label('受注' . QuantityType::PIECE->name())
                    ->state(function ($record) {
                        $display = $record->convertToCaseDisplay($record->order_qty_each);
                        return $display['piece'];
                    })
                    ->formatStateUsing(fn ($state) => $state > 0 ? (string)$state : '-')
                    ->alignment('center'),

                TextColumn::make('shortage_qty_case')
                    ->label('欠品' . QuantityType::CASE->name())
                    ->state(function ($record) {
                        $display = $record->convertToCaseDisplay($record->shortage_qty_each);
                        return $display['case'];
                    })
                    ->formatStateUsing(fn ($state) => $state > 0 ? (string)$state : '-')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->weight('bold')
                    ->alignment('center'),

                TextColumn::make('shortage_qty_piece')
                    ->label('欠品' . QuantityType::PIECE->name())
                    ->state(function ($record) {
                        $display = $record->convertToCaseDisplay($record->shortage_qty_each);
                        return $display['piece'];
                    })
                    ->formatStateUsing(fn ($state) => $state > 0 ? (string)$state : '-')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->weight('bold')
                    ->alignment('center'),

                TextColumn::make('allocations_case_qty')
                    ->label('移動出荷' . QuantityType::CASE->name())
                    ->state(fn ($record) => $record->allocations_case_qty ?? 0)
                    ->formatStateUsing(fn ($state) => $state > 0 ? (string)$state : '-')
                    ->color(fn ($state) => $state > 0 ? 'info' : 'gray')
                    ->alignment('center'),

                TextColumn::make('allocations_piece_qty')
                    ->label('移動出荷' . QuantityType::PIECE->name())
                    ->state(fn ($record) => $record->allocations_piece_qty ?? 0)
                    ->formatStateUsing(fn ($state) => $state > 0 ? (string)$state : '-')
                    ->color(fn ($state) => $state > 0 ? 'info' : 'gray')
                    ->alignment('center'),

                TextColumn::make('remaining_qty_case')
                    ->label('残欠品' . QuantityType::CASE->name())
                    ->state(function ($record) {
                        // Use eager loaded sum to calculate remaining
                        $allocated = $record->allocations_total_qty ?? 0;
                        $remaining = max(0, $record->shortage_qty_each - $allocated);
                        $display = $record->convertToCaseDisplay($remaining);
                        return $display['case'];
                    })
                    ->formatStateUsing(fn ($state) => $state > 0 ? (string)$state : '-')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success')
                    ->alignment('center'),

                TextColumn::make('remaining_qty_piece')
                    ->label('残欠品' . QuantityType::PIECE->name())
                    ->state(function ($record) {
                        // Use eager loaded sum to calculate remaining
                        $allocated = $record->allocations_total_qty ?? 0;
                        $remaining = max(0, $record->shortage_qty_each - $allocated);
                        $display = $record->convertToCaseDisplay($remaining);
                        return $display['piece'];
                    })
                    ->formatStateUsing(fn ($state) => $state > 0 ? (string)$state : '-')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success')
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
                    // ->preload() // Disabled for performance - load on search

                SelectFilter::make('delivery_course_id')
                    ->label('配送コース')
                    ->relationship('trade.earning.delivery_course', 'name')
                    ->searchable()
                    // ->preload() // Disabled for performance - load on search
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
                    // ->preload() // Disabled for performance - load on search
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
                            ->map(fn ($date) => $date ? $date->format('Y-m-d') : '-')
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
                    // ->preload() // Disabled for performance - load on search
                    ->query(function ($query, $data) {
                        if (!empty($data['value'])) {
                            $query->whereHas('trade.earning.buyer.current_detail.salesman', function ($q) use ($data) {
                                $q->where('id', $data['value']);
                            });
                        }
                    }),
            ])
            ->recordAction(fn (WmsShortage $record) =>
                $record->status === WmsShortage::STATUS_CONFIRMED ? 'viewProxyShipment' : 'createProxyShipment'
            )
            ->recordActions([
                Action::make('createProxyShipment')
                    ->label('欠品処理')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->visible(function (WmsShortage $record): bool {
                        if (!in_array($record->status, ['OPEN', 'REALLOCATING'])) {
                            return false;
                        }
                        // Use eager loaded sum to check remaining qty
                        $allocated = $record->allocations_total_qty ?? 0;
                        $remaining = max(0, $record->shortage_qty_each - $allocated);
                        return $remaining > 0;
                    })
                    ->schema([
                        Section::make('元商品情報')
                            ->schema([
                                \Filament\Schemas\Components\View::make('filament.components.shortage-info-table')
                                    ->viewData(function (WmsShortage $record) {
                                        $caseLabel = QuantityType::CASE->name();
                                        $pieceLabel = QuantityType::PIECE->name();

                                        // 容量
                                        $volumeValue = '-';
                                        if ($record->item->volume) {
                                            $unit = \App\Enums\EVolumeUnit::tryFrom($record->item->volume_unit);
                                            $volumeValue = $record->item->volume . ($unit ? $unit->name() : '');
                                        }

                                        // 受注数
                                        $orderQtyDisplay = $record->convertToCaseDisplay($record->order_qty_each);
                                        $orderQtyValue = $orderQtyDisplay['case'] > 0
                                            ? ($orderQtyDisplay['piece'] > 0
                                                ? "{$orderQtyDisplay['case']}{$caseLabel} {$orderQtyDisplay['piece']}{$pieceLabel}"
                                                : "{$orderQtyDisplay['case']}{$caseLabel}")
                                            : "{$orderQtyDisplay['piece']}{$pieceLabel}";

                                        // 欠品内訳
                                        $shortageDetailsParts = [];
                                        if ($record->allocation_shortage_qty > 0) {
                                            $display = $record->convertToCaseDisplay($record->allocation_shortage_qty);
                                            $qtyStr = $display['case'] > 0
                                                ? ($display['piece'] > 0
                                                    ? "{$display['case']}{$caseLabel} {$display['piece']}{$pieceLabel}"
                                                    : "{$display['case']}{$caseLabel}")
                                                : "{$display['piece']}{$pieceLabel}";
                                            $shortageDetailsParts[] = "引当時: {$qtyStr}";
                                        }
                                        if ($record->picking_shortage_qty > 0) {
                                            $display = $record->convertToCaseDisplay($record->picking_shortage_qty);
                                            $qtyStr = $display['case'] > 0
                                                ? ($display['piece'] > 0
                                                    ? "{$display['case']}{$caseLabel} {$display['piece']}{$pieceLabel}"
                                                    : "{$display['case']}{$caseLabel}")
                                                : "{$display['piece']}{$pieceLabel}";
                                            $shortageDetailsParts[] = "ピッキング時: {$qtyStr}";
                                        }
                                        $shortageDetailsValue = implode(' / ', $shortageDetailsParts);

                                        // 合計欠品数
                                        $totalShortageDisplay = $record->convertToCaseDisplay($record->shortage_qty_each);
                                        $totalShortageValue = $totalShortageDisplay['case'] > 0
                                            ? ($totalShortageDisplay['piece'] > 0
                                                ? "{$totalShortageDisplay['case']}{$caseLabel} {$totalShortageDisplay['piece']}{$pieceLabel}"
                                                : "{$totalShortageDisplay['case']}{$caseLabel}")
                                            : "{$totalShortageDisplay['piece']}{$pieceLabel}";

                                        // 残欠品数
                                        $allocated = $record->allocations_total_qty ?? 0;
                                        $remaining = max(0, $record->shortage_qty_each - $allocated);
                                        $remainingDisplay = $record->convertToCaseDisplay($remaining);
                                        $remainingValue = $remainingDisplay['case'] > 0
                                            ? ($remainingDisplay['piece'] > 0
                                                ? "{$remainingDisplay['case']}{$caseLabel} {$remainingDisplay['piece']}{$pieceLabel}"
                                                : "{$remainingDisplay['case']}{$caseLabel}")
                                            : "{$remainingDisplay['piece']}{$pieceLabel}";

                                        return [
                                            'data' => [
                                                [
                                                    'label' => '商品名',
                                                    'value' => $record->item->name ?? '-',
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
                                                    'label' => '入り数',
                                                    'value' => $record->item->capacity_case
                                                        ? $record->item->capacity_case . $pieceLabel . '/' . $caseLabel
                                                        : '-',
                                                ],
                                                [
                                                    'label' => '容量',
                                                    'value' => $volumeValue,
                                                ],
                                                [
                                                    'label' => '受注単位',
                                                    'value' => QuantityType::tryFrom($record->qty_type_at_order)?->name() ?? $record->qty_type_at_order,
                                                ],
                                                [
                                                    'label' => '受注数',
                                                    'value' => $orderQtyValue,
                                                ],
                                                [
                                                    'label' => '欠品内訳',
                                                    'value' => $shortageDetailsValue,
                                                ],
                                                [
                                                    'label' => '合計欠品数',
                                                    'value' => $totalShortageValue,
                                                ],
                                                [
                                                    'label' => '残欠品数',
                                                    'value' => $remainingValue,
                                                    'bold' => true,
                                                ],
                                            ],
                                        ];
                                    }),
                            ]),

                        Repeater::make('allocations')
                            ->label('移動出荷指示')
                            ->schema([
                                TextInput::make('id')
                                    ->label('ID')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visible(fn ($state) => !empty($state)),

                                Select::make('from_warehouse_id')
                                    ->label('移動出荷倉庫')
                                    ->options(function (WmsShortage $record) {
                                        // 欠品元倉庫以外の倉庫を取得
                                        return Warehouse::where('id', '!=', $record->warehouse_id)
                                            ->pluck('name', 'id')
                                            ->toArray();
                                    })
                                    ->required()
                                    ->searchable(),

                                TextInput::make('assign_qty')
                                    ->label('移動出荷数量')
                                    ->numeric()
                                    ->required()
                                    ->minValue(1),

                                Select::make('assign_qty_type')
                                    ->label('単位')
                                    ->options(function (WmsShortage $record) {
                                        // 受注単位に合わせて固定
                                        $qtyType = QuantityType::tryFrom($record->qty_type_at_order);
                                        return [$qtyType->value => $qtyType->name()];
                                    })
                                    ->default(fn (WmsShortage $record) => $record->qty_type_at_order)
                                    ->disabled()
                                    ->dehydrated()
                                    ->required(),

                                TextInput::make('status')
                                    ->label('ステータス')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visible(fn ($state) => !empty($state))
                                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                                        'PENDING' => '指示待ち',
                                        'RESERVED' => '引当済み',
                                        'PICKING' => 'ピッキング中',
                                        'FULFILLED' => '完了',
                                        'SHORTAGE' => '代理側欠品',
                                        'CANCELLED' => 'キャンセル',
                                        default => $state ?? '-',
                                    }),
                            ])
                            ->default(function (WmsShortage $record) {
                                // 既存の移動出荷指示を取得（キャンセル以外）
                                $allocations = $record->allocations()
                                    ->with('fromWarehouse')
                                    ->whereNotIn('status', ['CANCELLED'])
                                    ->get();

                                if ($allocations->isEmpty()) {
                                    return [[]]; // 空の1行を表示
                                }

                                return $allocations->map(function ($allocation) use ($record) {
                                    // PIECE換算からCASE表示に変換
                                    $display = $record->convertToCaseDisplay($allocation->assign_qty_each);

                                    // 元の単位を推測（CASE入数で割り切れればCASE、そうでなければPIECE）
                                    $qtyType = ($allocation->assign_qty_each % $record->case_size_snap === 0 && $display['case'] > 0)
                                        ? QuantityType::CASE->value
                                        : QuantityType::PIECE->value;

                                    $assignQty = $qtyType === QuantityType::CASE->value
                                        ? $display['case']
                                        : $allocation->assign_qty_each;

                                    return [
                                        'id' => $allocation->id,
                                        'from_warehouse_id' => $allocation->from_warehouse_id,
                                        'assign_qty' => $assignQty,
                                        'assign_qty_type' => $qtyType,
                                        'status' => $allocation->status,
                                    ];
                                })->toArray();
                            })
                            ->minItems(1)
                            ->addActionLabel('移動出荷倉庫を追加')
                            ->deleteAction(
                                fn ($action, $state) => $action->hidden(!empty($state['id']))
                            )
                            ->columns(4),
                    ])
                    ->action(function (WmsShortage $record, array $data, Action $action): void {
                        try {
                            $service = app(ProxyShipmentService::class);
                            $createdCount = 0;
                            $updatedCount = 0;

                            foreach ($data['allocations'] as $allocation) {
                                // 既存レコードかどうかを判定
                                if (!empty($allocation['id'])) {
                                    // 既存レコードの更新
                                    $existingAllocation = WmsShortageAllocation::find($allocation['id']);
                                    if ($existingAllocation && in_array($existingAllocation->status, ['PENDING', 'RESERVED'])) {
                                        // PIECE換算に変換
                                        $assignQtyEach = \App\Services\Shortage\QuantityConverter::convertToEach(
                                            $allocation['assign_qty'],
                                            $allocation['assign_qty_type'],
                                            $record->case_size_snap
                                        );

                                        $existingAllocation->update([
                                            'from_warehouse_id' => $allocation['from_warehouse_id'],
                                            'assign_qty_each' => $assignQtyEach,
                                        ]);
                                        $updatedCount++;
                                    }
                                } else {
                                    // 新規作成
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

                            $messages = [];
                            if ($createdCount > 0) {
                                $messages[] = "{$createdCount}件の新規指示を作成";
                            }
                            if ($updatedCount > 0) {
                                $messages[] = "{$updatedCount}件の既存指示を更新";
                            }

                            Notification::make()
                                ->title('移動出荷指示を保存しました')
                                ->body(implode('、', $messages) . 'しました')
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

                Action::make('viewProxyShipment')
                    ->label('欠品処理')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->visible(fn (WmsShortage $record): bool =>
                        $record->status === WmsShortage::STATUS_CONFIRMED
                    )
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->schema([
                        Section::make('商品情報')
                            ->schema([
                                \Filament\Schemas\Components\View::make('filament.components.shortage-info-table')
                                    ->viewData(function (WmsShortage $record) {
                                        $caseLabel = QuantityType::CASE->name();
                                        $pieceLabel = QuantityType::PIECE->name();

                                        // 容量
                                        $volumeValue = '-';
                                        if ($record->item->volume) {
                                            $unit = \App\Enums\EVolumeUnit::tryFrom($record->item->volume_unit);
                                            $volumeValue = $record->item->volume . ($unit ? $unit->name() : '');
                                        }

                                        // 受注数
                                        $orderQtyDisplay = $record->convertToCaseDisplay($record->order_qty_each);
                                        $orderQtyValue = $orderQtyDisplay['case'] > 0
                                            ? ($orderQtyDisplay['piece'] > 0
                                                ? "{$orderQtyDisplay['case']}{$caseLabel} {$orderQtyDisplay['piece']}{$pieceLabel}"
                                                : "{$orderQtyDisplay['case']}{$caseLabel}")
                                            : "{$orderQtyDisplay['piece']}{$pieceLabel}";

                                        // 欠品内訳
                                        $shortageDetailsParts = [];
                                        if ($record->allocation_shortage_qty > 0) {
                                            $display = $record->convertToCaseDisplay($record->allocation_shortage_qty);
                                            $qtyStr = $display['case'] > 0
                                                ? ($display['piece'] > 0
                                                    ? "{$display['case']}{$caseLabel} {$display['piece']}{$pieceLabel}"
                                                    : "{$display['case']}{$caseLabel}")
                                                : "{$display['piece']}{$pieceLabel}";
                                            $shortageDetailsParts[] = "引当時: {$qtyStr}";
                                        }
                                        if ($record->picking_shortage_qty > 0) {
                                            $display = $record->convertToCaseDisplay($record->picking_shortage_qty);
                                            $qtyStr = $display['case'] > 0
                                                ? ($display['piece'] > 0
                                                    ? "{$display['case']}{$caseLabel} {$display['piece']}{$pieceLabel}"
                                                    : "{$display['case']}{$caseLabel}")
                                                : "{$display['piece']}{$pieceLabel}";
                                            $shortageDetailsParts[] = "ピッキング時: {$qtyStr}";
                                        }
                                        $shortageDetailsValue = implode(' / ', $shortageDetailsParts);

                                        // 合計欠品数
                                        $totalShortageDisplay = $record->convertToCaseDisplay($record->shortage_qty_each);
                                        $totalShortageValue = $totalShortageDisplay['case'] > 0
                                            ? ($totalShortageDisplay['piece'] > 0
                                                ? "{$totalShortageDisplay['case']}{$caseLabel} {$totalShortageDisplay['piece']}{$pieceLabel}"
                                                : "{$totalShortageDisplay['case']}{$caseLabel}")
                                            : "{$totalShortageDisplay['piece']}{$pieceLabel}";

                                        // 残欠品数
                                        $allocated = $record->allocations_total_qty ?? 0;
                                        $remaining = max(0, $record->shortage_qty_each - $allocated);
                                        $remainingDisplay = $record->convertToCaseDisplay($remaining);
                                        $remainingValue = $remainingDisplay['case'] > 0
                                            ? ($remainingDisplay['piece'] > 0
                                                ? "{$remainingDisplay['case']}{$caseLabel} {$remainingDisplay['piece']}{$pieceLabel}"
                                                : "{$remainingDisplay['case']}{$caseLabel}")
                                            : "{$remainingDisplay['piece']}{$pieceLabel}";

                                        return [
                                            'data' => [
                                                [
                                                    'label' => '最終更新者',
                                                    'value' => $record->updater?->name ?? '-',
                                                ],
                                                [
                                                    'label' => '更新日時',
                                                    'value' => $record->updated_at?->format('Y-m-d H:i') ?? '-',
                                                ],
                                                [
                                                    'label' => '商品名',
                                                    'value' => $record->item->name ?? '-',
                                                ],
                                                [
                                                    'label' => '商品コード',
                                                    'value' => $record->item->code ?? '-',
                                                ],
                                                [
                                                    'label' => '倉庫',
                                                    'value' => $record->warehouse->name ?? '-',
                                                ],
                                                [
                                                    'label' => '入り数',
                                                    'value' => $record->item->capacity_case
                                                        ? $record->item->capacity_case . $pieceLabel . '/' . $caseLabel
                                                        : '-',
                                                ],
                                                [
                                                    'label' => '容量',
                                                    'value' => $volumeValue,
                                                ],
                                                [
                                                    'label' => '受注単位',
                                                    'value' => QuantityType::tryFrom($record->qty_type_at_order)?->name() ?? $record->qty_type_at_order,
                                                ],
                                                [
                                                    'label' => '受注数',
                                                    'value' => $orderQtyValue,
                                                ],
                                                [
                                                    'label' => '欠品内訳',
                                                    'value' => $shortageDetailsValue,
                                                ],
                                                [
                                                    'label' => '合計欠品数',
                                                    'value' => $totalShortageValue,
                                                ],
                                                [
                                                    'label' => '残欠品数',
                                                    'value' => $remainingValue,
                                                    'bold' => true,
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
                                // 既存の移動出荷指示を取得（キャンセル以外）
                                $allocations = $record->allocations()
                                    ->with('fromWarehouse')
                                    ->whereNotIn('status', ['CANCELLED'])
                                    ->get();

                                return $allocations->map(function ($allocation) {
                                    return [
                                        'id' => $allocation->id,
                                        'from_warehouse_id' => $allocation->from_warehouse_id,
                                        'assign_qty' => $allocation->assign_qty_each,
                                        'assign_qty_type' => $allocation->assign_qty_type,
                                    ];
                                })->toArray();
                            }),
                    ]),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('confirmShortage')
                        ->label('欠品処理確定')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('欠品処理を確定しますか？')
                        ->modalDescription('選択された欠品の移動出荷指示を確定し、ピッキング結果に反映します。')
                        ->action(function ($records) {
                            $service = app(ShortageConfirmationService::class);
                            $count = 0;

                            foreach ($records as $shortage) {
                                try {
                                    $service->confirm($shortage);
                                    $count++;
                                } catch (\Exception $e) {
                                    Notification::make()
                                        ->title('エラー')
                                        ->body("欠品ID {$shortage->id} の処理に失敗: {$e->getMessage()}")
                                        ->danger()
                                        ->send();
                                }
                            }

                            if ($count > 0) {
                                Notification::make()
                                    ->title('欠品処理を確定しました')
                                    ->body("{$count}件の欠品処理を確定しました")
                                    ->success()
                                    ->send();
                            }
                        }),

                    BulkAction::make('cancelConfirmation')
                        ->label('欠品処理取消')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('欠品処理確定を取り消しますか？')
                        ->modalDescription('選択された欠品の確定を取り消し、ピッキング結果から移動出荷数量を削除します。')
                        ->action(function ($records) {
                            $service = app(ShortageConfirmationCancelService::class);
                            $count = 0;

                            foreach ($records as $shortage) {
                                try {
                                    $service->cancel($shortage);
                                    $count++;
                                } catch (\Exception $e) {
                                    Notification::make()
                                        ->title('エラー')
                                        ->body("欠品ID {$shortage->id} の取り消しに失敗: {$e->getMessage()}")
                                        ->danger()
                                        ->send();
                                }
                            }

                            if ($count > 0) {
                                Notification::make()
                                    ->title('欠品処理確定を取り消しました')
                                    ->body("{$count}件の欠品処理確定を取り消しました")
                                    ->success()
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->selectCurrentPageOnly()
            ->defaultSort('created_at', 'desc');
    }
}
