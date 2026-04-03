<?php

namespace App\Filament\Resources\WmsIncomingCompleted\Tables;

use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasOptimizedFilters;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\ItemDefaultLocation;
use App\Models\Sakemaru\RealStock;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderIncomingSchedule;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsIncomingCompletedTable
{
    use HasExportAction;
    use HasOptimizedFilters;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'incoming-completed-table sticky-actions'])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter()
                    ->width('50px'),

                TextColumn::make('order_source')
                    ->label('区分')
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
                    ->alignCenter()
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

                TextColumn::make('confirmed_at')
                    ->label('入荷日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->alignCenter()
                    ->width('85px'),

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

                TextColumn::make('capacity_case')
                    ->label('入り数')
                    ->state(fn ($record) => $record->item?->capacity_case)
                    ->numeric()
                    ->placeholder('-')
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('expected_quantity')
                    ->label('ケース')
                    ->state(function ($record) {
                        $qty = $record->expected_quantity ?? 0;
                        $capacity = $record->item?->capacity_case;
                        if (! $capacity || $capacity <= 1) {
                            return 0;
                        }

                        return (int) ($qty / $capacity);
                    })
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state) : '-')
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('loose_quantity')
                    ->label('バラ')
                    ->state(function ($record) {
                        $qty = $record->expected_quantity ?? 0;
                        $capacity = $record->item?->capacity_case;
                        if (! $capacity || $capacity <= 1) {
                            return $qty;
                        }

                        return $qty % $capacity;
                    })
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state) : '-')
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('total_piece_quantity')
                    ->label('総バラ数')
                    ->state(function ($record) {
                        $qty = $record->expected_quantity ?? 0;
                        if ($qty === 0) {
                            return 0;
                        }
                        $quantityType = $record->quantity_type;
                        if ($quantityType === QuantityType::CASE) {
                            $capacity = $record->item?->capacity_case ?? 1;

                            return $qty * $capacity;
                        }
                        if ($quantityType === QuantityType::CARTON) {
                            $capacity = $record->item?->capacity_carton ?? 1;

                            return $qty * $capacity;
                        }

                        return $qty;
                    })
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

                TextColumn::make('computed_default_location')
                    ->label('ロケーション')
                    ->placeholder('-')
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('computed_current_stock')
                    ->label('現在庫')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('computed_available_stock')
                    ->label('有効在庫')
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

                TextColumn::make('confirmedByUser.name')
                    ->label('入荷担当者')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),

                TextColumn::make('confirmedByPicker.name')
                    ->label('入荷ピッカー')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('100px'),
            ])
            ->filters([
                Filter::make('order_date')
                    ->label('発注日')
                    ->form([
                        DatePicker::make('order_date')
                            ->label('発注日'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['order_date'], fn (Builder $q, $date) => $q->where('order_date', $date))
                    )
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['order_date']) {
                            return null;
                        }

                        return '発注日: '.\Carbon\Carbon::parse($data['order_date'])->format('Y年m月d日');
                    }),

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

                Filter::make('actual_arrival_date')
                    ->label('入荷日')
                    ->form([
                        DatePicker::make('actual_arrival_date')
                            ->label('入荷日'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['actual_arrival_date'], fn (Builder $q, $date) => $q->where('actual_arrival_date', $date))
                    )
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['actual_arrival_date']) {
                            return null;
                        }

                        return '入荷日: '.\Carbon\Carbon::parse($data['actual_arrival_date'])->format('Y年m月d日');
                    }),

                SelectFilter::make('order_source')
                    ->label('入荷区分')
                    ->options([
                        'AUTO' => '発注',
                        'MANUAL' => '手動',
                        'TRANSFER' => '移動',
                        'RECEIVED' => '受信',
                    ]),

                static::warehouseFilter(),

                static::contractorFilter(),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('入荷完了詳細')
                    ->modalWidth('6xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
                    ->schema(function (?WmsOrderIncomingSchedule $record): array {
                        if (! $record) {
                            return [];
                        }

                        $orderCandidate = $record->orderCandidate;
                        $transferCandidate = $record->transferCandidate;
                        $candidate = $orderCandidate ?? $transferCandidate;
                        $log = null;
                        $details = [];

                        if ($candidate) {
                            $warehouseId = $orderCandidate
                                ? $orderCandidate->warehouse_id
                                : $transferCandidate->satellite_warehouse_id;
                            $log = WmsOrderCalculationLog::where('batch_code', $candidate->batch_code)
                                ->where('warehouse_id', $warehouseId)
                                ->where('item_id', $candidate->item_id)
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

                        return [
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
                                    'searchCode' => $record->search_code ?? '-',
                                    'itemName' => $item?->name ?? '-',
                                    'packaging' => $item?->packaging ?? '-',
                                    'capacityText' => $capacityText,
                                    'capacityCase' => $item?->capacity_case ?? 0,
                                    'warehouseName' => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-',
                                    'contractorName' => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-',
                                    'orderDate' => $record->order_date?->format('Y/m/d') ?? '-',
                                    'expectedArrivalDate' => $record->expected_arrival_date?->format('Y/m/d') ?? '-',
                                    'actualArrivalDateTime' => $record->confirmed_at?->format('Y/m/d H:i') ?? '-',
                                    'confirmedByName' => $record->confirmedByUser?->name ?? '-',
                                    'confirmedByPickerName' => $record->confirmedByPicker?->name ?? '-',
                                    'locationText' => $locationText,
                                    'expectedQuantity' => $record->expected_quantity ?? 0,
                                    'receivedQuantity' => $record->received_quantity ?? 0,
                                    'remainingQuantity' => $record->remaining_quantity ?? 0,
                                    'status' => $record->status->label(),
                                    'statusColor' => $record->status->color(),
                                    'currentStock' => $currentStock,
                                    'availableStock' => $availableStock,
                                    'hasOrderCandidate' => $candidate !== null,
                                    'orderCandidateId' => $candidate?->id,
                                    'batchCodeFormatted' => $candidate?->batch_code
                                        ? \Carbon\Carbon::createFromFormat('YmdHis', $candidate->batch_code)->format('Y/m/d H:i')
                                        : null,
                                    'hasCalculationLog' => ! empty($details),
                                    'leadTimeDays' => $log?->lead_time_days ?? 0,
                                    'originalArrivalDate' => $candidate?->original_arrival_date
                                        ? \Carbon\Carbon::parse($candidate->original_arrival_date)->format('m/d')
                                        : null,
                                    'shiftedDays' => (int) ($details['到着日調整'] ?? 0),
                                    'shiftReasons' => $details['調整理由'] ?? '',
                                    'formula' => $details['計算式'] ?? '-',
                                    'effectiveStock' => $details['有効在庫'] ?? 0,
                                    'incomingStock' => $details['入庫予定数'] ?? 0,
                                    'safetyStock' => $details['安全在庫'] ?? 0,
                                    'shortageQty' => $details['不足数'] ?? 0,
                                    'purchaseUnit' => $details['最小仕入単位'] ?? 1,
                                    'transferIncoming' => $details['移動入庫予定'] ?? 0,
                                    'transferOutgoing' => $details['移動出庫予定'] ?? 0,
                                    'unitAdjustmentNote' => $details['単位調整説明'] ?? '',
                                    'orderQuantity' => $orderCandidate?->order_quantity ?? $transferCandidate?->transfer_quantity ?? $record->expected_quantity,
                                ]),
                        ];
                    }),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('confirmed_at', 'desc');
    }
}
