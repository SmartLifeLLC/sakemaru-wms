<?php

namespace App\Filament\Resources\WmsIncomingImportError\Tables;

use App\Enums\PaginationOptions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsIncomingImportErrorsTable
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

                TextColumn::make('receivedFile.filename')
                    ->label('ファイル名')
                    ->searchable()
                    ->width('180px'),

                TextColumn::make('error_type')
                    ->label('種別')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ERROR' => 'danger',
                        'WARNING' => 'warning',
                        default => 'gray',
                    })
                    ->width('80px'),

                TextColumn::make('error_code')
                    ->label('エラーCD')
                    ->badge()
                    ->color('gray')
                    ->width('130px'),

                TextColumn::make('item_code')
                    ->label('商品CD')
                    ->searchable()
                    ->copyable()
                    ->width('130px'),

                TextColumn::make('error_message')
                    ->label('メッセージ')
                    ->wrap()
                    ->grow(),

                TextColumn::make('expected_price')
                    ->label('自社単価')
                    ->money('JPY')
                    ->alignEnd()
                    ->width('90px')
                    ->placeholder('-'),

                TextColumn::make('actual_price')
                    ->label('仕入先単価')
                    ->money('JPY')
                    ->alignEnd()
                    ->width('90px')
                    ->placeholder('-'),

                TextColumn::make('is_resolved')
                    ->label('解決')
                    ->formatStateUsing(fn ($state) => $state ? '解決済み' : '未解決')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'danger')
                    ->alignCenter()
                    ->width('80px'),

                TextColumn::make('created_at')
                    ->label('発生日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->width('100px'),
            ])
            ->filters([
                SelectFilter::make('error_type')
                    ->label('種別')
                    ->options([
                        'ERROR' => 'エラー',
                        'WARNING' => 'ワーニング',
                    ]),

                SelectFilter::make('is_resolved')
                    ->label('解決状態')
                    ->options([
                        '0' => '未解決',
                        '1' => '解決済み',
                    ]),

                SelectFilter::make('error_code')
                    ->label('エラーCD')
                    ->options([
                        'ITEM_NOT_FOUND' => '商品不明',
                        'PRICE_MISMATCH' => '単価不一致',
                        'SLIP_NOT_FOUND' => '伝票不明',
                    ]),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->label('解決済み')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('解決済みにしますか？')
                    ->visible(fn ($record) => ! $record->is_resolved)
                    ->action(function ($record) {
                        $record->update([
                            'is_resolved' => true,
                            'resolved_by' => auth()->id(),
                            'resolved_at' => now(),
                        ]);

                        Notification::make()
                            ->title('解決済みにしました')
                            ->success()
                            ->send();
                    }),
            ], position: RecordActionsPosition::BeforeColumns);
    }
}
