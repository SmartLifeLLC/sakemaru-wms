<?php

namespace App\Filament\Resources\WmsWarehouseCalendars\Tables;

use App\Models\Sakemaru\Warehouse;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class WmsWarehouseCalendarsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['warehouse'])->orderBy('target_date', 'desc'))
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('target_date')
                    ->label('対象日')
                    ->date('Y-m-d (D)')
                    ->sortable(),

                IconColumn::make('is_holiday')
                    ->label('休日')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success'),

                TextColumn::make('holiday_reason')
                    ->label('理由')
                    ->limit(30)
                    ->placeholder('-'),

                IconColumn::make('is_manual_override')
                    ->label('手動')
                    ->boolean()
                    ->trueIcon('heroicon-o-pencil')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning')
                    ->falseColor('gray'),

                TextColumn::make('created_at')
                    ->label('作成日')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(fn () => Warehouse::pluck('name', 'id')->toArray())
                    ->searchable(),

                TernaryFilter::make('is_holiday')
                    ->label('休日のみ')
                    ->placeholder('すべて')
                    ->trueLabel('休日のみ')
                    ->falseLabel('営業日のみ'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                CreateAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
                BulkAction::make('markAsHoliday')
                    ->label('休日に設定')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $records->each(fn ($record) => $record->update(['is_holiday' => true, 'is_manual_override' => true]));
                    }),
                BulkAction::make('markAsBusinessDay')
                    ->label('営業日に設定')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $records->each(fn ($record) => $record->update(['is_holiday' => false, 'is_manual_override' => true]));
                    }),
            ])
            ->defaultSort('target_date', 'desc');
    }
}
