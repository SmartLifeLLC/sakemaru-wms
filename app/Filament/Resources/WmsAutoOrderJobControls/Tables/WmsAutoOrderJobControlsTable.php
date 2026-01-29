<?php

namespace App\Filament\Resources\WmsAutoOrderJobControls\Tables;

use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\JobStatus;
use App\Enums\PaginationOptions;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class WmsAutoOrderJobControlsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
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

                TextColumn::make('started_at')
                    ->label('開始日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('finished_at')
                    ->label('終了日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

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
            ->recordActions([
                Action::make('viewResult')
                    ->label('結果')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('info')
                    ->visible(fn ($record) => ! empty($record->result_data))
                    ->modalHeading(fn ($record) => "発注候補生成結果 - {$record->batch_code}")
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalContent(fn ($record): View => view(
                        'filament.resources.wms-auto-order-job-controls.result-modal',
                        ['result' => $record->result_data ?? []]
                    )),
            ])
            ->defaultSort('started_at', 'desc');
    }
}
