<?php

namespace App\Filament\Resources\Contractors\Tables;

use App\Enums\PaginationOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ContractorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('code')
                    ->label('コード')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('発注先名')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nickname')
                    ->label('略称')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('supplier.name')
                    ->label('仕入先')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('tel')
                    ->label('電話番号')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('fax')
                    ->label('FAX')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_auto_change_order')
                    ->label('自動発注')
                    ->boolean()
                    ->sortable()
                    ->alignCenter(),

                IconColumn::make('is_active')
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
                TernaryFilter::make('is_active')
                    ->label('有効/無効')
                    ->placeholder('すべて')
                    ->trueLabel('有効のみ')
                    ->falseLabel('無効のみ'),

                TernaryFilter::make('is_auto_change_order')
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
            ->defaultSort('code', 'asc');
    }
}
