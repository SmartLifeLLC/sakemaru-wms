<?php

namespace App\Filament\Resources\WmsImportLogs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsImportLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('種類')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'monthly_safety_stocks' => '月別発注点',
                        default => $state,
                    })
                    ->badge(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending' => '待機中',
                        'processing' => '処理中',
                        'completed' => '完了',
                        'failed' => '失敗',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->badge(),

                TextColumn::make('file_name')
                    ->label('ファイル名')
                    ->limit(30),

                TextColumn::make('success_count')
                    ->label('成功')
                    ->numeric()
                    ->color('success'),

                TextColumn::make('error_count')
                    ->label('エラー')
                    ->numeric()
                    ->color('danger'),

                TextColumn::make('user.name')
                    ->label('実行者'),

                TextColumn::make('started_at')
                    ->label('開始')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('completed_at')
                    ->label('完了')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('種類')
                    ->options([
                        'monthly_safety_stocks' => '月別発注点',
                    ]),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'pending' => '待機中',
                        'processing' => '処理中',
                        'completed' => '完了',
                        'failed' => '失敗',
                    ]),
            ])
            ->recordActions([])
            ->bulkActions([]);
    }
}
