<?php

namespace App\Filament\Resources\WmsShortagesApproved\Tables;

use App\Enums\PaginationOptions;
use App\Enums\QuantityType;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortage;
use Filament\Actions\Action;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
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
            ->paginationPageOptions([100, 500])
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

                TextColumn::make('shipment_type_label')
                    ->label('出荷区分')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        '営業出荷' => 'success',
                        '店間移動' => 'info',
                        default => 'gray',
                    })
                    ->alignment('center'),

                TextColumn::make('status_label')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        '未対応' => 'danger',
                        '横持ち出荷' => 'warning',
                        '欠品確定' => 'danger',
                        '部分欠品' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('status', $direction))
                    ->alignment('center'),

                TextColumn::make('wave_id')
                    ->label('ウェーブID')
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('shipment_date')
                    ->label('納品日')
                    ->date('Y-m-d')
                    ->sortable()
                    ->alignment('center'),

                TextColumn::make('request_source_code')
                    ->label('依頼元CD')
                    ->searchable(query: fn ($query, $search) => $query->where(function ($q) use ($search) {
                        $q->whereHas('trade.partner', fn ($q2) => $q2->where('code', 'like', "%{$search}%"))
                            ->orWhereHas('warehouse', fn ($q2) => $q2->where('code', 'like', "%{$search}%"));
                    }))
                    ->alignment('center'),

                TextColumn::make('request_source_name')
                    ->label('依頼元名')
                    ->searchable(query: fn ($query, $search) => $query->where(function ($q) use ($search) {
                        $q->whereHas('trade.partner', fn ($q2) => $q2->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('warehouse', fn ($q2) => $q2->where('name', 'like', "%{$search}%"));
                    }))
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
                    ->grow()
                    ->alignment('center'),

                TextColumn::make('warehouse_name_display')
                    ->label('出庫倉庫')
                    ->state(fn (WmsShortage $record): string => $record->warehouse->name ?? '-')
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('warehouse_id', $direction))
                    ->searchable(query: fn ($query, $search) => $query->whereHas('warehouse', fn ($q) => $q->where('name', 'like', "%{$search}%")))
                    ->alignment('center'),

                TextColumn::make('location_code')
                    ->label('棚番')
                    ->state(fn (WmsShortage $record): string => $record->location
                        ? \App\Models\Sakemaru\Location::formatCode($record->location->code1, $record->location->code2, $record->location->code3, '-')
                        : '-')
                    ->alignment('center'),

                TextColumn::make('order_case_qty')
                    ->label('受注ケース')
                    ->numeric()
                    ->placeholder('-')
                    ->alignment('center'),

                TextColumn::make('order_piece_qty')
                    ->label('受注バラ数')
                    ->numeric()
                    ->placeholder('-')
                    ->alignment('center'),

                TextColumn::make('total_order_pieces')
                    ->label('総バラ数')
                    ->numeric()
                    ->alignment('center')
                    ->weight('bold')
                    ->size(\Filament\Support\Enums\TextSize::Large),

                TextColumn::make('picked_qty')
                    ->label('引当数')
                    ->numeric()
                    ->alignment('center'),

                TextColumn::make('shortage_qty')
                    ->label('欠品数')
                    ->numeric()
                    ->alignment('center')
                    ->color('danger'),

                TextColumn::make('total_shortage_pieces')
                    ->label('欠品総バラ数')
                    ->numeric()
                    ->alignment('center')
                    ->color('danger')
                    ->weight('bold')
                    ->size(\Filament\Support\Enums\TextSize::Large),

                TextColumn::make('allocations_total_qty')
                    ->label('横持ち出荷数')
                    ->numeric()
                    ->alignment('center')
                    ->color('warning')
                    ->weight('bold'),

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

                SelectFilter::make('shipment_type')
                    ->label('出荷区分')
                    ->options([
                        'EARNING' => '営業出荷',
                        'STOCK_TRANSFER' => '店間移動',
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['value'])) {
                            $query->whereHas('trade', fn ($q) => $q->where('trade_category', $data['value']));
                        }
                    }),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'PARTIAL_SHORTAGE' => '部分欠品',
                        'SHORTAGE' => '欠品確定',
                        'REALLOCATING' => '横持ち出荷',
                    ])
                    ->multiple(),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return Warehouse::query()
                            ->where('is_active', true)
                            ->where(fn ($q) => $q
                                ->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%"))
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
                            ->toArray();
                    }),

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

                                        $allocations = $record->allocations()
                                            ->with('targetWarehouse:id,name')
                                            ->get()
                                            ->map(function ($allocation) use ($statusLabels) {
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
