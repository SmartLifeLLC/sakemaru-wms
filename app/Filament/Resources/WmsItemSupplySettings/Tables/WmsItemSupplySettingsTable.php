<?php

namespace App\Filament\Resources\WmsItemSupplySettings\Tables;

use App\Enums\AutoOrder\SupplyType;
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

class WmsItemSupplySettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('item.item_code')
                    ->label('商品コード')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('item.item_name')
                    ->label('商品名')
                    ->searchable()
                    ->sortable()
                    ->limit(25),

                TextColumn::make('supply_type')
                    ->label('供給タイプ')
                    ->badge()
                    ->formatStateUsing(fn (SupplyType $state) => $state === SupplyType::INTERNAL ? '内部移動' : '外部発注')
                    ->color(fn (SupplyType $state) => $state === SupplyType::INTERNAL ? 'info' : 'success')
                    ->sortable(),

                TextColumn::make('sourceWarehouse.name')
                    ->label('供給元倉庫')
                    ->default('-')
                    ->sortable(),

                TextColumn::make('itemContractor.contractor.name')
                    ->label('発注先')
                    ->default('-')
                    ->sortable(),

                TextColumn::make('lead_time_days')
                    ->label('LT')
                    ->suffix('日')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('itemContractor.safety_stock')
                    ->label('安全在庫')
                    ->numeric()
                    ->default('-')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('daily_consumption_qty')
                    ->label('日販')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('hierarchy_level')
                    ->label('階層')
                    ->sortable()
                    ->alignCenter(),

                IconColumn::make('is_enabled')
                    ->label('有効')
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

                SelectFilter::make('supply_type')
                    ->label('供給タイプ')
                    ->options([
                        'INTERNAL' => '内部移動',
                        'EXTERNAL' => '外部発注',
                    ]),

                TernaryFilter::make('is_enabled')
                    ->label('有効/無効')
                    ->placeholder('すべて')
                    ->trueLabel('有効のみ')
                    ->falseLabel('無効のみ'),
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
            ->defaultSort('warehouse_id', 'asc');
    }
}
