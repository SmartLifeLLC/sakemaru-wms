<?php

namespace App\Filament\Resources\WmsShortageAllocations\Tables;

use App\Enums\QuantityType;
use App\Filament\Support\Tables\Columns\QuantityTypeColumn;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortageAllocation;
use App\Services\Shortage\ProxyShipmentService;
use App\Services\Shortage\StockTransferQueueService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use App\Enums\PaginationOptions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WmsShortageAllocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->striped()
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignment('center'),

                TextColumn::make('shortage.warehouse.name')
                    ->label('元倉庫')
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('targetWarehouse.name')
                    ->label('横持ち出荷倉庫')
                    ->sortable()
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('shortage.item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('shortage.item.name')
                    ->label('商品名')
                    ->searchable()
                    ->alignment('center')
                    ->wrap(),

                TextColumn::make('shortage.item.volume')
                    ->label('容量')
                    ->alignment('center')
                    ->formatStateUsing(fn ($record) =>
                        $record->shortage?->item?->volume && $record->shortage?->item?->volume_unit
                            ? $record->shortage->item->volume . \App\Enums\EVolumeUnit::tryFrom($record->shortage->item->volume_unit)?->name()
                            : ''
                    ),

                TextColumn::make('shortage.item.case_size')
                    ->label('入り数')
                    ->alignment('center')
                    ->formatStateUsing(fn ($state) => $state ? $state : '-'),

                TextColumn::make('shortage.trade.partner.name')
                    ->label('得意先')
                    ->searchable()
                    ->limit(20)
                    ->alignment('center'),

                TextColumn::make('deliveryCourse.name')
                    ->label('配送コース')
                    ->searchable()
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: false),

                QuantityTypeColumn::make('assign_qty_type', '単位'),

                TextColumn::make('assign_qty')
                    ->label('予定数')
                    ->alignment('center'),

                TextInputColumn::make('picked_qty')
                    ->label('ピック数')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->disabled(fn (WmsShortageAllocation $record): bool =>
                        !in_array($record->status, ['RESERVED', 'PICKING'])
                    )
                    ->afterStateUpdated(function (WmsShortageAllocation $record, $state) {
                        // picked_qtyがassign_qtyを超えないようにチェック
                        if ($state > $record->assign_qty) {
                            Notification::make()
                                ->title('エラー')
                                ->body("ピック数は予定数（{$record->assign_qty}）を超えることはできません")
                                ->danger()
                                ->send();
                            return;
                        }

                        $record->picked_qty = $state;

                        // ピック数量が入力されたらステータスをPICKINGに変更（完了していない場合）
                        if ($state > 0 && !$record->is_finished) {
                            $record->status = 'PICKING';
                        }

                        $record->save();

                        Notification::make()
                            ->title('ピック数を更新しました')
                            ->success()
                            ->send();
                    })
                    ->alignment('center')
                    ->extraInputAttributes([
                        'class' => 'w-20 !h-8 !py-1 text-center border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 border focus:border-primary-500 focus:ring-primary-500 !text-sm',
                        'style' => 'height: 32px !important; padding-top: 0.25rem !important; padding-bottom: 0.25rem !important;',
                        'min' => '0',
                        'step' => '1',
                        'inputmode' => 'numeric',
                        'pattern' => '[0-9]*',
                    ]),

                TextColumn::make('remaining_qty')
                    ->label('欠品数')
                    ->getStateUsing(fn (WmsShortageAllocation $record): int => $record->remaining_qty)
                    ->color(fn (WmsShortageAllocation $record): string => $record->remaining_qty > 0 ? 'warning' : 'success')
                    ->weight('bold')
                    ->alignment('center'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'gray',
                        'RESERVED' => 'info',
                        'PICKING' => 'warning',
                        'FULFILLED' => 'success',
                        'SHORTAGE' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'PENDING' => '承認待ち',
                        'RESERVED' => '引当済み',
                        'PICKING' => 'ピッキング中',
                        'FULFILLED' => '完了',
                        'SHORTAGE' => '代理側欠品',
                        default => $state,
                    })
                    ->alignment('center'),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'PENDING' => '承認待ち',
                        'RESERVED' => '引当済み',
                        'PICKING' => 'ピッキング中',
                        'FULFILLED' => '完了',
                        'SHORTAGE' => '代理側欠品',
                    ]),

                SelectFilter::make('shortage.warehouse_id')
                    ->label('元倉庫')
                    ->relationship('shortage.warehouse', 'name')
                    ->searchable(),

                SelectFilter::make('target_warehouse_id')
                    ->label('横持ち出荷倉庫')
                    ->relationship('targetWarehouse', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                // 完了ボタン
                Action::make('complete')
                    ->label('完了')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WmsShortageAllocation $record): bool =>
                        !$record->is_finished && in_array($record->status, ['PICKING', 'RESERVED'])
                    )
                    ->requiresConfirmation()
                    ->modalHeading('横持ち出荷の完了確認')
                    ->modalDescription(fn (WmsShortageAllocation $record): string =>
                        "予定数: {$record->assign_qty}、ピック数: {$record->picked_qty}、欠品数: {$record->remaining_qty}"
                    )
                    ->modalSubmitActionLabel('完了')
                    ->action(function (WmsShortageAllocation $record): void {
                        $record->is_finished = true;
                        $record->finished_at = now();
                        $record->finished_user_id = auth()->id();

                        // 完了時のステータス判定
                        if ($record->picked_qty >= $record->assign_qty) {
                            $record->status = 'FULFILLED';
                        } elseif ($record->picked_qty > 0 && $record->remaining_qty > 0) {
                            $record->status = 'SHORTAGE';
                        }

                        $record->save();

                        // 倉庫移動伝票キューを作成
                        try {
                            $queueService = app(StockTransferQueueService::class);
                            $queueId = $queueService->createStockTransferQueue($record);

                            $message = '横持ち出荷を完了しました';
                            if ($queueId) {
                                $message .= "\n倉庫移動伝票キューID: {$queueId}";
                            }

                            Notification::make()
                                ->title($message)
                                ->body("ステータス: {$record->status}")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('完了しましたが、倉庫移動伝票の作成でエラーが発生しました')
                                ->body($e->getMessage())
                                ->warning()
                                ->send();
                        }
                    }),

                // 追加の横持ち出荷アクション（残数量がある場合のみ）
                Action::make('addPartialShipment')
                    ->label('追加横持ち出荷')
                    ->icon('heroicon-o-plus-circle')
                    ->color('info')
                    ->visible(fn (WmsShortageAllocation $record): bool =>
                        $record->remaining_qty > 0 && in_array($record->status, ['PICKING', 'RESERVED'])
                    )
                    ->modalHeading('追加の横持ち出荷指示')
                    ->modalSubmitActionLabel('確定')
                    ->form([
                        \Filament\Forms\Components\ViewField::make('allocations')
                            ->label('横持ち出荷指示')
                            ->live()
                            ->view('filament.forms.components.proxy-shipment-allocations')
                            ->viewData(function (WmsShortageAllocation $record) {
                                // 該当商品の全倉庫在庫を取得（在庫がある倉庫のみ）
                                $stocks = \DB::connection('sakemaru')
                                    ->table('real_stocks')
                                    ->select([
                                        'real_stocks.warehouse_id',
                                        \DB::raw('warehouses.name as warehouse_name'),
                                        \DB::raw('SUM(real_stocks.current_quantity) as total_pieces'),
                                    ])
                                    ->join('warehouses', 'warehouses.id', '=', 'real_stocks.warehouse_id')
                                    ->where('real_stocks.item_id', $record->shortage->item_id)
                                    ->where('real_stocks.current_quantity', '>', 0)
                                    ->where('real_stocks.warehouse_id', '!=', $record->target_warehouse_id) // 現在の倉庫を除外
                                    ->groupBy('real_stocks.warehouse_id', 'warehouses.name')
                                    ->orderBy('warehouses.name')
                                    ->get();

                                $caseSize = $record->shortage->item->capacity_case ?? 1;

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

                                $qtyType = QuantityType::tryFrom($record->assign_qty_type);
                                
                                // 容量
                                $volumeValue = '-';
                                if ($record->shortage->item->volume) {
                                    $unit = \App\Enums\EVolumeUnit::tryFrom($record->shortage->item->volume_unit);
                                    $volumeValue = $record->shortage->item->volume . ($unit ? $unit->name() : '');
                                }

                                // 欠品内訳（このレコードのコンテキストに合わせて表示）
                                $shortageDetailsValue = "残数量: {$record->remaining_qty}";

                                return [
                                    'stocks' => $stockData,
                                    'warehouses' => Warehouse::where('id', '!=', $record->target_warehouse_id)->pluck('name', 'id')->toArray(), // 現在の倉庫を除外
                                    'shortage_qty' => $record->remaining_qty, // この割り当ての残数を上限とする
                                    'qty_type' => $record->assign_qty_type,
                                    'qty_type_label' => $qtyType ? $qtyType->name() : $record->assign_qty_type,
                                    // Info Table Data
                                    'item_code' => $record->shortage->item->code ?? '-',
                                    'item_name' => $record->shortage->item->name ?? '-',
                                    'capacity_case' => $record->shortage->item->capacity_case ? (string)$record->shortage->item->capacity_case : '-',
                                    'volume_value' => $volumeValue,
                                    'partner_code' => $record->shortage->trade->partner->code ?? '-',
                                    'partner_name' => $record->shortage->trade->partner->name ?? '-',
                                    'warehouse_name' => $record->shortage->warehouse->name ?? '-',
                                    'order_qty' => (string)$record->assign_qty, // 横持ち出荷指示数
                                    'picked_qty' => (string)$record->picked_qty, // ピッキング済み数
                                    'picked_qty_label' => 'ピック数',
                                    'shortage_details' => $shortageDetailsValue,
                                ];
                            })
                            ->default([]) // 新規追加なので空
                            ->rules([
                                function (WmsShortageAllocation $record) {
                                    return function (string $attribute, $value, \Closure $fail) use ($record) {
                                        if (!is_array($value)) {
                                            return;
                                        }

                                        $totalAllocated = collect($value)->sum(function ($item) {
                                            $qty = $item['assign_qty'] ?? 0;
                                            return is_numeric($qty) ? (int)$qty : 0;
                                        });

                                        if ($totalAllocated > $record->remaining_qty) {
                                            $qtyType = QuantityType::tryFrom($record->assign_qty_type);
                                            $unit = $qtyType ? $qtyType->name() : $record->assign_qty_type;
                                            $fail("追加出荷総数（{$totalAllocated}{$unit}）が残数量（{$record->remaining_qty}{$unit}）を超えています。");
                                        }

                                        $selectedWarehouses = collect($value)
                                            ->pluck('from_warehouse_id')
                                            ->filter()
                                            ->toArray();

                                        // 現在の倉庫が含まれていないかチェック
                                        if (in_array($record->target_warehouse_id, $selectedWarehouses)) {
                                            $fail("現在の出荷元倉庫と同じ倉庫は選択できません。");
                                        }

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
                    ->action(function (WmsShortageAllocation $record, array $data): void {
                        try {
                            $service = app(ProxyShipmentService::class);
                            $createdCount = 0;
                            $totalReallocatedQty = 0;

                            DB::transaction(function () use ($record, $data, $service, &$createdCount, &$totalReallocatedQty) {
                                foreach ($data['allocations'] as $allocation) {
                                    if (empty($allocation['assign_qty']) || $allocation['assign_qty'] <= 0) {
                                        continue;
                                    }

                                    // 新しい横持ち出荷レコードを作成
                                    $service->createProxyShipment(
                                        shortage: $record->shortage,
                                        fromWarehouseId: $allocation['from_warehouse_id'],
                                        assignQty: $allocation['assign_qty'],
                                        assignQtyType: $record->assign_qty_type,
                                        createdBy: auth()->id() ?? 0
                                    );
                                    $createdCount++;
                                    $totalReallocatedQty += (int)$allocation['assign_qty'];
                                }

                                if ($createdCount > 0) {
                                    // 元の横持ち出荷指示の数量を減らす
                                    $record->assign_qty -= $totalReallocatedQty;
                                    $record->save();

                                    // 欠品情報の承認状態を解除
                                    $record->shortage->is_confirmed = false;
                                    $record->shortage->save();
                                }
                            });

                            if ($createdCount > 0) {
                                Notification::make()
                                    ->title("追加の横持ち出荷を{$createdCount}件作成しました")
                                    ->body("元の出荷指示から{$totalReallocatedQty}個を振り分けました。承認状態が解除されました。")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('追加の出荷指示がありませんでした')
                                    ->warning()
                                    ->send();
                            }
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
                    BulkAction::make('bulkComplete')
                        ->label('一括完了')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('横持ち出荷の一括完了確認')
                        ->modalDescription(fn (Collection $records): string =>
                            "選択された {$records->count()} 件の横持ち出荷を完了します。"
                        )
                        ->modalSubmitActionLabel('完了')
                        ->action(function (Collection $records): void {
                            $userId = auth()->id();
                            $completedCount = 0;
                            $fulfilledCount = 0;
                            $shortageCount = 0;
                            $queueCreatedCount = 0;
                            $queueErrorCount = 0;

                            $queueService = app(StockTransferQueueService::class);

                            foreach ($records as $record) {
                                // 既に完了している、またはステータスがPICKING/RESERVED以外の場合はスキップ
                                if ($record->is_finished || !in_array($record->status, ['PICKING', 'RESERVED'])) {
                                    continue;
                                }

                                $record->is_finished = true;
                                $record->finished_at = now();
                                $record->finished_user_id = $userId;

                                // 完了時のステータス判定
                                if ($record->picked_qty >= $record->assign_qty) {
                                    $record->status = 'FULFILLED';
                                    $fulfilledCount++;
                                } elseif ($record->picked_qty > 0 && $record->remaining_qty > 0) {
                                    $record->status = 'SHORTAGE';
                                    $shortageCount++;
                                }

                                $record->save();
                                $completedCount++;

                                // 倉庫移動伝票キューを作成
                                try {
                                    $queueId = $queueService->createStockTransferQueue($record);
                                    if ($queueId) {
                                        $queueCreatedCount++;
                                    }
                                } catch (\Exception $e) {
                                    $queueErrorCount++;
                                    \Log::error('Failed to create stock transfer queue in bulk action', [
                                        'allocation_id' => $record->id,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }

                            $message = "完了件数: {$completedCount}件 (完了: {$fulfilledCount}件、欠品: {$shortageCount}件)";
                            if ($queueCreatedCount > 0) {
                                $message .= "\n倉庫移動伝票キュー作成: {$queueCreatedCount}件";
                            }
                            if ($queueErrorCount > 0) {
                                $message .= "\n倉庫移動伝票キューエラー: {$queueErrorCount}件";
                            }

                            Notification::make()
                                ->title('横持ち出荷を一括完了しました')
                                ->body($message)
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(null);
    }
}
