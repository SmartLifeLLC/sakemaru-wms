<?php

namespace App\Filament\Resources\WmsIncomingImportLog\Tables;

use App\Enums\PaginationOptions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsIncomingImportLogsTable
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

                TextColumn::make('file.filename')
                    ->label('ファイル名')
                    ->searchable()
                    ->width('180px'),

                TextColumn::make('slip.slip_number')
                    ->label('伝票番号')
                    ->searchable()
                    ->copyable()
                    ->width('130px'),

                TextColumn::make('d_line_number')
                    ->label('行番号')
                    ->alignCenter()
                    ->width('60px'),

                TextColumn::make('d_item_code')
                    ->label('商品CD')
                    ->searchable()
                    ->copyable()
                    ->width('130px'),

                TextColumn::make('d_product_name')
                    ->label('商品名')
                    ->grow(),

                TextColumn::make('d_jan_code')
                    ->label('JAN')
                    ->searchable()
                    ->copyable()
                    ->width('130px'),

                TextColumn::make('d_case_quantity')
                    ->label('CS数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px')
                    ->placeholder('-'),

                TextColumn::make('d_piece_quantity')
                    ->label('バラ数')
                    ->numeric()
                    ->alignEnd()
                    ->width('70px')
                    ->placeholder('-'),

                TextColumn::make('total_quantity')
                    ->label('出荷総数')
                    ->numeric()
                    ->alignEnd()
                    ->width('80px')
                    ->placeholder('-'),

                TextColumn::make('d_unit_price')
                    ->label('仕入単価')
                    ->money('JPY')
                    ->alignEnd()
                    ->width('90px')
                    ->placeholder('-'),

                TextColumn::make('match_status')
                    ->label('照合ステータス')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'MATCHED' => 'success',
                        'SHORTAGE' => 'danger',
                        'PARTIAL' => 'warning',
                        'NOT_FOUND' => 'danger',
                        'UNMATCHED', 'PENDING' => 'gray',
                        'EXTRA' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'MATCHED' => '照合済み',
                        'SHORTAGE' => '欠品',
                        'PARTIAL' => '数量差異',
                        'NOT_FOUND' => '商品不明',
                        'UNMATCHED' => '未照合',
                        'PENDING' => '処理待ち',
                        'EXTRA' => '余剰',
                        default => $state,
                    })
                    ->alignCenter()
                    ->width('100px'),

                TextColumn::make('created_at')
                    ->label('取込日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->width('100px'),
            ])
            ->filters([
                SelectFilter::make('match_status')
                    ->label('照合ステータス')
                    ->multiple()
                    ->options([
                        'MATCHED' => '照合済み',
                        'SHORTAGE' => '欠品',
                        'PARTIAL' => '数量差異',
                        'NOT_FOUND' => '商品不明',
                        'UNMATCHED' => '未照合',
                        'PENDING' => '処理待ち',
                        'EXTRA' => '余剰',
                    ]),
            ]);
    }
}
