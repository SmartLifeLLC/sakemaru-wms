<?php

namespace App\Filament\Resources\WmsQueueJobs\Tables;

use App\Enums\AutoOrder\QueueJobStatus;
use App\Enums\AutoOrder\QueueJobType;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsQueueJobsTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'queue-jobs-table sticky-actions'])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('job_type')
                    ->label('ジョブタイプ')
                    ->badge()
                    ->formatStateUsing(fn (QueueJobType $state): string => $state->label())
                    ->color('info')
                    ->sortable()
                    ->width('120px'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (QueueJobStatus $state): string => $state->label())
                    ->color(fn (QueueJobStatus $state): string => $state->color())
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('source_system')
                    ->label('依頼元')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-')
                    ->width('70px'),

                TextColumn::make('source_reference_id')
                    ->label('参照ID')
                    ->formatStateUsing(fn ($record) => $record->source_reference_type
                        ? "{$record->source_reference_type}:{$record->source_reference_id}"
                        : '-')
                    ->placeholder('-')
                    ->toggleable()
                    ->width('150px'),

                TextColumn::make('attempts')
                    ->label('試行')
                    ->formatStateUsing(fn ($record) => "{$record->attempts}/{$record->max_attempts}")
                    ->alignCenter()
                    ->width('60px'),

                TextColumn::make('result_summary')
                    ->label('結果')
                    ->state(function ($record) {
                        if (! $record->result) {
                            return '-';
                        }
                        $success = $record->result['success_count'] ?? 0;
                        $skip = $record->result['skip_count'] ?? 0;

                        return "成功:{$success} / スキップ:{$skip}";
                    })
                    ->placeholder('-')
                    ->width('130px'),

                TextColumn::make('created_at')
                    ->label('登録日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->width('90px'),

                TextColumn::make('started_at')
                    ->label('開始日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->placeholder('-')
                    ->width('90px'),

                TextColumn::make('completed_at')
                    ->label('完了日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->placeholder('-')
                    ->width('90px'),

                TextColumn::make('error_message')
                    ->label('エラー')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->error_message)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('job_type')
                    ->label('ジョブタイプ')
                    ->options(fn () => collect(QueueJobType::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(fn () => collect(QueueJobStatus::cases())
                        ->mapWithKeys(fn ($case) => [$case->value => $case->label()])),

                SelectFilter::make('source_system')
                    ->label('依頼元')
                    ->options([
                        'trade' => 'Trade',
                        'wms' => 'WMS',
                        'batch' => 'Batch',
                    ]),
            ])
            ->recordActionsColumnLabel('操作')
            ->recordActions([
                Action::make('viewDetail')
                    ->label('詳細')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn ($record) => "Queueジョブ詳細 - #{$record->id}")
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->infolist(function ($record): array {
                        return [
                            Section::make('基本情報')
                                ->schema([
                                    Grid::make(3)
                                        ->schema([
                                            TextEntry::make('job_type')
                                                ->label('ジョブタイプ')
                                                ->state(fn () => $record->job_type->label())
                                                ->badge()
                                                ->color('info'),
                                            TextEntry::make('status')
                                                ->label('ステータス')
                                                ->state(fn () => $record->status->label())
                                                ->badge()
                                                ->color(fn () => $record->status->color()),
                                            TextEntry::make('attempts')
                                                ->label('試行回数')
                                                ->state(fn () => "{$record->attempts}/{$record->max_attempts}"),
                                        ]),
                                    Grid::make(3)
                                        ->schema([
                                            TextEntry::make('source_system')
                                                ->label('依頼元システム')
                                                ->state(fn () => $record->source_system ?? '-'),
                                            TextEntry::make('source_user_id')
                                                ->label('依頼元ユーザーID')
                                                ->state(fn () => $record->source_user_id ?? '-'),
                                            TextEntry::make('source_reference')
                                                ->label('参照先')
                                                ->state(fn () => $record->source_reference_type
                                                    ? "{$record->source_reference_type}:{$record->source_reference_id}"
                                                    : '-'),
                                        ]),
                                ]),
                            Section::make('日時情報')
                                ->schema([
                                    Grid::make(3)
                                        ->schema([
                                            TextEntry::make('created_at')
                                                ->label('登録日時')
                                                ->state(fn () => $record->created_at?->format('Y-m-d H:i:s') ?? '-'),
                                            TextEntry::make('started_at')
                                                ->label('開始日時')
                                                ->state(fn () => $record->started_at?->format('Y-m-d H:i:s') ?? '-'),
                                            TextEntry::make('completed_at')
                                                ->label('完了日時')
                                                ->state(fn () => $record->completed_at?->format('Y-m-d H:i:s') ?? '-'),
                                        ]),
                                ]),
                            Section::make('Payload')
                                ->schema([
                                    TextEntry::make('payload')
                                        ->label('')
                                        ->state(fn () => json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                                        ->formatStateUsing(fn ($state) => $state)
                                        ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap bg-gray-100 p-2 rounded']),
                                ])
                                ->collapsible(),
                            Section::make('結果')
                                ->schema([
                                    TextEntry::make('result')
                                        ->label('')
                                        ->state(fn () => $record->result
                                            ? json_encode($record->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                            : '-')
                                        ->formatStateUsing(fn ($state) => $state)
                                        ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap bg-gray-100 p-2 rounded']),
                                ])
                                ->collapsible()
                                ->visible(fn () => ! empty($record->result)),
                            Section::make('エラー')
                                ->schema([
                                    TextEntry::make('error_message')
                                        ->label('')
                                        ->state(fn () => $record->error_message ?? '-')
                                        ->extraAttributes(['class' => 'text-red-600']),
                                ])
                                ->visible(fn () => ! empty($record->error_message)),
                            Section::make('ログ')
                                ->schema([
                                    TextEntry::make('logs')
                                        ->label('')
                                        ->state(function () use ($record) {
                                            $logs = $record->logs()->orderBy('created_at', 'desc')->get();
                                            if ($logs->isEmpty()) {
                                                return 'ログなし';
                                            }

                                            return $logs->map(function ($log) {
                                                $time = $log->created_at?->format('H:i:s') ?? '';
                                                $level = strtoupper($log->level->value ?? 'INFO');

                                                return "[{$time}] [{$level}] {$log->message}";
                                            })->implode("\n");
                                        })
                                        ->formatStateUsing(fn ($state) => $state)
                                        ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap bg-gray-100 p-2 rounded max-h-48 overflow-y-auto']),
                                ])
                                ->collapsible(),
                        ];
                    }),

                Action::make('retry')
                    ->label('再試行')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('ジョブを再試行')
                    ->modalDescription('このジョブをpending状態に戻し、再処理可能にします。')
                    ->action(function ($record) {
                        $record->update([
                            'status' => QueueJobStatus::PENDING,
                            'error_message' => null,
                            'completed_at' => null,
                        ]);
                    })
                    ->visible(fn ($record) => $record->status === QueueJobStatus::FAILED),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
