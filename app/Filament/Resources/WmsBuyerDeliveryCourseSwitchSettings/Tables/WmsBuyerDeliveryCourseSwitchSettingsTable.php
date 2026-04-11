<?php

namespace App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class WmsBuyerDeliveryCourseSwitchSettingsTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('buyer_name')
                    ->label('得意先')
                    ->getStateUsing(function ($record) {
                        return DB::connection('sakemaru')
                            ->table('buyers as b')
                            ->join('partners as p', 'b.partner_id', '=', 'p.id')
                            ->where('b.id', $record->buyer_id)
                            ->selectRaw("CONCAT('[', p.code, '] ', p.name) as label")
                            ->value('label');
                    })
                    ->searchable(false)
                    ->sortable(false),

                TextColumn::make('switch_time')
                    ->label('切替時刻')
                    ->formatStateUsing(fn ($state) => substr($state, 0, 5))
                    ->sortable(),

                TextColumn::make('to_delivery_course_name')
                    ->label('切替先配送コース')
                    ->getStateUsing(function ($record) {
                        return DB::connection('sakemaru')
                            ->table('delivery_courses')
                            ->where('id', $record->to_delivery_course_id)
                            ->value('name');
                    })
                    ->sortable(false),

                TextColumn::make('last_executed_date')
                    ->label('最終実行日')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('created_at')
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
