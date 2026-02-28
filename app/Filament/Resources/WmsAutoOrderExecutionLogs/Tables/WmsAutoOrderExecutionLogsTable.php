<?php

namespace App\Filament\Resources\WmsAutoOrderExecutionLogs\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Jobs\ProcessOrderCandidateGenerationJob;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsAutoOrderExecutionLog;
use App\Models\WmsQueueProgress;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class WmsAutoOrderExecutionLogsTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('executed_date')
                    ->label('実行日')
                    ->date('Y-m-d')
                    ->sortable(),

                TextColumn::make('contractor.name')
                    ->label('仕入先名')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'RUNNING' => 'info',
                        'SUCCESS' => 'success',
                        'FAILED' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('started_at')
                    ->label('開始日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('finished_at')
                    ->label('完了日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('error_details')
                    ->label('エラー内容')
                    ->limit(50)
                    ->placeholder('-')
                    ->tooltip(fn ($record) => $record->error_details),

                TextColumn::make('job_control_id')
                    ->label('関連ジョブ')
                    ->placeholder('-')
                    ->url(fn ($record) => $record->job_control_id
                        ? route('filament.admin.resources.wms-auto-order-job-controls.index')
                        : null),
            ])
            ->filters([
                SelectFilter::make('executed_date')
                    ->form([
                        DatePicker::make('executed_date')
                            ->label('実行日')
                            ->default(today()),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['executed_date'],
                        fn ($q, $date) => $q->where('executed_date', $date)
                    )),

                SelectFilter::make('contractor_id')
                    ->label('仕入先')
                    ->options(fn () => Contractor::orderBy('code')->pluck('name', 'id')->toArray())
                    ->searchable(),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options([
                        'RUNNING' => '実行中',
                        'SUCCESS' => '成功',
                        'FAILED' => '失敗',
                    ]),
            ])
            ->recordActions([
                Action::make('viewError')
                    ->label('エラー詳細')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === 'FAILED')
                    ->modalHeading('エラー詳細')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->infolist(fn ($record) => [
                        Section::make()->schema([
                            TextEntry::make('contractor.name')->label('仕入先'),
                            TextEntry::make('executed_date')->label('実行日'),
                            TextEntry::make('started_at')->label('開始日時'),
                            TextEntry::make('error_details')->label('エラー内容')
                                ->columnSpanFull()
                                ->prose(),
                        ]),
                    ]),

                Action::make('retry')
                    ->label('再実行')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === 'FAILED')
                    ->requiresConfirmation()
                    ->modalHeading('この仕入先の発注計算を再実行しますか？')
                    ->modalDescription(fn ($record) => "仕入先: {$record->contractor->name}\n前回エラー: ".Str::limit($record->error_details, 100))
                    ->action(function ($record) {
                        // 新しい実行ログを作成
                        $log = WmsAutoOrderExecutionLog::create([
                            'contractor_id' => $record->contractor_id,
                            'executed_date' => today(),
                            'status' => 'RUNNING',
                            'started_at' => now(),
                        ]);

                        // 進捗レコードを作成
                        $queueProgress = WmsQueueProgress::createJob(
                            WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                            auth()->id(),
                            ['contractor_id' => $record->contractor_id, 'source' => 'retry']
                        );

                        // Job起動
                        ProcessOrderCandidateGenerationJob::dispatch(
                            jobId: $queueProgress->job_id,
                            deletePending: false,
                            contractorId: $record->contractor_id,
                            executionLogId: $log->id,
                        );

                        Notification::make()
                            ->title('再実行を開始しました')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('id', 'desc');
    }
}
