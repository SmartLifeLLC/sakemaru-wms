<?php

namespace App\Filament\Resources\WaveSettings\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class WaveSettingsTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('delivery_course_id')
                    ->label('配送コース')
                    ->formatStateUsing(fn ($state) => DB::connection('sakemaru')
                        ->table('delivery_courses')
                        ->where('id', $state)
                        ->value('name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('warehouse_name')
                    ->label('倉庫')
                    ->getStateUsing(function ($record) {
                        return DB::connection('sakemaru')
                            ->table('delivery_courses as dc')
                            ->join('warehouses as w', 'dc.warehouse_id', '=', 'w.id')
                            ->where('dc.id', $record->delivery_course_id)
                            ->value('w.name');
                    })
                    ->sortable(false),

                TextColumn::make('picking_start_time')
                    ->label('Start Time')
                    ->time('H:i')
                    ->sortable(),

                TextColumn::make('picking_deadline_time')
                    ->label('Deadline Time')
                    ->time('H:i')
                    ->sortable(),

                IconColumn::make('is_wave_generation_enabled')
                    ->label('波動生成')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
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
                static::getExportAction(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
