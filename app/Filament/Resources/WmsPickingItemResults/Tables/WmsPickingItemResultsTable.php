<?php

namespace App\Filament\Resources\WmsPickingItemResults\Tables;

use App\Filament\Support\Tables\Columns\QuantityTypeColumn;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsPickingItemResultsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(20)
            ->paginationPageOptions([20, 50, 100, 200])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('pickingTask.id')
                    ->label('タスクID')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'PENDING' => 'warning',
                        'PICKING' => 'info',
                        'COMPLETED' => 'success',
                        'CANCELLED' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'PENDING' => '未着手',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        'CANCELLED' => 'キャンセル',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('trade.serial_id')
                    ->label('伝票番号')
                    ->searchable()
                    ->sortable()
                    ->default('-')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('earning.buyer.partner.code')
                    ->label('得意先コード')
                    ->searchable()
                    ->default('-')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('earning.buyer.partner.name')
                    ->label('得意先名')
                    ->searchable()
                    ->default('-')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('delivery_course')
                    ->label('配送コース')
                    ->default('-')
                    ->state(function ($record) {
                        if (!$record->earning) {
                            return '-';
                        }

                        $deliveryCourse = $record->earning->delivery_course;
                        if (!$deliveryCourse) {
                            return '-';
                        }

                        return "{$deliveryCourse->code} - {$deliveryCourse->name}";
                    }),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('item.capacity_case')
                    ->label('入り数')
                    ->formatStateUsing(fn($state) => $state ? (string)$state : '-')
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: false),

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
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('location_display')
                    ->label('ロケーション')
                    ->default('-')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('walking_order')
                    ->label('歩行順序')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                QuantityTypeColumn::make('ordered_qty_type'),

                TextColumn::make('ordered_qty')
                    ->label('受注')
                    ->numeric()
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('planned_qty')
                    ->label('引当')
                    ->numeric()
                    ->sortable()
                    ->alignment('center')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('picked_qty')
                    ->label('出荷')
                    ->numeric()
                    ->sortable()
                    ->alignment('center')
                    ->formatStateUsing(fn ($record) => $record->picked_qty ?? '-')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('shortage_qty')
                    ->label('欠品')
                    ->numeric()
                    ->sortable()
                    ->alignment('center')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->weight(fn ($state) => $state > 0 ? 'bold' : 'normal')
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('picked_at')
                    ->label('ピッキング日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('picking_task_id')
                    ->label('ピッキングタスク')
                    ->relationship('pickingTask', 'id')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('earning_id')
                    ->label('伝票ID')
                    ->relationship('earning', 'id')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'PENDING' => '未着手',
                        'PICKING' => 'ピッキング中',
                        'COMPLETED' => '完了',
                        'CANCELLED' => 'キャンセル',
                    ]),

                SelectFilter::make('has_shortage')
                    ->label('欠品状況')
                    ->options([
                        '1' => '欠品あり',
                        '0' => '欠品なし',
                    ])
                    ->query(function ($query, $state) {
                        if ($state['value'] === '1') {
                            return $query->where('shortage_qty', '>', 0);
                        } elseif ($state['value'] === '0') {
                            return $query->where(function ($q) {
                                $q->where('shortage_qty', 0)
                                    ->orWhereNull('shortage_qty');
                            });
                        }
                        return $query;
                    }),
            ])
            ->defaultSort('walking_order', 'asc')
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ]);
    }
}
