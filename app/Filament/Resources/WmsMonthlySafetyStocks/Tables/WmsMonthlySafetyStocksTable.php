<?php

namespace App\Filament\Resources\WmsMonthlySafetyStocks\Tables;

use App\Enums\PaginationOptions;
use App\Models\WmsMonthlySafetyStock;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsMonthlySafetyStocksTable
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
                    ->state(fn ($record) => $record->warehouse
                        ? "[{$record->warehouse->code}] {$record->warehouse->name}"
                        : '-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor
                        ? "[{$record->contractor->code}] {$record->contractor->name}"
                        : '-')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('month')
                    ->label('月')
                    ->state(fn ($record) => $record->month . '月')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('safety_stock')
                    ->label('発注点')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

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
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('month')
                    ->label('月')
                    ->options(WmsMonthlySafetyStock::getMonthOptions()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('warehouse_id', 'asc');
    }
}
