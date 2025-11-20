<?php

namespace App\Filament\Resources\WmsShortageAllocations\Tables;

use App\Models\WmsShortageAllocation;
use App\Services\Shortage\ProxyShipmentService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsShortageAllocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('shortage.wave.wave_no')
                    ->label('Wave')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('shortage.warehouse.name')
                    ->label('元倉庫')
                    ->searchable(),

                TextColumn::make('fromWarehouse.name')
                    ->label('移動出荷倉庫')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('shortage.item.name')
                    ->label('商品名')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('assign_qty')
                    ->label('移動出荷数量')
                    ->formatStateUsing(function ($record) {
                        $shortage = $record->shortage;
                        if (!$shortage) {
                            return "{$record->assign_qty}";
                        }
                        return $shortage->formatQuantity($record->assign_qty);
                    })
                    ->sortable(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'gray',
                        'RESERVED' => 'info',
                        'PICKING' => 'warning',
                        'FULFILLED' => 'success',
                        'SHORTAGE' => 'danger',
                        'CANCELLED' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'PENDING' => '指示待ち',
                        'RESERVED' => '引当済み',
                        'PICKING' => 'ピッキング中',
                        'FULFILLED' => '完了',
                        'SHORTAGE' => '代理側欠品',
                        'CANCELLED' => 'キャンセル',
                        default => $state,
                    }),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'PENDING' => '指示待ち',
                        'RESERVED' => '引当済み',
                        'PICKING' => 'ピッキング中',
                        'FULFILLED' => '完了',
                        'SHORTAGE' => '代理側欠品',
                        'CANCELLED' => 'キャンセル',
                    ]),

                SelectFilter::make('shortage_id')
                    ->label('欠品')
                    ->relationship('shortage', 'id')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('from_warehouse_id')
                    ->label('移動出荷倉庫')
                    ->relationship('fromWarehouse', 'name'),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('キャンセル')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WmsShortageAllocation $record): bool =>
                        in_array($record->status, ['PENDING', 'RESERVED'])
                    )
                    ->requiresConfirmation()
                    ->action(function (WmsShortageAllocation $record): void {
                        try {
                            $service = app(ProxyShipmentService::class);
                            $service->cancelProxyShipment($record);

                            Notification::make()
                                ->title('移動出荷をキャンセルしました')
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Bulk actions if needed
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
