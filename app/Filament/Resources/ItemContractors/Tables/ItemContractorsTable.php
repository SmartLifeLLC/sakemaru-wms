<?php

namespace App\Filament\Resources\ItemContractors\Tables;

use App\Enums\PaginationOptions;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ItemContractorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('item.code')
                    ->label('商品コード')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supplier.partner.name')
                    ->label('仕入先')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('safety_stock')
                    ->label('安全在庫')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('max_stock')
                    ->label('最大在庫')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),

                IconColumn::make('is_auto_order')
                    ->label('自動発注')
                    ->boolean()
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label('登録日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                    ->searchable(),

                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->options(fn () => Contractor::where('is_active', true)->pluck('name', 'id'))
                    ->searchable(),

                TernaryFilter::make('is_auto_order')
                    ->label('自動発注')
                    ->placeholder('すべて')
                    ->trueLabel('自動発注のみ')
                    ->falseLabel('手動発注のみ'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('item_id', 'asc');
    }
}
