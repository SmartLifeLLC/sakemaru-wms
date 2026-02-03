<?php

namespace App\Filament\Resources\WmsPickers\Tables;

use App\Enums\PaginationOptions;
use App\Enums\PickerSkillLevel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WmsPickersTable
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
                    ->label('名前')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('defaultWarehouse.name')
                    ->label('デフォルト倉庫')
                    ->sortable()
                    ->default('-'),

                TextColumn::make('skill_level')
                    ->label('スキル')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray')
                    ->sortable(),

                TextColumn::make('picking_speed_rate')
                    ->label('作業速度')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 2).'x' : '-')
                    ->sortable()
                    ->alignCenter(),

                IconColumn::make('is_available_for_picking')
                    ->label('稼働可')
                    ->boolean()
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('currentWarehouse.name')
                    ->label('現在倉庫')
                    ->sortable()
                    ->default('-'),

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

                TernaryFilter::make('is_available_for_picking')
                    ->label('稼働可否')
                    ->placeholder('すべて')
                    ->trueLabel('稼働可のみ')
                    ->falseLabel('稼働不可のみ'),

                SelectFilter::make('skill_level')
                    ->label('スキルレベル')
                    ->options(PickerSkillLevel::options()),
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
