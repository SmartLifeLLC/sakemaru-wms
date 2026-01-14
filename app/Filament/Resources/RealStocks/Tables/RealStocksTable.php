<?php

namespace App\Filament\Resources\RealStocks\Tables;

use App\Enums\PaginationOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RealStocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('activeLots.location.code')
                    ->label('ロケーション')
                    ->listWithLineBreaks()
                    ->limitList(3),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->sortable()
                    ->searchable()
                    ->limit(50),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('lot_no')
                    ->label('ロット番号')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('activeLots.expiration_date')
                    ->label('賞味期限')
                    ->date('Y-m-d')
                    ->listWithLineBreaks()
                    ->limitList(3),

                TextColumn::make('current_quantity')
                    ->label('現在庫数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('reserved_quantity')
                    ->label('引当済')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'warning' : null),

                TextColumn::make('available_quantity')
                    ->label('利用可能数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('created_at')
                    ->label('登録日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('activeLots.price')
                    ->label('単価')
                    ->money('JPY')
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->preload(),

                SelectFilter::make('item_id')
                    ->label('商品')
                    ->relationship('item', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
