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
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
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

    public string $fileSplitMode = 'split';

    #[Url(as: 'tab')]
    public string $confirmationTab = 'order';

    public function getHeading(): string|Htmlable
    {
        $orderCount = $this->getOrderApprovedCount();
        $transferCount = $this->getTransferApprovedCount();

        $orderActive = $this->confirmationTab === 'order';
        $transferActive = $this->confirmationTab === 'transfer';

        $activeTab = 'bg-slate-800 text-white rounded-md shadow-sm dark:bg-slate-700';
        $inactiveTab = 'bg-transparent text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300';
        $activeBadge = 'bg-white/25 text-white';
        $inactiveBadge = 'bg-gray-200 text-gray-500 dark:bg-gray-600 dark:text-gray-400';

        $orderClasses = $orderActive ? $activeTab : $inactiveTab;
        $transferClasses = $transferActive ? $activeTab : $inactiveTab;
        $orderBadgeClasses = $orderActive ? $activeBadge : $inactiveBadge;
        $transferBadgeClasses = $transferActive ? $activeBadge : $inactiveBadge;

        return new HtmlString(
            '<nav class="flex gap-2 items-center">' .
            '<button wire:click="setConfirmationTab(\'order\')" class="px-4 py-1 text-base font-semibold transition-all whitespace-nowrap ' . $orderClasses . '">' .
            '発注確定待ち<span class="ml-1.5 px-1.5 py-0.5 text-xs font-bold rounded ' . $orderBadgeClasses . '">' . $orderCount . '</span>' .
            '</button>' .
            '<button wire:click="setConfirmationTab(\'transfer\')" class="px-4 py-1 text-base font-semibold transition-all whitespace-nowrap ' . $transferClasses . '">' .
            '移動確定待ち<span class="ml-1.5 px-1.5 py-0.5 text-xs font-bold rounded ' . $transferBadgeClasses . '">' . $transferCount . '</span>' .
            '</button>' .
            '</nav>'
        );
    }

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
        $selectedWarehouse = $this->getConfirmationScopeWarehouse();
        $selectedWarehouseId = $selectedWarehouse?->id;
        $selectedWarehouseName = $selectedWarehouse?->name ?? '未選択';

        $globalOrderApprovedCount = $this->getOrderApprovedCount();
        $globalTransferApprovedCount = $this->getTransferApprovedCount();
        $globalTotalApprovedCount = $globalOrderApprovedCount + $globalTransferApprovedCount;

        // 確定対象表示用（選択中倉庫）
        $orderApprovedCount = $this->getOrderApprovedCount($selectedWarehouseId);
        $transferApprovedCount = $this->getTransferApprovedCount($selectedWarehouseId);
        $totalApprovedCount = $orderApprovedCount + $transferApprovedCount;

        // アクティブな発注確定ジョブがあるかチェック
        $activeJob = WmsQueueProgress::getActiveJobForUser(
            WmsQueueProgress::JOB_TYPE_ORDER_CONFIRMATION,
            auth()->id()
        );

        $activeTestJob = WmsQueueProgress::getActiveJobForUser(
            WmsQueueProgress::JOB_TYPE_TEST_ORDER_FILES,
            auth()->id()
        );

        return [
            Action::make('confirmAll')
                ->label($selectedWarehouseId
                    ? "{$selectedWarehouseName}の発注・移動確定"
                    : '倉庫別の発注・移動確定')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->extraAttributes(['class' => 'wms-order-confirm-action'])
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalHeading("発注・移動確定（{$selectedWarehouseName}）")
                ->modalDescription(function () use ($selectedWarehouseId, $selectedWarehouseName, $orderApprovedCount, $transferApprovedCount) {
                    if (! $selectedWarehouseId) {
                        return 'トップバーから倉庫を選択してください。';
                    }

                    $details = [];
                    if ($transferApprovedCount > 0) {
                        $details[] = "移動候補: {$transferApprovedCount}件 → 移動伝票生成";
                    }
                    if ($orderApprovedCount > 0) {
                        $details[] = "発注候補: {$orderApprovedCount}件 → 発注送信データ生成・入荷予定作成";
                    }

                    return "倉庫「{$selectedWarehouseName}」の承認済み候補のみ確定します。\n".
                        "発注データファイルは倉庫別で生成されます。\n\n".
                        '以下の処理を実行します。'."\n\n".
                        implode("\n", $details)."\n\n".
                        '処理はバックグラウンドで実行されます。';
                })
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('確定実行')->color('danger'))
                ->modalCancelActionLabel('確定せず閉じる')
                ->visible($globalTotalApprovedCount > 0 && ! $activeJob)
                ->disabled(! $selectedWarehouseId || $totalApprovedCount === 0)
                ->action(function () use ($selectedWarehouseId, $selectedWarehouseName) {
                    if (! $selectedWarehouseId) {
                        Notification::make()
                            ->title('倉庫が選択されていません')
                            ->body('トップバーから確定対象の倉庫を選択してください。')
                            ->danger()
                            ->send();

                        return;
                    }

                    $splitByWarehouse = true;

                    // 進捗レコードを作成
                    $progress = WmsQueueProgress::createJob(
                        WmsQueueProgress::JOB_TYPE_ORDER_CONFIRMATION,
                        auth()->id(),
                        ['warehouse_id' => $selectedWarehouseId]
                    );

                    // ジョブをディスパッチ（選択中倉庫の移動候補と発注候補のみ処理）
                    ProcessOrderConfirmationJob::dispatch(
                        $progress->job_id,
                        auth()->id(),
                        $splitByWarehouse,
                        $selectedWarehouseId
                    );

                    $this->activeJobId = $progress->job_id;

                    Notification::make()
                        ->title("倉庫「{$selectedWarehouseName}」の発注・移動確定処理を開始しました")
                        ->body('処理はバックグラウンドで実行されます。進捗は画面上部で確認できます。')
                        ->success()
                        ->send();
                }),

            ActionGroup::make([
                Action::make('confirmAllWarehouses')
                    ->label("発注・移動確定（全倉庫 / 移動:{$globalTransferApprovedCount}件 / 発注:{$globalOrderApprovedCount}件）")
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalHeading('発注・移動確定（全倉庫）')
                    ->modalDescription(function () use ($globalOrderApprovedCount, $globalTransferApprovedCount) {
                        $details = [];
                        if ($globalTransferApprovedCount > 0) {
                            $details[] = "移動候補: {$globalTransferApprovedCount}件 → 移動伝票生成";
                        }
                        if ($globalOrderApprovedCount > 0) {
                            $details[] = "発注候補: {$globalOrderApprovedCount}件 → 発注送信データ生成・入荷予定作成";
                        }

                        $description = '全倉庫の承認済み候補をまとめて確定します。';
                        if ($globalOrderApprovedCount > 0) {
                            $description .= "\n発注データファイルの出力方式を選択してください。";
                        }

                        return $description."\n\n".
                            '以下の処理を実行します。'."\n\n".
                            implode("\n", $details)."\n\n".
                            '処理はバックグラウンドで実行されます。';
                    })
                    ->schema([
                        ViewField::make('file_split_mode')
                            ->view('filament.components.file-split-mode-selection')
                            ->hiddenLabel()
                            ->visible($globalOrderApprovedCount > 0),
                    ])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('確定実行')->color('danger'))
                    ->modalCancelActionLabel('確定せず閉じる')
                    ->visible($globalTotalApprovedCount > 0 && ! $activeJob)
                    ->action(function () {
                        $splitByWarehouse = $this->fileSplitMode === 'split';

                        $progress = WmsQueueProgress::createJob(
                            WmsQueueProgress::JOB_TYPE_ORDER_CONFIRMATION,
                            auth()->id()
                        );

                        ProcessOrderConfirmationJob::dispatch(
                            $progress->job_id,
                            auth()->id(),
                            $splitByWarehouse
                        );

                        $this->activeJobId = $progress->job_id;

                        Notification::make()
                            ->title('全倉庫の発注・移動確定処理を開始しました')
                            ->body('処理はバックグラウンドで実行されます。進捗は画面上部で確認できます。')
                            ->success()
                            ->send();
                    }),

                Action::make('generateTestOrderFiles')
                    ->label('発注送信テストデータ生成')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalHeading('発注送信テストデータの生成')
                    ->modalDescription('承認済みの発注候補からテスト用の発注ファイルを生成します。このファイルはJX送信できません。処理はバックグラウンドで実行されます。')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('生成開始')->color('danger'))
                    ->modalCancelActionLabel('生成せず閉じる')
                    ->visible($globalOrderApprovedCount > 0 && ! $activeJob && ! $activeTestJob)
                    ->action(function () {
                        $progress = WmsQueueProgress::createJob(
                            WmsQueueProgress::JOB_TYPE_TEST_ORDER_FILES,
                            auth()->id()
                        );

                        ProcessTestOrderFilesJob::dispatch($progress->job_id, auth()->id());

                        $this->activeTestJobId = $progress->job_id;

                        Notification::make()
                            ->title('テストデータ生成を開始しました')
                            ->body('処理はバックグラウンドで実行されます。進捗は画面上部で確認できます。')
                            ->success()
                            ->send();
                    }),
            ])
                ->label('管理者メニュー')
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->button()
                ->visible($globalTotalApprovedCount > 0 && ! $activeJob),
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

        // 現在のプリセットビューを引き継いでリダイレクト
        $params = ['tab' => $tab];
        if ($this->activePresetView) {
            $params['activePresetView'] = $this->activePresetView;
            $params['currentPresetView'] = $this->activePresetView;
        }

        $this->redirect(
            static::getResource()::getUrl('index', $params),
            navigate: true
        );
    }

    public function getOrderApprovedCount(?int $warehouseId = null): int
    {
        return $this->getOrderApprovedCountForWarehouse($warehouseId);
    }

    public function getTransferApprovedCount(?int $warehouseId = null): int
    {
        return $this->getTransferApprovedCountForWarehouse($warehouseId);
    }

    private function getOrderApprovedCountForWarehouse(?int $warehouseId): int
    {
        $query = WmsOrderCandidate::where('status', CandidateStatus::APPROVED);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->count();
    }

    private function getTransferApprovedCountForWarehouse(?int $warehouseId): int
    {
        $query = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED);

        if ($warehouseId !== null) {
            $query->where('satellite_warehouse_id', $warehouseId);
        }

        return $query->count();
    }

    private function getConfirmationScopeWarehouse(): ?Warehouse
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        if (! $warehouseId) {
            return null;
        }

        return Warehouse::find($warehouseId);
    }

    public function getPresetViews(): array
    {
        // ユーザーの選択中倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->getSelectedWarehouseId();

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

            if ($defaultWarehouse) {
                $views = [
                    'default' => PresetView::make()
                        ->modifyQueryUsing(fn (Builder $query) => $query->where('satellite_warehouse_id', $userDefaultWarehouseId))
                        ->favorite()
                        ->label($defaultWarehouse->name)
                        ->default(),
                    'all' => PresetView::make()
                        ->favorite()
                        ->label('全て'),
                ];
            } else {
                $views = [
                    'default' => PresetView::make()
                        ->favorite()
                        ->label('全て')
                        ->default(),
                ];
            }

            foreach ($warehouses as $warehouse) {
                if ($defaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                    continue;
                }
                $views["wh_{$warehouse->id}"] = PresetView::make()
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

        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
                    ->favorite()
                    ->label($defaultWarehouse->name)
                    ->default(),
                'all' => PresetView::make()
                    ->favorite()
                    ->label('全て'),
            ];
        } else {
            $views = [
                'default' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        foreach ($warehouses as $warehouse) {
            if ($defaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }
            $views["wh_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
