<?php

namespace App\Filament\Resources\WmsContractorWarehouseSettings\Tables;

use App\Enums\AutoOrder\ConfirmationLevel;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsContractorWarehouseSettingsTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['contractor', 'warehouse']))
            ->columns([
                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('designated_code')
                    ->label('納入先指定コード')
                    ->placeholder('-')
                    ->searchable(),

                TextColumn::make('confirmation_level')
                    ->label('確定レベル')
                    ->badge()
                    ->formatStateUsing(fn (ConfirmationLevel $state) => $state->label())
                    ->color(fn (ConfirmationLevel $state) => $state->color()),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('contractor_id', 'asc')
            ->filters([
                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->options(fn () => Contractor::pluck('name', 'id')->toArray())
                    ->searchable(),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(fn () => Warehouse::pluck('name', 'id')->toArray())
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                static::getExportAction(),
                CreateAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->paginationPageOptions([10, 25, 50, 'all']);
    }
}
