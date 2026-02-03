<?php

namespace App\Filament\Resources\WmsRouteCalculationLogs\Tables;

use App\Enums\PaginationOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WmsRouteCalculationLogsTable
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
                    ->sortable(),
                TextColumn::make('picking_task_id')
                    ->label('タスクID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('warehouse_id')
                    ->label('倉庫ID')
                    ->sortable(),
                TextColumn::make('floor_id')
                    ->label('フロアID')
                    ->sortable(),
                TextColumn::make('algorithm')
                    ->label('アルゴリズム')
                    ->badge()
                    ->color('info'),
                TextColumn::make('cell_size')
                    ->label('セルサイズ')
                    ->suffix('px'),
                TextColumn::make('front_point_delta')
                    ->label('Delta')
                    ->suffix('px'),
                TextColumn::make('location_count')
                    ->label('ロケーション数')
                    ->sortable(),
                TextColumn::make('total_distance')
                    ->label('総距離')
                    ->suffix('px')
                    ->sortable(),
                TextColumn::make('calculation_time_ms')
                    ->label('計算時間')
                    ->suffix('ms')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('計算日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
