<?php

namespace App\Filament\Resources\Sakemaru\Warehouses\Tables;

use App\Enums\PaginationOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarehousesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('client.name')
                    ->label('クライアント')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('倉庫コード')
                    ->numeric()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->label('倉庫名')
                    ->searchable(),
                TextColumn::make('abbreviation')
                    ->label('略称')
                    ->searchable(),
                TextColumn::make('out_of_stock_option')
                    ->label('在庫切れオプション')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'IGNORE_STOCK' => '在庫を無視',
                        'UP_TO_STOCK' => '在庫まで',
                        default => $state,
                    }),
                IconColumn::make('is_active')
                    ->label('有効')
                    ->boolean()
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
