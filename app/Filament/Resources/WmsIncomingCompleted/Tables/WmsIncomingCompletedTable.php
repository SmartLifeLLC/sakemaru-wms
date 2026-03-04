<?php

namespace App\Filament\Resources\WmsIncomingCompleted\Tables;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (IncomingScheduleStatus $state): string => $state->label())
                    ->color(fn (IncomingScheduleStatus $state): string => $state->color())
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('slip_number')
                    ->label('伝票番号')
                    ->searchable()
                    ->copyable()
                    ->placeholder('-')
                    ->width('130px'),

                TextColumn::make('order_source')
                    ->label('区分')
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
                    ->width('55px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->state(fn ($record) => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-')
                    ->searchable()
                    ->sortable()
                    ->width('140px'),

                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->searchable()
                    ->sortable()
                    ->alignCenter()
                    ->width('90px'),

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
                    ->label('入庫数')
                    ->numeric()
                    ->alignEnd()
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
                    ->width('55px'),

                TextColumn::make('expiration_date')
                    ->label('賞味期限')
                    ->date('Y/m/d')
                    ->sortable()
                    ->alignCenter()
                    ->placeholder('-')
                    ->width('85px'),

                TextColumn::make('actual_arrival_date')
                    ->label('入庫日')
                    ->date('m/d')
                    ->sortable()
                    ->alignCenter()
                    ->width('60px'),

                TextColumn::make('confirmed_at')
                    ->label('確定日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->alignCenter()
                    ->width('85px'),

                TextColumn::make('location.display_name')
                    ->label('ロケ')
                    ->placeholder('-')
                    ->toggleable()
                    ->width('80px'),

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
                SelectFilter::make('order_source')
                    ->label('入庫区分')
                    ->options([
                        'AUTO' => '発注',
                        'MANUAL' => '手動',
                        'TRANSFER' => '移動',
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
                    ->infolist(function ($record): array {
                        return [
                            \Filament\Schemas\Components\Section::make('基本情報')
                                ->schema([
                                    \Filament\Schemas\Components\Grid::make(2)
                                        ->schema([
                                            \Filament\Infolists\Components\TextEntry::make('warehouse')
                                                ->label('倉庫')
                                                ->state(fn () => $record->warehouse ? "[{$record->warehouse->code}]{$record->warehouse->name}" : '-'),
                                            \Filament\Infolists\Components\TextEntry::make('order_source')
                                                ->label('入庫区分')
                                                ->state(fn () => match ($record->order_source) {
                                                    OrderSource::AUTO => '発注',
                                                    OrderSource::MANUAL => '手動',
                                                    OrderSource::TRANSFER => '移動',
                                                    default => '-',
                                                })
                                                ->badge()
                                                ->color(fn () => match ($record->order_source) {
                                                    OrderSource::AUTO => 'info',
                                                    OrderSource::MANUAL => 'gray',
                                                    OrderSource::TRANSFER => 'warning',
                                                    default => 'gray',
                                                }),
                                        ]),
                                    \Filament\Infolists\Components\TextEntry::make('item')
                                        ->label('商品')
                                        ->state(fn () => $record->item ? "[{$record->item->code}]{$record->item->name}" : '-'),
                                    \Filament\Infolists\Components\TextEntry::make('contractor')
                                        ->label('発注先')
                                        ->state(fn () => $record->contractor ? "[{$record->contractor->code}]{$record->contractor->name}" : '-'),
                                ]),
                            \Filament\Schemas\Components\Section::make('入庫情報')
                                ->schema([
                                    \Filament\Schemas\Components\Grid::make(3)
                                        ->schema([
                                            \Filament\Infolists\Components\TextEntry::make('received_quantity')
                                                ->label('入庫数')
                                                ->state(fn () => number_format($record->received_quantity).' '.match ($record->quantity_type?->value ?? $record->quantity_type) {
                                                    'PIECE' => 'バラ',
                                                    'CASE' => 'ケース',
                                                    'CARTON' => 'ボール',
                                                    default => '',
                                                })
                                                ->weight('bold'),
                                            \Filament\Infolists\Components\TextEntry::make('expected_quantity')
                                                ->label('予定数')
                                                ->state(fn () => number_format($record->expected_quantity)),
                                            \Filament\Infolists\Components\TextEntry::make('expiration_date')
                                                ->label('賞味期限')
                                                ->state(fn () => $record->expiration_date?->format('Y/m/d') ?? '-'),
                                        ]),
                                    \Filament\Schemas\Components\Grid::make(3)
                                        ->schema([
                                            \Filament\Infolists\Components\TextEntry::make('expected_arrival_date')
                                                ->label('入庫予定日')
                                                ->state(fn () => $record->expected_arrival_date?->format('Y/m/d') ?? '-'),
                                            \Filament\Infolists\Components\TextEntry::make('actual_arrival_date')
                                                ->label('入庫日')
                                                ->state(fn () => $record->actual_arrival_date?->format('Y/m/d') ?? '-'),
                                            \Filament\Infolists\Components\TextEntry::make('confirmed_at')
                                                ->label('確定日時')
                                                ->state(fn () => $record->confirmed_at?->format('Y/m/d H:i') ?? '-'),
                                        ]),
                                    \Filament\Schemas\Components\Grid::make(2)
                                        ->schema([
                                            \Filament\Infolists\Components\TextEntry::make('location')
                                                ->label('入庫ロケーション')
                                                ->state(fn () => $record->location?->display_name ?? '-'),
                                            \Filament\Infolists\Components\TextEntry::make('purchase_slip_number')
                                                ->label('仕入伝票番号')
                                                ->state(fn () => $record->purchase_slip_number ?? '-'),
                                        ]),
                                ]),
                            \Filament\Schemas\Components\Section::make('備考')
                                ->schema([
                                    \Filament\Infolists\Components\TextEntry::make('note')
                                        ->label('')
                                        ->state(fn () => $record->note ?? '-')
                                        ->columnSpanFull(),
                                ])
                                ->collapsed()
                                ->collapsible(),
                        ];
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('confirmed_at', 'desc');
    }
}
