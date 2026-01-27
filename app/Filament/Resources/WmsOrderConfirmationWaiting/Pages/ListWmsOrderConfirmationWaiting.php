<?php

namespace App\Filament\Resources\WmsOrderConfirmationWaiting\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderConfirmationWaiting\WmsOrderConfirmationWaitingResource;
use App\Jobs\ProcessOrderConfirmationJob;
use App\Jobs\ProcessTestOrderFilesJob;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Services\AutoOrder\OrderConfirmationCleanupService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsOrderConfirmationWaiting extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderConfirmationWaitingResource::class;

    public ?string $activeJobId = null;

    public ?string $activeTestJobId = null;

    public function getView(): string
    {
        return 'filament.resources.wms-order-confirmation-waiting.pages.list-wms-order-confirmation-waiting';
    }

    public function mount(): void
    {
        parent::mount();

        // アクティブな発注確定ジョブがあるかチェック
        $activeJob = WmsQueueProgress::getActiveJobForUser(
            WmsQueueProgress::JOB_TYPE_ORDER_CONFIRMATION,
            auth()->id()
        );

        if ($activeJob) {
            // タイムアウトチェック（15分以上経過していればクリーンアップ）
            $cleanupService = app(OrderConfirmationCleanupService::class);
            $cleanupResult = $cleanupService->checkAndCleanupTimedOutJob($activeJob);

            if ($cleanupResult) {
                // タイムアウトによりキャンセルされた
                Notification::make()
                    ->title('発注確定処理がタイムアウトしました')
                    ->body('15分以上経過したため処理をキャンセルしました。生成されたファイルは削除されました。')
                    ->danger()
                    ->persistent()
                    ->send();
            } else {
                $this->activeJobId = $activeJob->job_id;
            }
        }

        // アクティブなテストデータ生成ジョブがあるかチェック
        $activeTestJob = WmsQueueProgress::getActiveJobForUser(
            WmsQueueProgress::JOB_TYPE_TEST_ORDER_FILES,
            auth()->id()
        );

        if ($activeTestJob) {
            // テストデータ生成もタイムアウトチェック
            $cleanupService = app(OrderConfirmationCleanupService::class);
            if ($cleanupService->isTimedOut($activeTestJob)) {
                // テストデータは単純にエラー終了のみ（ファイル削除は不要、TEST状態のまま）
                $activeTestJob->markAsFailed('タイムアウト（15分経過）によりキャンセルされました。');

                Notification::make()
                    ->title('テストデータ生成がタイムアウトしました')
                    ->body('15分以上経過したため処理をキャンセルしました。')
                    ->danger()
                    ->persistent()
                    ->send();
            } else {
                $this->activeTestJobId = $activeTestJob->job_id;
            }
        }
    }

    protected function getHeaderActions(): array
    {
        // 承認済み件数を取得
        $approvedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->count();

        // 未承認（PENDING）件数を取得
        $pendingCount = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();

        // アクティブな発注確定ジョブがあるかチェック
        $activeJob = WmsQueueProgress::getActiveJobForUser(
            WmsQueueProgress::JOB_TYPE_ORDER_CONFIRMATION,
            auth()->id()
        );

        // アクティブなテストデータ生成ジョブがあるかチェック
        $activeTestJob = WmsQueueProgress::getActiveJobForUser(
            WmsQueueProgress::JOB_TYPE_TEST_ORDER_FILES,
            auth()->id()
        );

        return [
            Action::make('generateTestOrderFiles')
                ->label('発注送信テストデータ生成')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('発注送信テストデータの生成')
                ->modalDescription('承認済みの発注候補からテスト用の発注ファイルを生成します。このファイルはJX送信できません。処理はバックグラウンドで実行されます。')
                ->visible($approvedCount > 0 && ! $activeJob && ! $activeTestJob)
                ->action(function () {
                    // 進捗レコードを作成
                    $progress = WmsQueueProgress::createJob(
                        WmsQueueProgress::JOB_TYPE_TEST_ORDER_FILES,
                        auth()->id()
                    );

                    // ジョブをディスパッチ
                    ProcessTestOrderFilesJob::dispatch($progress->job_id, auth()->id());

                    $this->activeTestJobId = $progress->job_id;

                    Notification::make()
                        ->title('テストデータ生成を開始しました')
                        ->body('処理はバックグラウンドで実行されます。進捗は画面上部で確認できます。')
                        ->success()
                        ->send();
                }),

            Action::make('confirmAll')
                ->label("発注確定 ({$approvedCount}件)")
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('発注確定')
                ->modalDescription(function () use ($approvedCount, $pendingCount) {
                    if ($pendingCount > 0) {
                        return "⚠️ 未承認の発注候補が {$pendingCount}件 あります。先に全ての発注候補を承認してください。";
                    }

                    return "承認済みの発注候補 {$approvedCount}件 を確定し、発注送信データの生成・入庫予定の作成を行います。\n処理はバックグラウンドで実行されます。";
                })
                ->visible($approvedCount > 0 && ! $activeJob)
                ->action(function () use ($pendingCount) {
                    // 未承認があれば確定不可
                    if ($pendingCount > 0) {
                        Notification::make()
                            ->title('確定できません')
                            ->body("未承認の発注候補が {$pendingCount}件 あります。先に全ての発注候補を承認してください。")
                            ->danger()
                            ->send();

                        return;
                    }

                    // 進捗レコードを作成
                    $progress = WmsQueueProgress::createJob(
                        WmsQueueProgress::JOB_TYPE_ORDER_CONFIRMATION,
                        auth()->id()
                    );

                    // ジョブをディスパッチ
                    ProcessOrderConfirmationJob::dispatch($progress->job_id, auth()->id());

                    $this->activeJobId = $progress->job_id;

                    Notification::make()
                        ->title('発注確定処理を開始しました')
                        ->body('処理はバックグラウンドで実行されます。進捗は画面上部で確認できます。')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getProgressData(): ?array
    {
        if (! $this->activeJobId) {
            return null;
        }

        $progress = WmsQueueProgress::findByJobId($this->activeJobId);

        if (! $progress) {
            $this->activeJobId = null;

            return null;
        }

        // 完了または失敗の場合
        if ($progress->isCompleted() || $progress->isFailed()) {
            $data = [
                'status' => $progress->status->value,
                'progress' => $progress->progress,
                'message' => $progress->message,
                'result' => $progress->result,
            ];

            // 完了後にIDをクリア（次回ポーリングで非表示にする）
            if ($progress->isCompleted()) {
                $this->activeJobId = null;

                Notification::make()
                    ->title('発注確定が完了しました')
                    ->body($progress->message)
                    ->success()
                    ->send();
            } elseif ($progress->isFailed()) {
                $this->activeJobId = null;

                Notification::make()
                    ->title('発注確定が失敗しました')
                    ->body($progress->message)
                    ->danger()
                    ->send();
            }

            return $data;
        }

        return [
            'status' => $progress->status->value,
            'progress' => $progress->progress,
            'message' => $progress->message,
            'processed_items' => $progress->processed_items,
            'total_items' => $progress->total_items,
        ];
    }

    public function getTestProgressData(): ?array
    {
        if (! $this->activeTestJobId) {
            return null;
        }

        $progress = WmsQueueProgress::findByJobId($this->activeTestJobId);

        if (! $progress) {
            $this->activeTestJobId = null;

            return null;
        }

        // 完了または失敗の場合
        if ($progress->isCompleted() || $progress->isFailed()) {
            $data = [
                'status' => $progress->status->value,
                'progress' => $progress->progress,
                'message' => $progress->message,
                'result' => $progress->result,
            ];

            // 完了後にIDをクリア
            if ($progress->isCompleted()) {
                $this->activeTestJobId = null;

                Notification::make()
                    ->title('テストデータ生成が完了しました')
                    ->body($progress->message)
                    ->success()
                    ->send();
            } elseif ($progress->isFailed()) {
                $this->activeTestJobId = null;

                Notification::make()
                    ->title('テストデータ生成が失敗しました')
                    ->body($progress->message)
                    ->danger()
                    ->send();
            }

            return $data;
        }

        return [
            'status' => $progress->status->value,
            'progress' => $progress->progress,
            'message' => $progress->message,
            'processed_items' => $progress->processed_items,
            'total_items' => $progress->total_items,
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->orderBy('batch_code', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // 発注確定待ち（APPROVED）に存在する倉庫を取得（キャッシュして重複クエリを防止）
        $cacheKey = 'order_confirmation_approved_warehouses_'.auth()->id();
        $warehouseData = cache()->remember($cacheKey, 30, function () {
            $warehouseIds = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                ->distinct()
                ->pluck('warehouse_id')
                ->toArray();

            $warehouses = Warehouse::whereIn('id', $warehouseIds)
                ->orderBy('code')
                ->get(['id', 'name']);

            return [
                'ids' => $warehouseIds,
                'warehouses' => $warehouses,
            ];
        });

        $warehouseIds = $warehouseData['ids'];
        $warehouses = $warehouseData['warehouses'];

        // デフォルト倉庫が発注確定待ちに存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);

        // プリセットビュー構築
        $views = [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse),
        ];

        // 全ての倉庫タブを追加
        foreach ($warehouses as $warehouse) {
            $isDefault = $hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId;
            $views["warehouse_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name)
                ->default($isDefault);
        }

        return $views;
    }
}
