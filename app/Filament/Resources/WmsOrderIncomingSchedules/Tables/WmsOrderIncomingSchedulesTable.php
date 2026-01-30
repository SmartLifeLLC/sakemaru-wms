<?php

namespace App\Filament\Resources\WmsOrderIncomingSchedules\Tables;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\ItemDefaultLocation;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingConfirmationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class WmsOrderIncomingSchedulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'incoming-schedules-table sticky-actions'])
            ->columns([
                TextColumn::make('batch_code')
                    ->label('実行CD')
                    ->state(function ($record) {
                        // orderCandidate または transferCandidate から batch_code を取得
                        return $record->orderCandidate?->batch_code
                            ?? $record->transferCandidate?->batch_code
                            ?? '-';
                    })
                    ->searchable(query: function ($query, string $search) {
                        $query->where(function ($q) use ($search) {
                            $q->whereHas('orderCandidate', fn ($sub) => $sub->where('batch_code', 'like', "%{$search}%"))
                              ->orWhereHas('transferCandidate', fn ($sub) => $sub->where('batch_code', 'like', "%{$search}%"));
                        });
                    })
                    ->copyable()
                    ->width('120px'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (IncomingScheduleStatus $state): string => $state->label())
                    ->color(fn (IncomingScheduleStatus $state): string => $state->color())
                    ->sortable()
                    ->width('90px'),

                TextColumn::make('order_source')
                    ->label('入庫区分')
                    ->badge()
                    ->formatStateUsing(fn (OrderSource $state): string => match ($state) {
                        OrderSource::AUTO => '発注',
                        OrderSource::MANUAL => '手動',
                        OrderSource::TRANSFER => '移動',
                    })
                    ->color(fn (OrderSource $state): string => match ($state) {
                        OrderSource::AUTO => 'info',
                        OrderSource::MANUAL => 'gray',
                        OrderSource::TRANSFER => 'warning',
                    })
                    ->width('60px'),

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->searchable()
                    ->alignCenter()
                    ->width('50px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->width('120px'),

                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->searchable()
                    ->alignCenter()
                    ->toggleable()
                    ->width('50px'),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->searchable()
                    ->toggleable()
                    ->width('100px'),

                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('search_code')
                    ->label('検索CD')
                    ->searchable()
                    ->limit(20)
                    ->placeholder('-')
                    ->width('120px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->grow(),

                TextColumn::make('current_stock')
                    ->label('現在庫')
                    ->state(function ($record) {
                        if (! $record->warehouse_id || ! $record->item_id) {
                            return null;
                        }

                        return RealStock::where('warehouse_id', $record->warehouse_id)
                            ->where('item_id', $record->item_id)
                            ->sum('current_quantity');
                    })
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('available_stock')
                    ->label('有効在庫')
                    ->state(function ($record) {
                        if (! $record->warehouse_id || ! $record->item_id) {
                            return null;
                        }

                        return RealStock::where('warehouse_id', $record->warehouse_id)
                            ->where('item_id', $record->item_id)
                            ->sum('available_quantity');
                    })
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('expected_quantity')
                    ->label('予定数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('remaining')
                    ->label('残数')
                    ->state(fn ($record) => $record->remaining_quantity)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => $record->remaining_quantity > 0 ? 'warning' : 'success')
                    ->width('70px'),

                TextInputColumn::make('received_quantity')
                    ->label('入庫検品数')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->alignEnd()
                    ->width('70px')
                    ->extraInputAttributes(['style' => 'width: 65px; text-align: right;'])
                    ->disabled(fn ($record) => $record->status === IncomingScheduleStatus::CONFIRMED)
                    ->afterStateUpdated(function ($record, $state) {
                        if ($record->status === IncomingScheduleStatus::CONFIRMED) {
                            Notification::make()
                                ->title('確定済みのレコードは変更できません')
                                ->danger()
                                ->send();

                            return;
                        }

                        $newQty = (int) $state;
                        $expectedQty = $record->expected_quantity;

                        // ステータスを更新
                        $newStatus = $record->status;
                        if ($newQty >= $expectedQty) {
                            $newStatus = IncomingScheduleStatus::CONFIRMED;
                        } elseif ($newQty > 0) {
                            $newStatus = IncomingScheduleStatus::PARTIAL;
                        } else {
                            $newStatus = IncomingScheduleStatus::PENDING;
                        }

                        $record->update([
                            'received_quantity' => $newQty,
                            'status' => $newStatus,
                        ]);

                        Notification::make()
                            ->title('入庫検品数を更新しました')
                            ->success()
                            ->send();
                    }),

                TextColumn::make('quantity_type')
                    ->label('単位')
                    ->formatStateUsing(fn ($state) => match ($state?->value ?? $state) {
                        'PIECE' => 'バラ',
                        'CASE' => 'ケース',
                        'CARTON' => 'ボール',
                        default => '-',
                    })
                    ->alignCenter()
                    ->width('60px'),

                TextColumn::make('order_date')
                    ->label('発注日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('expected_arrival_date')
                    ->label('入庫予定日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('80px'),

                TextColumn::make('expiration_date')
                    ->label('賞味期限')
                    ->date('Y/m/d')
                    ->sortable()
                    ->alignCenter()
                    ->placeholder('-')
                    ->width('90px'),

                TextColumn::make('default_location')
                    ->label('ロケーション')
                    ->state(function ($record) {
                        if (! $record->warehouse_id || ! $record->item_id) {
                            return null;
                        }
                        $location = ItemDefaultLocation::getDefaultLocation(
                            $record->warehouse_id,
                            $record->item_id
                        );
                        if (! $location) {
                            return null;
                        }

                        return "{$location->code1}-{$location->code2}-{$location->code3}";
                    })
                    ->placeholder('-')
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('order_candidate_id')
                    ->label('発注候補ID')
                    ->alignCenter()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('80px'),

                TextColumn::make('manual_order_number')
                    ->label('発注番号')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),

                TextColumn::make('purchase_slip_number')
                    ->label('仕入伝票')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),

                TextColumn::make('note')
                    ->label('備考')
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(collect(IncomingScheduleStatus::cases())->mapWithKeys(fn ($status) => [
                        $status->value => $status->label(),
                    ])),

                SelectFilter::make('order_source')
                    ->label('入庫区分')
                    ->options([
                        'AUTO' => '発注',
                        'MANUAL' => '手動',
                        'TRANSFER' => '移動',
                    ]),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(fn () => Warehouse::query()
                        ->where('is_active', true)
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return Warehouse::query()
                            ->where('is_active', true)
                            ->where(function ($query) use ($search) {
                                $query->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
                            ->toArray();
                    }),

                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->multiple()
                    ->options(fn () => Contractor::query()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"]))
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return Contractor::query()
                            ->where(function ($query) use ($search) {
                                $query->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"])
                            ->toArray();
                    }),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('入庫')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => in_array($record->status, [
                        IncomingScheduleStatus::PENDING,
                        IncomingScheduleStatus::PARTIAL,
                    ]))
                    ->modalHeading('入庫')
                    ->schema(function ($record) {
                        // デフォルトロケーションを取得
                        $defaultLocation = ItemDefaultLocation::getDefaultLocation(
                            $record->warehouse_id,
                            $record->item_id
                        );

                        return [
                            TextInput::make('received_quantity')
                                ->label('入庫数量')
                                ->numeric()
                                ->required()
                                ->default($record->remaining_quantity)
                                ->helperText("残数: {$record->remaining_quantity}"),

                            DatePicker::make('actual_date')
                                ->label('入荷日')
                                ->default(fn () => $record->actual_arrival_date ?? ClientSetting::systemDate())
                                ->required(),

                            DatePicker::make('expiration_date')
                                ->label('賞味期限')
                                ->default($record->expiration_date)
                                ->helperText('商品の賞味期限を入力してください（任意）'),

                            Select::make('location_id')
                                ->label('ロケーション')
                                ->options(fn () => Location::query()
                                    ->where('warehouse_id', $record->warehouse_id)
                                    ->orderBy('code1')
                                    ->orderBy('code2')
                                    ->orderBy('code3')
                                    ->get()
                                    ->mapWithKeys(fn ($loc) => [
                                        $loc->id => "{$loc->code1}-{$loc->code2}-{$loc->code3}".($loc->name ? " ({$loc->name})" : ''),
                                    ]))
                                ->default($defaultLocation?->id)
                                ->searchable()
                                ->helperText($defaultLocation
                                    ? "デフォルト: {$defaultLocation->code1}-{$defaultLocation->code2}-{$defaultLocation->code3}"
                                    : 'デフォルトロケーション未設定'),
                        ];
                    })
                    ->action(function ($record, array $data) {
                        $service = app(IncomingConfirmationService::class);

                        try {
                            $receivedQty = (int) $data['received_quantity'];
                            $remainingQty = $record->remaining_quantity;
                            $expirationDate = $data['expiration_date'] ?? null;
                            $locationId = $data['location_id'] ?? null;

                            if ($receivedQty >= $remainingQty) {
                                // 全量入庫
                                $service->confirmIncoming(
                                    $record,
                                    auth()->id(),
                                    $record->expected_quantity,
                                    $data['actual_date'],
                                    $expirationDate,
                                    $locationId
                                );
                                Notification::make()
                                    ->title('入庫を確定しました')
                                    ->success()
                                    ->send();
                            } else {
                                // 一部入庫
                                $service->recordPartialIncoming(
                                    $record,
                                    $receivedQty,
                                    auth()->id(),
                                    $data['actual_date'],
                                    $expirationDate,
                                    $locationId
                                );
                                Notification::make()
                                    ->title('一部入庫を記録しました')
                                    ->body("入庫数: {$receivedQty} / 残数: ".($remainingQty - $receivedQty))
                                    ->success()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('cancel')
                    ->label('取消')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => in_array($record->status, [
                        IncomingScheduleStatus::PENDING,
                        IncomingScheduleStatus::PARTIAL,
                    ]))
                    ->requiresConfirmation()
                    ->modalHeading('入庫予定を取消')
                    ->modalDescription('この入庫予定を取消しますか？')
                    ->schema([
                        Textarea::make('reason')
                            ->label('取消理由'),
                    ])
                    ->action(function ($record, array $data) {
                        $service = app(IncomingConfirmationService::class);

                        try {
                            $service->cancelIncoming(
                                $record,
                                auth()->id(),
                                $data['reason'] ?? ''
                            );
                            Notification::make()
                                ->title('入庫予定を取消しました')
                                ->warning()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('入庫予定詳細')
                    ->modalWidth('6xl')
                    ->infolist(function (?WmsOrderIncomingSchedule $record): array {
                        if (! $record) {
                            return [];
                        }

                        // 発注候補からの計算ログを取得
                        $orderCandidate = $record->orderCandidate;
                        $log = null;
                        $details = [];

                        if ($orderCandidate) {
                            $log = WmsOrderCalculationLog::where('batch_code', $orderCandidate->batch_code)
                                ->where('warehouse_id', $orderCandidate->warehouse_id)
                                ->where('item_id', $orderCandidate->item_id)
                                ->first();
                            $details = $log?->calculation_details ?? [];
                        }

                        // 商品情報
                        $item = $record->item;
                        $capacityText = '-';
                        if ($item) {
                            $parts = [];
                            if ($item->capacity_case) {
                                $parts[] = "ケース: {$item->capacity_case}";
                            }
                            if ($item->capacity_carton) {
                                $parts[] = "ボール: {$item->capacity_carton}";
                            }
                            $capacityText = implode(' / ', $parts) ?: '-';
                        }

                        // 現在の在庫情報を取得
                        $currentStock = 0;
                        $availableStock = 0;
                        if ($record->warehouse_id && $record->item_id) {
                            $stockData = RealStock::where('warehouse_id', $record->warehouse_id)
                                ->where('item_id', $record->item_id)
                                ->selectRaw('SUM(current_quantity) as current_qty, SUM(available_quantity) as available_qty')
                                ->first();
                            $currentStock = $stockData->current_qty ?? 0;
                            $availableStock = $stockData->available_qty ?? 0;
                        }

                        // ロケーション情報
                        $location = ItemDefaultLocation::getDefaultLocation(
                            $record->warehouse_id,
                            $record->item_id
                        );
                        $locationText = $location ? "{$location->code1}-{$location->code2}-{$location->code3}" : '-';

                        return [
                            Grid::make(3)
                                ->schema([
                                    // 左パネル（基本情報）
                                    View::make('filament.components.incoming-schedule-left-panel')
                                        ->viewData([
                                            'warehouseName' => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-',
                                            'contractorName' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                                            'orderSource' => match ($record->order_source) {
                                                OrderSource::AUTO => '発注',
                                                OrderSource::MANUAL => '手動',
                                                OrderSource::TRANSFER => '移動',
                                                default => '-',
                                            },
                                            'orderDate' => $record->order_date?->format('Y/m/d') ?? '-',
                                            'expectedArrivalDate' => $record->expected_arrival_date?->format('Y/m/d') ?? '-',
                                            'locationText' => $locationText,
                                            'itemCode' => $item?->code ?? '-',
                                            'itemName' => $item?->name ?? '-',
                                            'packaging' => $item?->packaging ?? '-',
                                            'capacityText' => $capacityText,
                                        ])
                                        ->columnSpan(1),

                                    // 右パネル（数量・在庫情報）
                                    Section::make('入庫・在庫情報')
                                        ->schema([
                                            View::make('filament.components.incoming-schedule-right-panel')
                                                ->viewData([
                                                    'expectedQuantity' => $record->expected_quantity ?? 0,
                                                    'receivedQuantity' => $record->received_quantity ?? 0,
                                                    'remainingQuantity' => $record->remaining_quantity ?? 0,
                                                    'status' => $record->status->label(),
                                                    'statusColor' => $record->status->color(),
                                                    'currentStock' => $currentStock,
                                                    'availableStock' => $availableStock,
                                                    'hasOrderCandidate' => $orderCandidate !== null,
                                                    'orderCandidateId' => $orderCandidate?->id,
                                                    'batchCode' => $orderCandidate?->batch_code,
                                                    'batchCodeFormatted' => $orderCandidate?->batch_code
                                                        ? \Carbon\Carbon::createFromFormat('YmdHis', $orderCandidate->batch_code)->format('Y/m/d H:i')
                                                        : null,
                                                    'hasCalculationLog' => ! empty($details),
                                                    'formula' => $details['計算式'] ?? '-',
                                                    'effectiveStock' => $details['有効在庫'] ?? 0,
                                                    'incomingStock' => $details['入庫予定数'] ?? 0,
                                                    'safetyStock' => $details['安全在庫'] ?? 0,
                                                    'shortageQty' => $details['不足数'] ?? 0,
                                                    'orderQuantity' => $orderCandidate?->order_quantity ?? $record->expected_quantity,
                                                ]),
                                        ])
                                        ->columnSpan(2),
                                ]),
                        ];
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkUpdateDates')
                        ->label('入荷日・賞味期限を更新')
                        ->icon('heroicon-o-calendar')
                        ->color('info')
                        ->modalHeading('入荷日・賞味期限の一括更新')
                        ->modalDescription('選択した入庫予定の入荷日・賞味期限を更新します（確定は行いません）')
                        ->schema([
                            DatePicker::make('actual_arrival_date')
                                ->label('入荷日')
                                ->helperText('空欄の場合は更新しません'),

                            DatePicker::make('expiration_date')
                                ->label('賞味期限')
                                ->helperText('空欄の場合は更新しません'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $validRecords = $records->filter(fn ($r) => in_array($r->status, [
                                IncomingScheduleStatus::PENDING,
                                IncomingScheduleStatus::PARTIAL,
                            ]));

                            if ($validRecords->isEmpty()) {
                                Notification::make()
                                    ->title('更新可能なレコードがありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $updateData = [];
                            if (! empty($data['actual_arrival_date'])) {
                                $updateData['actual_arrival_date'] = $data['actual_arrival_date'];
                            }
                            if (! empty($data['expiration_date'])) {
                                $updateData['expiration_date'] = $data['expiration_date'];
                            }

                            if (empty($updateData)) {
                                Notification::make()
                                    ->title('更新する項目がありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = 0;
                            foreach ($validRecords as $record) {
                                $record->update($updateData);
                                $count++;
                            }

                            Notification::make()
                                ->title("{$count}件を更新しました")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkConfirm')
                        ->label('選択を入庫確定')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('一括入庫確定')
                        ->modalDescription('選択した入庫予定を全量入庫確定します。')
                        ->schema([
                            DatePicker::make('actual_date')
                                ->label('入庫日')
                                ->default(now())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $service = app(IncomingConfirmationService::class);
                            $validRecords = $records->filter(fn ($r) => in_array($r->status, [
                                IncomingScheduleStatus::PENDING,
                                IncomingScheduleStatus::PARTIAL,
                            ]));

                            if ($validRecords->isEmpty()) {
                                Notification::make()
                                    ->title('確定可能なレコードがありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $result = $service->confirmMultiple(
                                $validRecords->pluck('id')->toArray(),
                                auth()->id(),
                                $data['actual_date']
                            );

                            Notification::make()
                                ->title("{$result['success']}件を入庫確定しました")
                                ->body($result['failed'] > 0 ? "{$result['failed']}件でエラーが発生" : null)
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkCancel')
                        ->label('選択を取消')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('一括取消')
                        ->modalDescription('選択した入庫予定を取消します。')
                        ->schema([
                            Textarea::make('reason')
                                ->label('取消理由'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $service = app(IncomingConfirmationService::class);
                            $validRecords = $records->filter(fn ($r) => in_array($r->status, [
                                IncomingScheduleStatus::PENDING,
                                IncomingScheduleStatus::PARTIAL,
                            ]));

                            if ($validRecords->isEmpty()) {
                                Notification::make()
                                    ->title('取消可能なレコードがありません')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $count = 0;
                            foreach ($validRecords as $record) {
                                try {
                                    $service->cancelIncoming($record, auth()->id(), $data['reason'] ?? '');
                                    $count++;
                                } catch (\Exception $e) {
                                    // Skip errors
                                }
                            }

                            Notification::make()
                                ->title("{$count}件を取消しました")
                                ->warning()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }
}
