<?php

namespace App\Filament\Resources\WmsIncomingCompleted\Tables;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsIncomingCompletedTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (IncomingScheduleStatus $state): string => $state->label())
                    ->color(fn (IncomingScheduleStatus $state): string => $state->color())
                    ->sortable()
                    ->width('90px'),

                TextColumn::make('order_source')
                    ->label('発注元')
                    ->badge()
                    ->formatStateUsing(fn (OrderSource $state): string => match ($state) {
                        OrderSource::AUTO => '自動',
                        OrderSource::MANUAL => '手動',
                    })
                    ->color(fn (OrderSource $state): string => match ($state) {
                        OrderSource::AUTO => 'info',
                        OrderSource::MANUAL => 'gray',
                    })
                    ->width('60px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->state(fn ($record) => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->width('150px'),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->grow(),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->width('120px'),

                TextColumn::make('expected_quantity')
                    ->label('予定数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

                TextColumn::make('received_quantity')
                    ->label('入庫数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px'),

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

                TextColumn::make('actual_arrival_date')
                    ->label('入庫日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('70px'),

                TextColumn::make('confirmed_at')
                    ->label('確定日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->alignCenter()
                    ->width('90px'),

                TextColumn::make('purchase_slip_number')
                    ->label('仕入伝票')
                    ->placeholder('-')
                    ->toggleable()
                    ->width('100px'),

                TextColumn::make('note')
                    ->label('備考')
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('order_source')
                    ->label('発注元')
                    ->options([
                        'AUTO' => '自動発注',
                        'MANUAL' => '手動発注',
                    ]),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->relationship('contractor', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('入庫完了詳細')
                    ->modalWidth('lg')
                    ->schema(function ($record) {
                        return [
                            \Filament\Schemas\Components\Section::make('基本情報')
                                ->schema([
                                    \Filament\Infolists\Components\TextEntry::make('warehouse')
                                        ->label('倉庫')
                                        ->state(fn () => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-'),
                                    \Filament\Infolists\Components\TextEntry::make('item')
                                        ->label('商品')
                                        ->state(fn () => $record->item ? "[{$record->item->code}]{$record->item->name}" : '-'),
                                    \Filament\Infolists\Components\TextEntry::make('contractor')
                                        ->label('発注先')
                                        ->state(fn () => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-'),
                                ]),
                            \Filament\Schemas\Components\Section::make('入庫情報')
                                ->schema([
                                    \Filament\Infolists\Components\TextEntry::make('received_quantity')
                                        ->label('入庫数量')
                                        ->state(fn () => $record->received_quantity),
                                    \Filament\Infolists\Components\TextEntry::make('actual_arrival_date')
                                        ->label('入庫日')
                                        ->state(fn () => $record->actual_arrival_date?->format('Y/m/d') ?? '-'),
                                    \Filament\Infolists\Components\TextEntry::make('confirmed_at')
                                        ->label('確定日時')
                                        ->state(fn () => $record->confirmed_at?->format('Y/m/d H:i') ?? '-'),
                                ]),
                        ];
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),
            ])
            ->defaultSort('confirmed_at', 'desc');
    }
}
