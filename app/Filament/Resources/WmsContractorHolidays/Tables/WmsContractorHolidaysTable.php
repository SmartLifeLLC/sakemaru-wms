<?php

namespace App\Filament\Resources\WmsContractorHolidays\Tables;

use App\Enums\PaginationOptions;
use App\Models\Sakemaru\Contractor;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsContractorHolidaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['contractor'])->orderBy('holiday_date', 'desc'))
            ->columns([
                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('holiday_date')
                    ->label('休業日')
                    ->date('Y-m-d (D)')
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('休業理由')
                    ->limit(50)
                    ->placeholder('-'),

                TextColumn::make('created_at')
                    ->label('作成日')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->options(fn () => Contractor::pluck('name', 'id')->toArray())
                    ->searchable(),
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
            ])
            ->defaultSort('holiday_date', 'desc');
    }
}
