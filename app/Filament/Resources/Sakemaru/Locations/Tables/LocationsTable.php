<?php

namespace App\Filament\Resources\Sakemaru\Locations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use App\Enums\PaginationOptions;

class LocationsTable
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
                    ->searchable()
                    ->sortable(),
                TextColumn::make('floor.name')
                    ->label('フロア')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code1')
                    ->label('コード1')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code2')
                    ->label('コード2')
                    ->searchable(),
                TextColumn::make('code3')
                    ->label('コード3')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('ロケーション名')
                    ->searchable(),
                TextColumn::make('available_quantity_flags')
                    ->label('数量タイプ')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        1 => 'ケース',
                        2 => 'バラ',
                        3 => 'ケース+バラ',
                        4 => 'ボール',
                        8 => '無し',
                        default => (string) $state,
                    })
                    ->color(fn (int $state): string => match ($state) {
                        1 => 'info',
                        2 => 'success',
                        3 => 'warning',
                        4 => 'primary',
                        8 => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
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
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
