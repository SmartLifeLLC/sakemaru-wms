<?php

namespace App\Filament\Resources\WmsIncomingCompleted\Tables;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\ItemDefaultLocation;
use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\Warehouse;
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
                    ->width('55px'),

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

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->grow(),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->width('110px'),

                TextColumn::make('expected_quantity')
                    ->label('予定数')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('received_quantity')
                    ->label('入荷数')
                    ->numeric()
                    ->alignEnd()
                    ->width('60px'),

                TextColumn::make('shortage_quantity')
                    ->label('欠品数')
                    ->numeric()
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'danger' : null)
                    ->placeholder('0')
                    ->width('60px'),

                TextColumn::make('is_receive_matched')
                    ->label('照合')
                    ->formatStateUsing(fn ($state) => $state ? '済' : '-')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->alignCenter()
                    ->toggleable()
                    ->width('50px'),

                TextColumn::make('expiration_date')
                    ->label('賞味期限')
                    ->date('Y/m/d')
                    ->sortable()
                    ->alignCenter()
                    ->placeholder('-')
                    ->width('85px'),

                TextColumn::make('location.display_name')
                    ->label('ロケ')
                    ->placeholder('-')
                    ->toggleable()
                    ->width('80px'),

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
                    ->width('55px'),

                TextColumn::make('purchase_slip_number')
                    ->label('仕入伝票')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('90px'),

                TextColumn::make('note')
                    ->label('備考')
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('actual_arrival_date')
                    ->label('入荷日')
                    ->form([
                        DatePicker::make('actual_arrival_date')
                            ->label('入荷日')
                            ->default(ClientSetting::systemDateYMD()),
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

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
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
                                    'purchaseUnit' => $details['最小仕入単位'] ?? 1,
                                    'transferIncoming' => $details['移動入庫予定'] ?? 0,
                                    'transferOutgoing' => $details['移動出庫予定'] ?? 0,
                                    'unitAdjustmentNote' => $details['単位調整説明'] ?? '',
                                    'orderQuantity' => $orderCandidate?->order_quantity ?? $record->expected_quantity,
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
