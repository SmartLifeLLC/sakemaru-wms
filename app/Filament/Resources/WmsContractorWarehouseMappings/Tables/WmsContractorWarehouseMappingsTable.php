<?php

namespace App\Filament\Resources\WmsContractorWarehouseMappings\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WmsContractorWarehouseMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                TextColumn::make('contractor.code')
                    ->label('発注先コード')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                TextColumn::make('warehouse.name')
                    ->label('対応倉庫')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),

                TextColumn::make('memo')
                    ->label('メモ')
                    ->limit(30)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('登録日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ->defaultSort('contractor_id', 'asc');
    }
}
