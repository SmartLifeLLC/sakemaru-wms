<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Enums\PaginationOptions;
use App\Models\Sakemaru\Warehouse;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LocationsTable
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
                    ->searchable()
                    ->sortable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('floor.name')
                    ->label('フロア')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('code1')
                    ->label('コード1')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code2')
                    ->label('コード2')
                    ->searchable()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('code3')
                    ->label('コード3')
                    ->searchable()
                    ->sortable()
                    ->default('-'),

                TextColumn::make('joinedLocation')
                    ->label('統合コード')
                    ->badge()
                    ->color('gray')
                    ->searchable(['code1', 'code2', 'code3']),

                TextColumn::make('name')
                    ->label('ロケーション名')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('temperature_type')
                    ->label('温度帯')
                    ->badge()
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '-')
                    ->sortable(),

                TextColumn::make('is_restricted_area')
                    ->label('制限エリア')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'danger' : 'success')
                    ->formatStateUsing(fn (bool $state): string => $state ? '制限' : '通常')
                    ->sortable(),

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
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(function () {
                        return Warehouse::query()
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    }),
            ])
            ->defaultSort('warehouse_id', 'asc')
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
