<?php

namespace App\Filament\Resources\WmsAutoOrderJobControls\Tables;

use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\JobStatus;
use App\Enums\AutoOrder\SettlementStatus;
use App\Enums\PaginationOptions;
use App\Enums\QueueProgressStatus;
use App\Filament\Concerns\HasExportAction;
use App\Models\WmsQueueProgress;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

class WmsAutoOrderJobControlsTable
{
    use HasExportAction;

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
                    ->label('実行CD')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('process_name')
                    ->label('実行タイプ')
                    ->formatStateUsing(fn (JobProcessName $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (JobStatus $state): string => $state->label())
                    ->color(fn (JobStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('settlement_status')
                    ->label('確定状態')
                    ->badge()
                    ->formatStateUsing(fn (SettlementStatus $state): string => $state->label())
                    ->color(fn (SettlementStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('snapshot_job_id')
                    ->label('参照SS')
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->label('実行タイプ')
                    ->options(fn () => collect(JobProcessName::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(fn () => collect(JobStatus::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])),

                SelectFilter::make('settlement_status')
                    ->label('確定状態')
                    ->options(fn () => collect(SettlementStatus::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])),
            ])
            ->recordActions([
                Action::make('forceCancel')
                    ->label('強制中断')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === JobStatus::RUNNING
                        && $record->started_at
                        && $record->started_at->diffInMinutes(now()) >= 10)
                    ->requiresConfirmation()
                    ->modalHeading('ジョブの強制中断')
                    ->modalDescription(fn ($record) => "このジョブ（{$record->batch_code}）を強制中断しますか？\n開始から".$record->started_at->diffInMinutes(now()).'分経過しています。')
                    ->action(function ($record) {
                        $record->markAsFailed('管理者による強制中断（タイムアウト）');

                        // 対応するwms_queue_progressもFAILEDにする
                        WmsQueueProgress::where('job_type', WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION)
                            ->whereIn('status', [QueueProgressStatus::PENDING, QueueProgressStatus::PROCESSING])
                            ->each(fn ($job) => $job->markAsFailed('管理者による強制中断'));

                        Notification::make()
                            ->title("ジョブ {$record->batch_code} を強制中断しました")
                            ->success()
                            ->send();
                    }),

                Action::make('viewResult')
                    ->label('結果')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('info')
                    ->visible(fn ($record) => ! empty($record->result_data) || $record->process_name === JobProcessName::STOCK_SNAPSHOT)
                    ->modalHeading(fn ($record) => match ($record->process_name) {
                        JobProcessName::STOCK_SNAPSHOT => "在庫スナップショット結果 - {$record->batch_code}",
                        default => "発注・移動候補生成結果 - {$record->batch_code}",
                    })
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalContent(fn ($record): View => view(
                        'filament.resources.wms-auto-order-job-controls.result-modal',
                        [
                            'result' => $record->result_data ?? [],
                            'record' => $record,
                        ]
                    )),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('id', 'desc');
    }
}
