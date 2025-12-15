<?php

namespace App\Filament\Resources\WmsAutoOrderJobControls\Tables;

use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\JobStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsAutoOrderJobControlsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('batch_code')
                    ->label('バッチコード')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('process_name')
                    ->label('プロセス')
                    ->badge()
                    ->color(fn (JobProcessName $state): string => match ($state) {
                        JobProcessName::STOCK_SNAPSHOT => 'gray',
                        JobProcessName::SATELLITE_CALC => 'info',
                        JobProcessName::HUB_CALC => 'warning',
                        JobProcessName::ORDER_EXECUTION => 'success',
                        JobProcessName::ORDER_TRANSMISSION => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (JobStatus $state): string => match ($state) {
                        JobStatus::PENDING => 'gray',
                        JobStatus::RUNNING => 'info',
                        JobStatus::SUCCESS => 'success',
                        JobStatus::FAILED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('processed_records')
                    ->label('処理件数')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('progress_current')
                    ->label('進捗')
                    ->state(fn ($record) => $record->progress_total > 0
                        ? "{$record->progress_current}/{$record->progress_total}"
                        : '-')
                    ->alignEnd(),

                TextColumn::make('started_at')
                    ->label('開始日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('ended_at')
                    ->label('終了日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('error_message')
                    ->label('エラー')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->error_message)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('process_name')
                    ->label('プロセス')
                    ->options(JobProcessName::class),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(JobStatus::class),
            ])
            ->defaultSort('started_at', 'desc');
    }
}
