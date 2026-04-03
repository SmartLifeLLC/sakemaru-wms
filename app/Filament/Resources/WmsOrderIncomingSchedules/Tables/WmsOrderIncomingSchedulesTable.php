<?php

namespace App\Filament\Resources\WmsOrderIncomingSchedules\Tables;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\ItemDefaultLocation;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingConfirmationService;
use App\Services\AutoOrder\OrderCancellationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class WmsOrderIncomingSchedulesTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'incoming-schedules-table sticky-actions'])
            ->columns([
                TextColumn::make('order_source')
                    ->label('入荷区分')
                    ->badge()
                    ->formatStateUsing(fn (OrderSource $state): string => match ($state) {
                        OrderSource::AUTO => '発注',
                        OrderSource::MANUAL => '手動',
                        OrderSource::TRANSFER => '移動',
                        OrderSource::RECEIVED => '受信',
                    })
                    ->color(fn (OrderSource $state): string => match ($state) {
                        OrderSource::AUTO => 'info',
                        OrderSource::MANUAL => 'gray',
                        OrderSource::TRANSFER => 'warning',
                        OrderSource::RECEIVED => 'success',
                    })
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('order_date')
                    ->label('発注日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('expected_arrival_date')
                    ->label('予定日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

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

                TextColumn::make('expected_quantity')
                    ->label('発注数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('received_quantity')
                    ->label('入荷実績')
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state) : '-')
                    ->alignEnd()
                    ->width('70px'),

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

                TextColumn::make('shipped_quantity')
                    ->label('出荷実績')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px')
                    ->placeholder('-')
                    ->color(fn ($record) => $record->shipped_quantity > 0 && $record->shipped_quantity < $record->expected_quantity ? 'warning' : null),

                TextColumn::make('shortage_quantity')
                    ->label('欠品数')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'danger' : null)
                    ->placeholder('0')
                    ->width('70px'),

                TextColumn::make('purchase_unit_price')
                    ->label('仕入単価')
                    ->state(function ($record) {
                        if ($record->order_source !== OrderSource::RECEIVED) {
                            return $record->unit_price ?? $record->case_price;
                        }

                        $priceType = $record->price_type ?? 'PIECE';

                        return $priceType === 'CASE'
                            ? $record->partner_case_price
                            : $record->partner_unit_price;
                    })
                    ->money('JPY')
                    ->alignEnd()
                    ->width('90px'),

                // --- 以下、補助カラム ---

                TextColumn::make('slip_number')
                    ->label('伝票番号')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('130px'),

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->searchable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('50px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('120px'),

                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->searchable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('50px'),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),

                TextColumn::make('remaining')
                    ->label('残数')
                    ->state(fn ($record) => $record->remaining_quantity)
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($record) => $record->remaining_quantity > 0 ? 'warning' : 'success')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('70px'),

                TextColumn::make('is_receive_matched')
                    ->label('照合')
                    ->formatStateUsing(fn ($state) => $state ? '済' : '-')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('50px'),

                TextColumn::make('unit_price')
                    ->label('自社単価')
                    ->money('JPY')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('90px'),

                TextColumn::make('partner_unit_price')
                    ->label('仕入先単価')
                    ->money('JPY')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('90px'),

                TextColumn::make('price_mismatch')
                    ->label('単価差')
                    ->state(function ($record) {
                        if ($record->price_type === 'CASE') {
                            if ($record->case_price !== null && $record->partner_case_price !== null
                                && (float) $record->case_price !== (float) $record->partner_case_price) {
                                return '不一致';
                            }
                        } elseif ($record->price_type === 'PIECE') {
                            if ($record->unit_price !== null && $record->partner_unit_price !== null
                                && (float) $record->unit_price !== (float) $record->partner_unit_price) {
                                return '不一致';
                            }
                        }

                        return null;
                    })
                    ->badge()
                    ->color('warning')
                    ->placeholder('-')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('60px'),

                TextColumn::make('quantity_type')
                    ->label('単位')
                    ->formatStateUsing(fn ($state) => match ($state?->value ?? $state) {
                        'PIECE' => 'バラ',
                        'CASE' => 'ケース',
                        'CARTON' => 'ボール',
                        default => '-',
                    })
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('60px'),

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
                Filter::make('expected_arrival_date')
                    ->label('入荷予定日')
                    ->form([
                        DatePicker::make('expected_arrival_date')
                            ->label('入荷予定日')
                            ->default(ClientSetting::systemDateYMD()),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['expected_arrival_date'], fn (Builder $q, $date) => $q->where('expected_arrival_date', $date))
                    )
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['expected_arrival_date']) {
                            return null;
                        }

                        return '入荷予定日: '.\Carbon\Carbon::parse($data['expected_arrival_date'])->format('Y年m月d日');
                    }),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(collect(IncomingScheduleStatus::cases())->mapWithKeys(fn ($status) => [
                        $status->value => $status->label(),
                    ])),

                SelectFilter::make('order_source')
                    ->label('入荷区分')
                    ->options([
                        'AUTO' => '発注',
                        'MANUAL' => '手動',
                        'TRANSFER' => '移動',
                        'RECEIVED' => '受信',
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
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('入荷予定詳細')
                    ->modalWidth('6xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitActionLabel('入荷確定')
                    ->modalSubmitAction(fn ($record, $action) => in_array($record->status, [
                        IncomingScheduleStatus::PENDING,
                        IncomingScheduleStatus::PARTIAL,
                    ]) ? $action->makeModalSubmitAction('submit', [])->label('入荷確定')->color('danger')->requiresConfirmation()
                        ->modalHeading('入荷確定')
                        ->modalDescription('入荷データを確定します。よろしいですか？')
                        ->modalSubmitActionLabel('確定する')
                    : false)
                    ->modalCancelActionLabel('このまま閉じる')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                    ->schema(function (?WmsOrderIncomingSchedule $record): array {
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

                        $defaultLocation = ItemDefaultLocation::getDefaultLocation(
                            $record->warehouse_id,
                            $record->item_id
                        );
                        $locationText = $defaultLocation ? "{$defaultLocation->code1}-{$defaultLocation->code2}-{$defaultLocation->code3}" : '-';

                        $isEditable = in_array($record->status, [
                            IncomingScheduleStatus::PENDING,
                            IncomingScheduleStatus::PARTIAL,
                        ]);

                        $schema = [
                            View::make('filament.components.incoming-schedule-detail')
                                ->viewData([
                                    'orderSource' => match ($record->order_source) {
                                        OrderSource::AUTO => '発注',
                                        OrderSource::MANUAL => '手動',
                                        OrderSource::TRANSFER => '移動',
                                        OrderSource::RECEIVED => '受信',
                                        default => '-',
                                    },
                                    'itemCode' => $item?->code ?? '-',
                                    'itemName' => $item?->name ?? '-',
                                    'packaging' => $item?->packaging ?? '-',
                                    'capacityText' => $capacityText,
                                    'warehouseName' => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-',
                                    'contractorName' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                                    'orderDate' => $record->order_date?->format('Y/m/d') ?? '-',
                                    'expectedArrivalDate' => $record->expected_arrival_date?->format('Y/m/d') ?? '-',
                                    'locationText' => $locationText,
                                    'expectedQuantity' => $record->expected_quantity ?? 0,
                                    'receivedQuantity' => $record->received_quantity ?? 0,
                                    'remainingQuantity' => $record->remaining_quantity ?? 0,
                                    'status' => $record->status->label(),
                                    'statusColor' => $record->status->color(),
                                    'currentStock' => $currentStock,
                                    'availableStock' => $availableStock,
                                    'hasOrderCandidate' => $orderCandidate !== null,
                                    'orderCandidateId' => $orderCandidate?->id,
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
                        ];

                        if ($isEditable) {
                            $schema[] = Grid::make(4)->schema([
                                TextInput::make('received_quantity')
                                    ->label('入荷数量')
                                    ->numeric()
                                    ->required()
                                    ->default($record->remaining_quantity)
                                    ->helperText("残数: {$record->remaining_quantity}"),

                                DatePicker::make('actual_date')
                                    ->label('入荷日')
                                    ->default($record->actual_arrival_date ?? ClientSetting::systemDate())
                                    ->required(),

                                DatePicker::make('expiration_date')
                                    ->label('賞味期限')
                                    ->default($record->expiration_date),

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
                            ]);
                        }

                        return $schema;
                    })
                    ->action(function ($record, array $data) {
                        // 確定済みの場合はスキップ（閉じるだけ）
                        if (! in_array($record->status, [
                            IncomingScheduleStatus::PENDING,
                            IncomingScheduleStatus::PARTIAL,
                        ])) {
                            return;
                        }

                        $service = app(IncomingConfirmationService::class);

                        try {
                            $receivedQty = (int) $data['received_quantity'];
                            $remainingQty = $record->remaining_quantity;
                            $expirationDate = $data['expiration_date'] ?? null;
                            $locationId = $data['location_id'] ?? null;

                            if ($receivedQty >= $remainingQty) {
                                $service->confirmIncoming(
                                    $record,
                                    auth()->id(),
                                    $record->expected_quantity,
                                    $data['actual_date'],
                                    $expirationDate,
                                    $locationId
                                );
                                Notification::make()
                                    ->title('入荷を確定しました')
                                    ->success()
                                    ->send();
                            } else {
                                $service->recordPartialIncoming(
                                    $record,
                                    $receivedQty,
                                    auth()->id(),
                                    $data['actual_date'],
                                    $expirationDate,
                                    $locationId
                                );
                                Notification::make()
                                    ->title('一部入荷を記録しました')
                                    ->body("入荷数: {$receivedQty} / 残数: ".($remainingQty - $receivedQty))
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
                    ->modalHeading('入荷予定を取消')
                    ->extraModalWindowAttributes(['class' => 'incoming-cancel-modal'])
                    ->modalDescription(fn ($record) => $record->status === IncomingScheduleStatus::PARTIAL
                        ? "一部入荷済み（入荷済数量: {$record->received_quantity}）の入荷予定を取消します。入荷済み数量は維持され、残数量の入荷が取消されます。"
                        : 'この入荷予定を取消しますか？')
                    ->schema([
                        Textarea::make('reason')
                            ->label('取消理由')
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $service = app(OrderCancellationService::class);

                        try {
                            $service->cancelIncomingSchedule(
                                $record,
                                auth()->id(),
                                $data['reason']
                            );

                            $statusLabel = $record->fresh()->status->label();
                            Notification::make()
                                ->title("入荷予定を取消しました（{$statusLabel}）")
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
            ])
            ->toolbarActions([
                static::getExportAction(),
                BulkActionGroup::make([
                    BulkAction::make('bulkUpdateDates')
                        ->label('入荷日・賞味期限を更新')
                        ->icon('heroicon-o-calendar')
                        ->color('info')
                        ->modalHeading('入荷日・賞味期限の一括更新')
                        ->modalDescription('選択した入荷予定の入荷日・賞味期限を更新します（確定は行いません）')
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
                        ->label('選択を入荷確定')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('一括入荷確定')
                        ->modalDescription('選択した入荷予定を全量入荷確定します。')
                        ->schema([
                            DatePicker::make('actual_date')
                                ->label('入荷日')
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
                                ->title("{$result['success']}件を入荷確定しました")
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
                        ->modalDescription('選択した入荷予定を取消します。')
                        ->schema([
                            Textarea::make('reason')
                                ->label('取消理由'),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $service = app(OrderCancellationService::class);
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
                                    $service->cancelIncomingSchedule($record, auth()->id(), $data['reason'] ?? '');
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
