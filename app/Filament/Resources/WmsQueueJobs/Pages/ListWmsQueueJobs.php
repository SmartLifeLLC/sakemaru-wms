<?php

namespace App\Filament\Resources\WmsQueueJobs\Pages;

use App\Enums\AutoOrder\QueueJobStatus;
use App\Enums\AutoOrder\QueueJobType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsQueueJobs\WmsQueueJobResource;
use App\Models\WmsQueueJob;
use App\Services\AutoOrder\OrderCreateJobHandler;
use App\Services\AutoOrder\TransferCreateJobHandler;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsQueueJobs extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsQueueJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('processPending')
                ->label('待機中ジョブを処理')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('待機中ジョブを処理')
                ->modalDescription('待機中（pending）のジョブを1件ずつ処理します。処理が完了するとページが更新されます。')
                ->modalSubmitActionLabel('処理開始')
                ->action(function () {
                    $job = WmsQueueJob::getNextPending();

                    if (! $job) {
                        Notification::make()
                            ->title('待機中のジョブがありません')
                            ->info()
                            ->send();

                        return;
                    }

                    try {
                        $result = match ($job->job_type) {
                            QueueJobType::ORDER_CREATE => app(OrderCreateJobHandler::class)->handle($job),
                            QueueJobType::TRANSFER_CREATE => app(TransferCreateJobHandler::class)->handle($job),
                            default => throw new \RuntimeException("未実装のジョブタイプ: {$job->job_type->value}"),
                        };

                        if ($result['success_count'] ?? 0 > 0) {
                            Notification::make()
                                ->title('ジョブを処理しました')
                                ->body("作成: {$result['success_count']}件 / スキップ: {$result['skip_count']}件")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('ジョブ処理でエラーが発生しました')
                                ->body($result['error'] ?? '不明なエラー')
                                ->warning()
                                ->send();
                        }

                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('処理エラー')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->visible(fn () => WmsQueueJob::where('status', QueueJobStatus::PENDING)->exists()),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['logs'])
                ->orderBy('created_at', 'desc')
            );
    }

    public function getPresetViews(): array
    {
        return [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(),

            'pending' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', QueueJobStatus::PENDING))
                ->favorite()
                ->label('待機中'),

            'processing' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', QueueJobStatus::PROCESSING))
                ->favorite()
                ->label('処理中'),

            'completed' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', QueueJobStatus::COMPLETED))
                ->favorite()
                ->label('完了'),

            'failed' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', QueueJobStatus::FAILED))
                ->favorite()
                ->label('失敗'),
        ];
    }
}
