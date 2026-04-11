<?php

namespace App\Filament\Resources\StatsItemWarehouseSalesSummaries\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Concerns\HasOptimizedFilters;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatsItemWarehouseSalesSummariesTable
{
    use HasExportAction;
    use HasOptimizedFilters;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->sortable('warehouse_id')
                    ->width('80px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->width('120px'),

                TextColumn::make('item.code')
                    ->label('商品CD')
                    ->searchable()
                    ->width('100px'),

                TextColumn::make('item.name')
                    ->label('商品名')
                    ->grow()
                    ->searchable(),

                TextColumn::make('last_3d_qty')
                    ->label('3日実績')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('last_7d_qty')
                    ->label('7日実績')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('last_14d_qty')
                    ->label('14日実績')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('last_30d_qty')
                    ->label('30日実績')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('avg_3d_qty')
                    ->label('3日平均')
                    ->numeric(2)
                    ->sortable()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('avg_7d_qty')
                    ->label('7日平均')
                    ->numeric(2)
                    ->sortable()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('avg_14d_qty')
                    ->label('14日平均')
                    ->numeric(2)
                    ->sortable()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('avg_30d_qty')
                    ->label('30日平均')
                    ->numeric(2)
                    ->sortable()
                    ->alignEnd()
                    ->width('80px'),

                TextColumn::make('last_shipped_at')
                    ->label('最終出荷日')
                    ->date('Y/m/d')
                    ->sortable()
                    ->width('110px'),

                TextColumn::make('calculated_at')
                    ->label('計算日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->width('110px'),
            ])
            ->filters([
                static::warehouseFilter(),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ]);
    }
}
