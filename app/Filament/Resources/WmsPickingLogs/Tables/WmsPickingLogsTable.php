<?php

namespace App\Filament\Resources\WmsPickingLogs\Tables;

use App\Models\WmsPicker;
use App\Models\Sakemaru\Warehouse;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DatePicker;

class WmsPickingLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('action_type')
                    ->label('操作種類')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'LOGIN' => 'success',
                        'LOGOUT' => 'gray',
                        'START' => 'info',
                        'PICK' => 'warning',
                        'COMPLETE' => 'success',
                        default => 'gray',
                    })
                    ->sortable()
                    ->searchable(),

                TextColumn::make('picker_name')
                    ->label('ピッカー')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('endpoint')
                    ->label('エンドポイント')
                    ->limit(30)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        return strlen($state) > 30 ? $state : null;
                    })
                    ->searchable(),

                TextColumn::make('pickingTask.warehouse.name')
                    ->label('倉庫')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('pickingTask.deliveryCourse.name')
                    ->label('配送コース')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('item_name')
                    ->label('商品')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('planned_qty')
                    ->label('予定数')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                TextColumn::make('picked_qty')
                    ->label('ピッキング数')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),

                TextColumn::make('shortage_qty')
                    ->label('欠品数')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                TextColumn::make('status_before')
                    ->label('変更前ステータス')
                    ->badge()
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                TextColumn::make('status_after')
                    ->label('変更後ステータス')
                    ->badge()
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                TextColumn::make('stock_qty_before')
                    ->label('在庫数（前）')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                TextColumn::make('stock_qty_after')
                    ->label('在庫数（後）')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                TextColumn::make('ip_address')
                    ->label('IPアドレス')
                    ->searchable()
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                TextColumn::make('device_id')
                    ->label('デバイスID')
                    ->searchable()
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                TextColumn::make('response_status_code')
                    ->label('HTTPステータス')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 400 && $state < 500 => 'warning',
                        $state >= 500 => 'danger',
                        default => 'gray',
                    })
                    ->toggleable()
                    ->toggledHiddenByDefault(),
            ])
            ->filters([
                SelectFilter::make('picker_id')
                    ->label('ピッカー')
                    ->options(
                        WmsPicker::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray()
                    )
                    ->searchable(),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->query(function (Builder $query, array $data): Builder {
                        if (!isset($data['value']) || $data['value'] === null) {
                            return $query;
                        }

                        return $query->whereHas('pickingTask.warehouse', function (Builder $q) use ($data) {
                            $q->where('id', $data['value']);
                        });
                    })
                    ->options(
                        Warehouse::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray()
                    )
                    ->searchable(),

                SelectFilter::make('action_type')
                    ->label('操作種類')
                    ->options([
                        'LOGIN' => 'ログイン',
                        'LOGOUT' => 'ログアウト',
                        'START' => 'タスク開始',
                        'PICK' => 'ピッキング',
                        'COMPLETE' => 'タスク完了',
                    ]),

                Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from')
                            ->label('開始日'),
                        DatePicker::make('created_until')
                            ->label('終了日'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['created_from'] ?? null) {
                            $indicators[] = '開始日: ' . \Carbon\Carbon::parse($data['created_from'])->toFormattedDateString();
                        }

                        if ($data['created_until'] ?? null) {
                            $indicators[] = '終了日: ' . \Carbon\Carbon::parse($data['created_until'])->toFormattedDateString();
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('詳細')
                    ->modalHeading(fn ($record) => "ログ詳細 #{$record->id}")
                    ->modalWidth('7xl')
                    ->modalContent(fn ($record) => view('filament.resources.wms-picking-logs.view-modal', ['record' => $record])),
            ])
            ->toolbarActions([])
            ->bulkActions([]);
    }
}
