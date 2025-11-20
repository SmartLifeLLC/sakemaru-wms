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
use Filament\Forms\Components\Hidden;
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

                TextColumn::make('is_confirmed')
                    ->label('承認')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? '承認済み' : '未承認')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->alignment('center'),

                TextColumn::make('confirmedBy.name')
                    ->label('承認者')
                    ->default('-')
                    ->alignment('center'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'BEFORE' => 'danger',
                        'REALLOCATING' => 'warning',
                        'SHORTAGE' => 'info',
                        'PARTIAL_SHORTAGE' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
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

                TextColumn::make('order_qty')
                    ->label('受注')
                    ->alignment('center'),

                TextColumn::make('shortage_qty')
                    ->label('欠品')
                    ->color(fn ($record) => $record->shortage_qty > 0 ? 'danger' : 'gray')
                    ->weight('bold')
                    ->alignment('center'),

                TextColumn::make('allocations_total_qty')
                    ->label('移動出荷')
                    ->formatStateUsing(function ($record) {
                        $qty = $record->allocations_total_qty ?? 0;
                        return $qty > 0 ? (string)$qty : '-';
                    })
                    ->color(fn ($record) => ($record->allocations_total_qty ?? 0) > 0 ? 'info' : 'gray')
                    ->alignment('center'),

                TextColumn::make('remaining_qty')
                    ->label('残欠品')
                    ->formatStateUsing(function ($record) {
                        $qty = $record->remaining_qty;
                        return $qty > 0 ? (string)$qty : '-';
                    })
                    ->color(fn ($record) => $record->remaining_qty > 0 ? 'warning' : 'success')
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
                $record->status === WmsShortage::STATUS_SHORTAGE ? 'viewProxyShipment' : 'createProxyShipment'
            )
            ->recordActions([
                Action::make('createProxyShipment')
                    ->label('欠品処理')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->modalHeading('')
                    ->schema([
                        Section::make()
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

                                        // 受注数（数字のみ）
                                        $orderQtyValue = (string)$record->order_qty;

                                        // 引当数（picked_qty）
                                        $plannedQtyValue = (string)$record->picked_qty;

                                        // 欠品内訳（数字のみ）
                                        $shortageDetailsParts = [];
                                        if ($record->allocation_shortage_qty > 0) {
                                            $shortageDetailsParts[] = "引当時: {$record->allocation_shortage_qty}";
                                        }
                                        if ($record->picking_shortage_qty > 0) {
                                            $shortageDetailsParts[] = "ピッキング時: {$record->picking_shortage_qty}";
                                        }
                                        $shortageDetailsValue = implode(' / ', $shortageDetailsParts);

                                        // 欠品数（数字のみ）
                                        $shortageQtyValue = $record->shortage_qty > 0
                                            ? (string)$record->shortage_qty
                                            : '-';

                                        // 移動出荷数（数字のみ）
                                        $allocatedQtyValue = ($record->allocations_total_qty ?? 0) > 0
                                            ? (string)($record->allocations_total_qty ?? 0)
                                            : '-';

                                        // 残欠品数（数字のみ）
                                        $remainingQty = $record->remaining_qty;
                                        $remainingValue = $remainingQty > 0
                                            ? (string)$remainingQty
                                            : '-';

                                        return [
                                            'data' => [
                                                // 1行目
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
                                                // 2行目
                                                [
                                                    'label' => '元倉庫',
                                                    'value' => $record->warehouse->name ?? '-',
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

                        Section::make()
                            ->schema([
                                TextInput::make('allocated_display')
                                    ->label('移動出荷数（リアルタイム計算）')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->default(fn (WmsShortage $record) => $record->allocations_total_qty ?? 0)
                                    ->live()
                                    ->extraInputAttributes(['class' => 'text-blue-600 font-bold text-lg'])
                                    ->columnSpan(1),

                                TextInput::make('remaining_display')
                                    ->label('残欠品数（リアルタイム計算）')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->default(fn (WmsShortage $record) => $record->remaining_qty)
                                    ->live()
                                    ->extraInputAttributes(['class' => 'text-red-600 font-bold text-lg'])
                                    ->columnSpan(1),
                            ])
                            ->columns(2)
                            ->compact(),

                        Repeater::make('allocations')
                            ->label('移動出荷指示')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, $set, WmsShortage $record, $livewire) {
                                // 移動出荷数量の合計を計算
                                $totalAllocated = collect($state ?? [])->sum('assign_qty');
                                // 残欠品数を計算
                                $remaining = max(0, $record->shortage_qty - $totalAllocated);

                                // 移動出荷数のフィールドを更新
                                $set('allocated_display', $totalAllocated > 0 ? (string)$totalAllocated : '0');
                                // 残欠品数のフィールドを更新
                                $set('remaining_display', $remaining > 0 ? (string)$remaining : '0');

                                // バリデーションエラーをクリア
                                $livewire->resetValidation();
                            })
                            ->rules([
                                function (WmsShortage $record) {
                                    return function (string $attribute, $value, \Closure $fail) use ($record) {
                                        if (!is_array($value)) {
                                            return;
                                        }

                                        // 倉庫の重複チェック
                                        $warehouseIds = collect($value)
                                            ->pluck('from_warehouse_id')
                                            ->filter();

                                        if ($warehouseIds->count() !== $warehouseIds->unique()->count()) {
                                            $fail('同じ倉庫を複数選択することはできません。');
                                        }

                                        // 元倉庫が含まれていないか確認
                                        if ($warehouseIds->contains($record->warehouse_id)) {
                                            $fail('元倉庫を移動出荷倉庫として選択することはできません。');
                                        }

                                        // 移動出荷総数が欠品数を超えないかチェック
                                        $totalAllocated = collect($value)->sum('assign_qty');
                                        if ($totalAllocated > $record->shortage_qty) {
                                            $qtyType = \App\Enums\QuantityType::tryFrom($record->qty_type_at_order);
                                            $unit = $qtyType ? $qtyType->name() : $record->qty_type_at_order;
                                            $fail("移動出荷総数（{$totalAllocated}{$unit}）が欠品数（{$record->shortage_qty}{$unit}）を超えています。");
                                        }
                                    };
                                },
                            ])
                            ->schema([
                                Hidden::make('id'),

                                Select::make('from_warehouse_id')
                                    ->label('移動出荷倉庫')
                                    ->options(function (WmsShortage $record) {
                                        // 欠品元倉庫以外の倉庫を取得
                                        return Warehouse::where('id', '!=', $record->warehouse_id)
                                            ->pluck('name', 'id')
                                            ->toArray();
                                    })
                                    ->required()
                                    ->searchable()
                                    ->reactive()
                                    ->disableOptionWhen(function ($value, WmsShortage $record, $get) {
                                        // 元倉庫は選択不可
                                        if ($value == $record->warehouse_id) {
                                            return true;
                                        }

                                        // 他の行で既に選択されている倉庫は選択不可
                                        // 現在の行のインデックスを取得
                                        $statePath = $get('statePath');
                                        $allocations = $get('../../allocations') ?? [];

                                        $selectedWarehouses = collect($allocations)
                                            ->filter(function ($allocation, $index) use ($statePath) {
                                                // 現在の行は除外
                                                return !str_contains($statePath ?? '', "allocations.{$index}");
                                            })
                                            ->pluck('from_warehouse_id')
                                            ->filter()
                                            ->values()
                                            ->toArray();

                                        return in_array($value, $selectedWarehouses);
                                    }),

                                TextInput::make('assign_qty')
                                    ->label('移動出荷数量')
                                    ->numeric()
                                    ->required()
                                    ->minValue(1)
                                    ->integer()
                                    ->inputMode('numeric')
                                    ->live(onBlur: true)
                                    ->extraInputAttributes([
                                        'pattern' => '[0-9]*',
                                        'type' => 'number',
                                        'min' => '1',
                                        'step' => '1',
                                        'oninput' => 'this.value = this.value.replace(/[^0-9]/g, "")',
                                    ])
                                    ->rule('regex:/^[0-9]+$/'),

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
                                    // 受注単位ベースの数量をそのまま使用
                                    return [
                                        'id' => $allocation->id,
                                        'from_warehouse_id' => $allocation->from_warehouse_id,
                                        'assign_qty' => $allocation->assign_qty,
                                        'assign_qty_type' => $record->qty_type_at_order,
                                    ];
                                })->toArray();
                            })
                            ->minItems(1)
                            ->addActionLabel('移動出荷倉庫を追加')
                            ->deleteAction(
                                fn ($action, $state) => $action->visible(empty($state['id']))
                            )
                            ->columns(4),
                    ])
                    ->action(function (WmsShortage $record, array $data, Action $action): void {
                        try {
                            $service = app(ProxyShipmentService::class);
                            $createdCount = 0;
                            $updatedCount = 0;
                            $deletedCount = 0;

                            // 現在のフォームデータに含まれるIDのリスト
                            $formAllocationIds = collect($data['allocations'] ?? [])
                                ->pluck('id')
                                ->filter()
                                ->toArray();

                            // 既存の全てのアクティブな移動出荷指示を取得
                            $existingAllocations = $record->allocations()
                                ->whereNotIn('status', ['CANCELLED'])
                                ->get();

                            // フォームから削除された項目を検出してキャンセル
                            foreach ($existingAllocations as $existing) {
                                if (!in_array($existing->id, $formAllocationIds)) {
                                    // フォームから削除されたのでキャンセル
                                    $service->cancelProxyShipment($existing, auth()->id() ?? 0);
                                    $deletedCount++;
                                }
                            }

                            // フォームデータを処理
                            foreach ($data['allocations'] as $allocation) {
                                // 既存レコードかどうかを判定
                                if (!empty($allocation['id'])) {
                                    // 既存レコードの更新
                                    $existingAllocation = WmsShortageAllocation::find($allocation['id']);
                                    if ($existingAllocation && in_array($existingAllocation->status, ['PENDING', 'RESERVED'])) {
                                        // 受注単位ベースで保存
                                        $existingAllocation->update([
                                            'from_warehouse_id' => $allocation['from_warehouse_id'],
                                            'assign_qty' => $allocation['assign_qty'],
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
                            if ($deletedCount > 0) {
                                $messages[] = "{$deletedCount}件の指示を削除";
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
                        $record->status === WmsShortage::STATUS_SHORTAGE
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

                                        // 受注数（数字のみ）
                                        $orderQtyValue = (string)$record->order_qty;

                                        // 引当数（picked_qty）
                                        $plannedQtyValue = (string)$record->picked_qty;

                                        // 欠品内訳（数字のみ）
                                        $shortageDetailsParts = [];
                                        if ($record->allocation_shortage_qty > 0) {
                                            $shortageDetailsParts[] = "引当時: {$record->allocation_shortage_qty}";
                                        }
                                        if ($record->picking_shortage_qty > 0) {
                                            $shortageDetailsParts[] = "ピッキング時: {$record->picking_shortage_qty}";
                                        }
                                        $shortageDetailsValue = implode(' / ', $shortageDetailsParts);

                                        // 欠品数（数字のみ）
                                        $shortageQtyValue = $record->shortage_qty > 0
                                            ? (string)$record->shortage_qty
                                            : '-';

                                        // 移動出荷数（数字のみ）
                                        $allocatedQtyValue = ($record->allocations_total_qty ?? 0) > 0
                                            ? (string)($record->allocations_total_qty ?? 0)
                                            : '-';

                                        // 残欠品数（数字のみ）
                                        $remainingQty = $record->remaining_qty;
                                        $remainingValue = $remainingQty > 0
                                            ? (string)$remainingQty
                                            : '-';

                                        return [
                                            'data' => [
                                                // 1行目
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
                                                // 2行目
                                                [
                                                    'label' => '元倉庫',
                                                    'value' => $record->warehouse->name ?? '-',
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
                                // 既存の移動出荷指示を取得（キャンセル以外）
                                $allocations = $record->allocations()
                                    ->with('fromWarehouse')
                                    ->whereNotIn('status', ['CANCELLED'])
                                    ->get();

                                return $allocations->map(function ($allocation) {
                                    return [
                                        'id' => $allocation->id,
                                        'from_warehouse_id' => $allocation->from_warehouse_id,
                                        'assign_qty' => $allocation->assign_qty,
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
