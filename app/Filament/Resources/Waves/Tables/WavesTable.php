<?php

namespace App\Filament\Resources\Waves\Tables;

use App\Enums\PaginationOptions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WavesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('wave_no')
                    ->label('波動番号')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('waveSetting.warehouse.code')
                    ->label('倉庫コード')
                    ->sortable(),

                TextColumn::make('waveSetting.warehouse.name')
                    ->label('倉庫名')
                    ->sortable(),

                TextColumn::make('waveSetting.deliveryCourse.code')
                    ->label('配送コースコード')
                    ->sortable(),

                TextColumn::make('waveSetting.deliveryCourse.name')
                    ->label('配送コース名')
                    ->sortable(),

                TextColumn::make('shipping_date')
                    ->label('出荷日')
                    ->date('Y年m月d日')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('出荷状況')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'PENDING' => '未出荷',
                        'PICKING' => 'ピッキング中',
                        'SHORTAGE' => '欠品あり',
                        'COMPLETED' => '出荷完了',
                        'CLOSED' => 'クローズ',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'gray',
                        'PICKING' => 'info',
                        'SHORTAGE' => 'warning',
                        'COMPLETED' => 'success',
                        'CLOSED' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('波動生成時刻')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('出荷状況')
                    ->options([
                        'PENDING' => '未出荷',
                        'PICKING' => 'ピッキング中',
                        'SHORTAGE' => '欠品あり',
                        'COMPLETED' => '出荷完了',
                        'CLOSED' => 'クローズ',
                    ]),
            ])
            ->recordActions([
                // 編集・削除は不可
            ])
            ->defaultSort('created_at', 'desc');
    }
}
