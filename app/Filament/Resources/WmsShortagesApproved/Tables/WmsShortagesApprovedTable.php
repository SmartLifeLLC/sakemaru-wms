<?php

namespace App\Filament\Resources\WmsShortagesApproved\Tables;

use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Models\WmsShortage;
use Filament\Actions\Action;
use Filament\Support\Enums\Alignment;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsShortagesApprovedTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->extraAttributes(['class' => 'sticky-actions-left'])
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('confirmedBy.name')
                    ->label('承認者')
                    ->searchable()
                    ->alignment('center'),

                TextColumn::make('confirmed_at')
                    ->label('承認日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
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
                \Filament\Tables\Filters\Filter::make('shipment_date')
                    ->label('出荷日')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('shipment_date')
                            ->label('出荷日'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['shipment_date'],
                            fn (Builder $query, $date) => $query->where('shipment_date', $date),
                        );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['shipment_date']) {
                            return null;
                        }

                        return '出荷日: '.$data['shipment_date'];
                    }),

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
                        if (! empty($data['value'])) {
                            $query->whereHas('trade', function ($q) use ($data) {
                                $q->where('partner_id', $data['value']);
                            });
                        }
                    }),
            ])
            ->recordAction('viewProxyShipment')
            ->recordActions([
                Action::make('viewProxyShipment')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalWidth('7xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
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
                                            $volumeValue = $record->item->volume.($unit ? $unit->name() : '');
                                        }

                                        $orderQtyValue = (string) $record->order_qty;
                                        $plannedQtyValue = (string) $record->picked_qty;

                                        $shortageDetailsParts = [];
                                        if ($record->allocation_shortage_qty > 0) {
                                            $shortageDetailsParts[] = "引当時: {$record->allocation_shortage_qty}";
                                        }
                                        if ($record->picking_shortage_qty > 0) {
                                            $shortageDetailsParts[] = "ピッキング時: {$record->picking_shortage_qty}";
                                        }
                                        $shortageDetailsValue = implode(' / ', $shortageDetailsParts);

                                        $shortageQtyValue = $record->shortage_qty > 0
                                            ? (string) $record->shortage_qty
                                            : '-';

                                        $allocatedQtyValue = ($record->allocations_total_qty ?? 0) > 0
                                            ? (string) ($record->allocations_total_qty ?? 0)
                                            : '-';

                                        $remainingQty = $record->remaining_qty;
                                        $remainingValue = $remainingQty > 0
                                            ? (string) $remainingQty
                                            : '-';

                                        return [
                                            'data' => [
                                                ['label' => '商品コード', 'value' => $record->item->code ?? '-'],
                                                ['label' => '商品名', 'value' => $record->item->name ?? '-'],
                                                ['label' => '入り数', 'value' => $record->item->capacity_case ? (string) $record->item->capacity_case : '-'],
                                                ['label' => '容量', 'value' => $volumeValue],
                                                ['label' => '得意先コード', 'value' => $record->trade->partner->code ?? '-'],
                                                ['label' => '得意先名', 'value' => $record->trade->partner->name ?? '-'],
                                                ['label' => '元倉庫', 'value' => $record->warehouse->name ?? '-'],
                                                ['label' => '受注単位', 'value' => QuantityType::tryFrom($record->qty_type_at_order)?->name() ?? $record->qty_type_at_order],
                                                ['label' => '受注数', 'value' => $orderQtyValue],
                                                ['label' => '引当数', 'value' => $plannedQtyValue],
                                                ['label' => '欠品数', 'value' => $shortageQtyValue],
                                                ['label' => '欠品詳細', 'value' => $shortageDetailsValue ?: '-'],
                                                ['label' => '横持ち出荷数', 'value' => $allocatedQtyValue],
                                                ['label' => '残欠品数', 'value' => $remainingValue],
                                            ],
                                        ];
                                    }),
                            ]),

                        Section::make('横持ち出荷指示')
                            ->schema([
                                View::make('filament.components.shortage-allocations-table')
                                    ->viewData(function (WmsShortage $record): array {
                                        $statusLabels = [
                                            'PENDING' => '未処理',
                                            'RESERVED' => '引当済',
                                            'PICKING' => 'ピッキング中',
                                            'FULFILLED' => '完了',
                                            'SHORTAGE' => '欠品',
                                            'CANCELLED' => 'キャンセル',
                                        ];

                                        $allocations = $record->allocations->map(function ($allocation) use ($statusLabels) {
                                            return [
                                                'warehouse_name' => $allocation->targetWarehouse->name ?? '-',
                                                'assign_qty' => $allocation->assign_qty,
                                                'qty_type_label' => QuantityType::tryFrom($allocation->assign_qty_type)?->name() ?? $allocation->assign_qty_type ?? '-',
                                                'status_label' => $statusLabels[$allocation->status] ?? $allocation->status ?? '-',
                                            ];
                                        })->toArray();

                                        return ['allocations' => $allocations];
                                    }),
                            ]),
                    ]),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('confirmed_at', 'desc')
            ->modifyQueryUsing(function (Builder $query) {
                // 承認済みのレコードのみ表示
                $query->where('is_confirmed', true);
            });
    }
}
