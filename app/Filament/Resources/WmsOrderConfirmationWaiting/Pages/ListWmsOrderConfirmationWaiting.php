<?php

namespace App\Filament\Resources\WmsOrderConfirmationWaiting\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsTransferConfirmationWaitingTable;
use App\Filament\Resources\WmsOrderConfirmationWaiting\WmsOrderConfirmationWaitingResource;
use App\Jobs\ProcessOrderConfirmationJob;
use App\Jobs\ProcessTestOrderFilesJob;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\OrderConfirmationCleanupService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

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

    #[Url(as: 'tab')]
    public string $confirmationTab = 'order';

    public function getView(): string
    {
        return 'filament.resources.wms-order-confirmation-waiting.pages.list-wms-order-confirmation-waiting';
    }

    public function mount(): void
    {
        parent::mount();

        // キャッシュをクリアしてプリセットビューを再取得
        cache()->forget('transfer_confirmation_approved_warehouses_'.auth()->id());
        cache()->forget('order_confirmation_approved_warehouses_'.auth()->id());

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
                    ->title('発注・移動確定処理がタイムアウトしました')
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
        // 発注候補の件数を取得
        $orderApprovedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->count();
        $orderPendingCount = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();

        // 移動候補の件数を取得
        $transferApprovedCount = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)->count();
        $transferPendingCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();

        // 合計件数
        $totalApprovedCount = $orderApprovedCount + $transferApprovedCount;
        $totalPendingCount = $orderPendingCount + $transferPendingCount;

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
                ->visible($orderApprovedCount > 0 && ! $activeJob && ! $activeTestJob)
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
                ->label("発注・移動確定 (移動:{$transferApprovedCount}件 / 発注:{$orderApprovedCount}件)")
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('発注・移動確定')
                ->modalDescription(function () use ($orderApprovedCount, $orderPendingCount, $transferApprovedCount, $transferPendingCount, $totalPendingCount) {
                    // 未承認がある場合はエラーメッセージ
                    if ($totalPendingCount > 0) {
                        $messages = [];
                        if ($transferPendingCount > 0) {
                            $messages[] = "移動候補: {$transferPendingCount}件";
                        }
                        if ($orderPendingCount > 0) {
                            $messages[] = "発注候補: {$orderPendingCount}件";
                        }

                        return '⚠️ 未承認の候補があります。先に全ての候補を承認または除外してください。'."\n\n".
                            '【未承認件数】'."\n".implode("\n", $messages);
                    }

                    // 承認済みの内訳を表示
                    $details = [];
                    if ($transferApprovedCount > 0) {
                        $details[] = "移動候補: {$transferApprovedCount}件 → 移動伝票生成";
                    }
                    if ($orderApprovedCount > 0) {
                        $details[] = "発注候補: {$orderApprovedCount}件 → 発注送信データ生成・入庫予定作成";
                    }

                    return '以下の処理を実行します。'."\n\n".
                        implode("\n", $details)."\n\n".
                        '処理はバックグラウンドで実行されます。';
                })
                ->visible($totalApprovedCount > 0 && ! $activeJob)
                ->action(function () use ($orderPendingCount, $transferPendingCount, $totalPendingCount) {
                    // 未承認があれば確定不可
                    if ($totalPendingCount > 0) {
                        $messages = [];
                        if ($transferPendingCount > 0) {
                            $messages[] = "移動候補: {$transferPendingCount}件";
                        }
                        if ($orderPendingCount > 0) {
                            $messages[] = "発注候補: {$orderPendingCount}件";
                        }

                        Notification::make()
                            ->title('確定できません')
                            ->body('未承認の候補があります。'."\n".implode("\n", $messages))
                            ->danger()
                            ->send();

                        return;
                    }

                    // 進捗レコードを作成
                    $progress = WmsQueueProgress::createJob(
                        WmsQueueProgress::JOB_TYPE_ORDER_CONFIRMATION,
                        auth()->id()
                    );

                    // ジョブをディスパッチ（移動候補と発注候補を両方処理）
                    ProcessOrderConfirmationJob::dispatch($progress->job_id, auth()->id());

                    $this->activeJobId = $progress->job_id;

                    Notification::make()
                        ->title('発注・移動確定処理を開始しました')
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
                    ->title('発注・移動確定が完了しました')
                    ->body($progress->message)
                    ->success()
                    ->send();
            } elseif ($progress->isFailed()) {
                $this->activeJobId = null;

                Notification::make()
                    ->title('発注・移動確定が失敗しました')
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
        // タブに応じてテーブル構成を切り替え
        if ($this->confirmationTab === 'transfer') {
            return WmsTransferConfirmationWaitingTable::configure($table)
                ->query(
                    WmsStockTransferCandidate::query()
                        ->where('status', CandidateStatus::APPROVED)
                        ->with([
                            'satelliteWarehouse',
                            'hubWarehouse',
                            'item',
                            'contractor',
                            'deliveryCourse',
                        ])
                )
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->orderBy('batch_code', 'desc')
                    ->orderBy('satellite_warehouse_id')
                    ->orderBy('item_id')
                );
        }

        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->orderBy('batch_code', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    public function setConfirmationTab(string $tab): void
    {
        // キャッシュをクリア
        cache()->forget('transfer_confirmation_approved_warehouses_'.auth()->id());
        cache()->forget('order_confirmation_approved_warehouses_'.auth()->id());

        // URLパラメータ付きでリダイレクト
        $this->redirect(
            static::getResource()::getUrl('index', ['tab' => $tab]),
            navigate: true
        );
    }

    public function getOrderApprovedCount(): int
    {
        return WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->count();
    }

    public function getTransferApprovedCount(): int
    {
        return WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)->count();
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // タブに応じて倉庫リストを取得
        if ($this->confirmationTab === 'transfer') {
            // 移動確定待ち（APPROVED）に存在する依頼倉庫を取得
            $cacheKey = 'transfer_confirmation_approved_warehouses_'.auth()->id();
            $warehouseData = cache()->remember($cacheKey, 30, function () {
                $warehouseIds = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)
                    ->distinct()
                    ->pluck('satellite_warehouse_id')
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

            // デフォルト倉庫が移動確定待ちに存在するかチェック
            $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
            $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

            // プリセットビュー構築（データがなくても「全て」タブは常に表示）
            if ($defaultWarehouse) {
                $views = [
                    'default' => PresetView::make()
                        ->modifyQueryUsing(fn (Builder $query) => $query->where('satellite_warehouse_id', $userDefaultWarehouseId))
                        ->favorite()
                        ->label($defaultWarehouse->name)
                        ->default(),
                ];
            } else {
                $views = [
                    'default' => PresetView::make()
                        ->favorite()
                        ->label('全て')
                        ->default(),
                ];
            }

            $views['all'] = PresetView::make()
                ->favorite()
                ->label('全て');

            // 倉庫タブを追加（データがある場合のみ）
            foreach ($warehouses as $warehouse) {
                if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                    continue;
                }
                $views["default_{$warehouse->id}"] = PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('satellite_warehouse_id', $warehouse->id))
                    ->favorite()
                    ->label($warehouse->name);
            }

            return $views;
        }

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
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        // プリセットビュー構築（データがなくても「全て」タブは常に表示）
        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
                    ->favorite()
                    ->label($defaultWarehouse->name)
                    ->default(),
            ];
        } else {
            $views = [
                'default' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        $views['all'] = PresetView::make()
            ->favorite()
            ->label('全て');

        // 倉庫タブを追加（データがある場合のみ）
        foreach ($warehouses as $warehouse) {
            if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }
            $views["default_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
