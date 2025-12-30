<?php

namespace App\Filament\Resources\WmsPickerAttendance\Tables;

use App\Enums\PickerSkillLevel;
use App\Models\WmsPicker;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use App\Enums\PaginationOptions;


class WmsPickerAttendanceTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
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
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 2) . 'x' : '-')
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
            ])
            ->filters([
                TernaryFilter::make('is_available_for_picking')
                    ->label('稼働可否')
                    ->placeholder('すべて')
                    ->trueLabel('稼働可のみ')
                    ->falseLabel('稼働不可のみ'),

                SelectFilter::make('skill_level')
                    ->label('スキルレベル')
                    ->options(PickerSkillLevel::options()),

                SelectFilter::make('current_warehouse_id')
                    ->label('現在倉庫')
                    ->options(function () {
                        return DB::connection('sakemaru')
                            ->table('warehouses')
                            ->where('is_active', true)
                            ->pluck('name', 'id');
                    })
                    ->placeholder('すべて'),
            ])
            ->recordActions([])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assignWarehouse')
                        ->label('出勤倉庫指定')
                        ->icon('heroicon-o-building-office-2')
                        ->form([
                            Select::make('warehouse_id')
                                ->label('出勤倉庫')
                                ->options(function () {
                                    return DB::connection('sakemaru')
                                        ->table('warehouses')
                                        ->where('is_active', true)
                                        ->pluck('name', 'id');
                                })
                                ->required()
                                ->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(function (WmsPicker $picker) use ($data) {
                                $picker->update([
                                    'current_warehouse_id' => $data['warehouse_id'],
                                    'is_available_for_picking' => true,
                                ]);
                            });

                            Notification::make()
                                ->title('出勤倉庫を設定しました')
                                ->body($records->count() . '名のピッカーの出勤倉庫を設定し、稼働可にしました。')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('setAvailable')
                        ->label('稼働ONにする')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('稼働ONにする')
                        ->modalDescription('選択したピッカーを稼働可能状態にします。よろしいですか？')
                        ->action(function (Collection $records): void {
                            $records->each(function (WmsPicker $picker) {
                                $picker->update(['is_available_for_picking' => true]);
                            });

                            Notification::make()
                                ->title('稼働状況を更新しました')
                                ->body($records->count() . '名のピッカーを稼働ONにしました。')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('setUnavailable')
                        ->label('稼働OFFにする')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('稼働OFFにする')
                        ->modalDescription('選択したピッカーを稼働不可状態にします。よろしいですか？')
                        ->action(function (Collection $records): void {
                            $records->each(function (WmsPicker $picker) {
                                $picker->update([
                                    'is_available_for_picking' => false,
                                    'current_warehouse_id' => null,
                                ]);
                            });

                            Notification::make()
                                ->title('稼働状況を更新しました')
                                ->body($records->count() . '名のピッカーを稼働OFFにしました。')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('code', 'asc');
    }
}
