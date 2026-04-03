<?php

namespace App\Filament\Resources\WmsAutoOrderJobControls\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\QueueProgressStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsAutoOrderJobControls\WmsAutoOrderJobControlResource;
use App\Jobs\ProcessOrderCandidateGenerationJob;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderExecutionLog;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use App\Models\WmsWarehouseAutoOrderSetting;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Builder;

class ListWmsAutoOrderJobControls extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsAutoOrderJobControlResource::class;

    // ウィザードの状態管理
    public int $wizardStep = 0;

    public bool $isProcessing = false;

    public int $progress = 0;

    public string $progressMessage = '';

    public array $results = [];

    public ?string $errorMessage = null;

    public int $pendingCount = 0;

    public int $pendingTransferCount = 0;

    public int $approvedCount = 0;

    // Queue進捗管理
    public ?string $currentJobId = null;

    // アクティブジョブ検知
    public ?array $stuckJob = null;

    public function mount(): void
    {
        parent::mount();
        // ページ表示時にカウントを取得
        $this->pendingCount = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();
        $this->pendingTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();
        $this->approvedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->count();
        $this->checkForStuckJob();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getGenerateByWarehouseAction(),

            ActionGroup::make([
                $this->getOrderGenerationWizardAction(),
                $this->getGenerateTransferCandidatesAction(),
                $this->getForceGenerateByContractorAction(),
            ])
                ->label('管理者メニュー')
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->button(),
        ];
    }

    private function getGenerateByWarehouseAction(): Action
    {
        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $selectedWarehouse = $selectedWarehouseId ? Warehouse::find($selectedWarehouseId) : null;
        $selectedWarehouseName = $selectedWarehouse?->name ?? '未選択';

        return Action::make('generateByWarehouse')
            ->label('発注・移動候補生成')
            ->icon('heroicon-o-building-storefront')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading("発注・移動候補生成（{$selectedWarehouseName}）")
            ->modalDescription($selectedWarehouse
                ? "倉庫「{$selectedWarehouseName}」の発注・移動候補を生成します。"
                : '倉庫が選択されていません。トップバーから倉庫を選択してください。')
            ->disabled(! $selectedWarehouse)
            ->action(function () use ($selectedWarehouseId, $selectedWarehouseName) {
                $warehouseId = $selectedWarehouseId;
                $warehouseName = $selectedWarehouseName;

                // 該当倉庫のAPPROVED候補がある場合はブロック
                $hasApprovedOrders = WmsOrderCandidate::query()
                    ->where('status', CandidateStatus::APPROVED)
                    ->where('warehouse_id', $warehouseId)
                    ->exists();

                $hasApprovedTransfers = WmsStockTransferCandidate::query()
                    ->where('status', CandidateStatus::APPROVED)
                    ->where('satellite_warehouse_id', $warehouseId)
                    ->exists();

                if ($hasApprovedOrders || $hasApprovedTransfers) {
                    Notification::make()
                        ->title("倉庫「{$warehouseName}」に承認済みの候補があります")
                        ->body('先に確定処理を行ってください')
                        ->danger()
                        ->send();

                    return;
                }

                // 該当倉庫のPENDING候補を削除
                $deletedOrders = WmsOrderCandidate::query()
                    ->where('status', CandidateStatus::PENDING)
                    ->where('warehouse_id', $warehouseId)
                    ->delete();

                $deletedTransfers = WmsStockTransferCandidate::query()
                    ->where('status', CandidateStatus::PENDING)
                    ->where('satellite_warehouse_id', $warehouseId)
                    ->delete();

                // 進捗レコードを作成
                $queueProgress = WmsQueueProgress::createJob(
                    WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                    auth()->id(),
                    ['warehouse_id' => $warehouseId, 'source' => 'warehouse_specific']
                );

                // Job dispatch（warehouseId指定）
                ProcessOrderCandidateGenerationJob::dispatch(
                    jobId: $queueProgress->job_id,
                    deletePending: false,
                    contractorId: null,
                    executionLogId: null,
                    transferOnly: false,
                    warehouseId: $warehouseId,
                    createdBy: auth()->id(),
                );

                $message = "倉庫「{$warehouseName}」の発注・移動候補生成を開始しました";
                if ($deletedOrders > 0 || $deletedTransfers > 0) {
                    $message .= "（PENDING候補 発注:{$deletedOrders}件 移動:{$deletedTransfers}件 を削除）";
                }

                Notification::make()
                    ->title($message)
                    ->success()
                    ->send();
            });
    }

    private function getOrderGenerationWizardAction(): Action
    {
        return Action::make('orderGenerationWizard')
            ->label('発注・移動候補生成')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->modalWidth('4xl')
            ->modalHeading('発注・移動候補生成')
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->schema([
                View::make('filament.components.order-generation-wizard')
                    ->viewData(fn () => [
                        'step' => $this->wizardStep,
                        'isProcessing' => $this->isProcessing,
                        'progress' => $this->progress,
                        'progressMessage' => $this->progressMessage,
                        'results' => $this->results,
                        'errorMessage' => $this->errorMessage,
                        'pendingCount' => $this->pendingCount,
                        'pendingTransferCount' => $this->pendingTransferCount,
                        'approvedCount' => $this->approvedCount,
                        'currentJobId' => $this->currentJobId,
                        'stuckJob' => $this->stuckJob,
                    ]),
            ])
            ->action(fn () => null)
            ->before(function () {
                $this->resetWizard();
            });
    }

    private function getGenerateTransferCandidatesAction(): Action
    {
        return Action::make('generateTransferCandidates')
            ->label('移動候補生成')
            ->icon('heroicon-o-arrows-right-left')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('移動候補の一括生成')
            ->modalDescription(fn () => $this->pendingTransferCount > 0
                ? "未承認の移動候補が {$this->pendingTransferCount}件 あります。削除して再生成します。"
                : '全仕入先のINTERNAL移動候補を生成します。発注候補は生成しません。')
            ->action(function () {
                // APPROVED移動候補がある場合はブロック
                $approvedTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)->count();
                if ($approvedTransferCount > 0) {
                    Notification::make()
                        ->title("確定待ちの移動候補が {$approvedTransferCount}件 あります")
                        ->body('先に確定処理を行ってください')
                        ->danger()
                        ->send();

                    return;
                }

                // PENDING移動候補を削除
                $deletedTransfers = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->delete();

                // 進捗レコードを作成
                $queueProgress = WmsQueueProgress::createJob(
                    WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                    auth()->id(),
                    ['source' => 'transfer_only', 'deletedTransfers' => $deletedTransfers]
                );

                // Job dispatch（transferOnly=true）
                ProcessOrderCandidateGenerationJob::dispatch(
                    jobId: $queueProgress->job_id,
                    deletePending: false,
                    contractorId: null,
                    executionLogId: null,
                    transferOnly: true,
                    createdBy: auth()->id(),
                );

                $message = '移動候補の生成を開始しました';
                if ($deletedTransfers > 0) {
                    $message .= "（PENDING移動候補 {$deletedTransfers}件 を削除）";
                }

                Notification::make()
                    ->title($message)
                    ->success()
                    ->send();
            });
    }

    private function getForceGenerateByContractorAction(): Action
    {
        return Action::make('forceGenerateByContractor')
            ->label('仕入先別発注候補生成')
            ->icon('heroicon-o-bolt')
            ->color('success')
            ->schema([
                Select::make('contractor_id')
                    ->label('仕入先')
                    ->options(fn () => Contractor::orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"])
                        ->toArray())
                    ->searchable()
                    ->required(),
            ])
            ->requiresConfirmation()
            ->modalHeading('仕入先別 発注・移動候補の強制生成')
            ->modalDescription('指定した仕入先に対して発注・移動候補を生成します。未処理の候補がある場合は削除して再生成します。')
            ->action(function (array $data) {
                $contractorId = (int) $data['contractor_id'];

                // PENDING候補がある場合は削除してから再生成
                $deletedOrders = WmsOrderCandidate::query()
                    ->where('status', CandidateStatus::PENDING)
                    ->where('contractor_id', $contractorId)
                    ->delete();

                $deletedTransfers = WmsStockTransferCandidate::query()
                    ->where('status', CandidateStatus::PENDING)
                    ->where('contractor_id', $contractorId)
                    ->delete();

                // APPROVED候補がある場合はブロック
                $hasApprovedOrders = WmsOrderCandidate::query()
                    ->where('status', CandidateStatus::APPROVED)
                    ->where('contractor_id', $contractorId)
                    ->exists();

                $hasApprovedTransfers = WmsStockTransferCandidate::query()
                    ->where('status', CandidateStatus::APPROVED)
                    ->where('contractor_id', $contractorId)
                    ->exists();

                if ($hasApprovedOrders || $hasApprovedTransfers) {
                    Notification::make()
                        ->title('承認済みの候補があるため生成できません')
                        ->body('先に確定処理を行ってください')
                        ->danger()
                        ->send();

                    return;
                }

                // 実行ログを記録
                $log = WmsAutoOrderExecutionLog::create([
                    'contractor_id' => $contractorId,
                    'executed_date' => today(),
                    'status' => 'RUNNING',
                    'started_at' => now(),
                ]);

                // 進捗レコードを作成
                $queueProgress = WmsQueueProgress::createJob(
                    WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                    auth()->id(),
                    ['contractor_id' => $contractorId, 'source' => 'force_generate']
                );

                // Job dispatch
                ProcessOrderCandidateGenerationJob::dispatch(
                    jobId: $queueProgress->job_id,
                    deletePending: false,
                    contractorId: $contractorId,
                    executionLogId: $log->id,
                    createdBy: auth()->id(),
                );

                $contractorName = Contractor::find($contractorId)?->name ?? $contractorId;
                $message = "仕入先「{$contractorName}」の発注候補生成を開始しました";
                if ($deletedOrders > 0 || $deletedTransfers > 0) {
                    $message .= "（PENDING候補 発注:{$deletedOrders}件 移動:{$deletedTransfers}件 を削除）";
                }

                Notification::make()
                    ->title($message)
                    ->success()
                    ->send();
            });
    }

    public function resetWizard(): void
    {
        $this->wizardStep = 0;
        $this->isProcessing = false;
        $this->progress = 0;
        $this->progressMessage = '';
        $this->results = [];
        $this->errorMessage = null;
        $this->currentJobId = null;
        $this->stuckJob = null;
        // ウィザード開始時のみカウントを取得（毎回のレンダリングで実行しない）
        $this->pendingCount = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();
        $this->pendingTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();
        $this->approvedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->count();
        $this->checkForStuckJob();
    }

    /**
     * アクティブ（PENDING/PROCESSING）のままスタックしたジョブを検知
     */
    private function checkForStuckJob(): void
    {
        $activeJob = WmsQueueProgress::where('job_type', WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION)
            ->whereIn('status', [QueueProgressStatus::PENDING, QueueProgressStatus::PROCESSING])
            ->latest()
            ->first();

        if ($activeJob) {
            $this->stuckJob = [
                'id' => $activeJob->id,
                'job_id' => $activeJob->job_id,
                'status' => $activeJob->status->label(),
                'progress' => $activeJob->progress,
                'message' => $activeJob->message,
                'started_at' => $activeJob->started_at?->format('Y-m-d H:i:s'),
                'created_at' => $activeJob->created_at?->format('Y-m-d H:i:s'),
            ];
        }
    }

    /**
     * スタックしたジョブを強制的にFAILEDにマーク
     */
    public function forceCancel(): void
    {
        $activeJobs = WmsQueueProgress::where('job_type', WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION)
            ->whereIn('status', [QueueProgressStatus::PENDING, QueueProgressStatus::PROCESSING])
            ->get();

        $count = 0;
        foreach ($activeJobs as $job) {
            $job->markAsFailed('管理者による強制中断');
            $count++;
        }

        $this->stuckJob = null;

        Notification::make()
            ->title("{$count}件のジョブを強制中断しました")
            ->success()
            ->send();
    }

    public function executeStep1Delete(): void
    {
        $this->isProcessing = true;
        $this->progressMessage = '未承認の発注・移動候補を削除中...';

        try {
            $deletedOrders = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->delete();
            $deletedTransfers = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->delete();

            // 確定待ち（PENDING）のジョブをキャンセル
            $cancelledJobs = WmsAutoOrderJobControl::cancelPendingSettlements();

            $this->results['deleted'] = $deletedOrders;
            $this->results['deletedTransfers'] = $deletedTransfers;
            $this->results['cancelledJobs'] = $cancelledJobs;
            $this->pendingCount = 0;
            $this->pendingTransferCount = 0;
            $this->wizardStep = 1;
            $this->isProcessing = false;
            $this->progress = 0;
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
            $this->isProcessing = false;
        }
    }

    public function skipStep1Delete(): void
    {
        $this->results['deleted'] = 0;
        $this->wizardStep = 1;
    }

    /**
     * 発注候補生成をQueueジョブとして開始
     */
    public function startGenerationJob(): void
    {
        try {
            // 発注確定待ち（APPROVED）の候補がある場合は生成不可
            $approvedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->count();
            if ($approvedCount > 0) {
                $this->errorMessage = "発注確定待ちの候補が {$approvedCount}件 あります。先に発注確定を行ってください。";

                return;
            }

            // 進捗レコードを作成
            $queueProgress = WmsQueueProgress::createJob(
                WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                auth()->id(),
                ['deleted' => $this->results['deleted'] ?? 0]
            );

            $this->currentJobId = $queueProgress->job_id;
            $this->isProcessing = true;
            $this->progress = 0;
            $this->progressMessage = 'ジョブを開始しています...';
            $this->wizardStep = 2;

            // ジョブをディスパッチ
            ProcessOrderCandidateGenerationJob::dispatch(
                jobId: $queueProgress->job_id,
                deletePending: false,
                createdBy: auth()->id(),
            );

        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
            $this->isProcessing = false;
        }
    }

    /**
     * Queueジョブの進捗をポーリング
     */
    public function pollJobProgress(): void
    {
        if (! $this->currentJobId) {
            return;
        }

        $progress = WmsQueueProgress::findByJobId($this->currentJobId);

        if (! $progress) {
            $this->errorMessage = 'ジョブの進捗情報が見つかりません';
            $this->isProcessing = false;

            return;
        }

        $this->progress = $progress->progress;
        $this->progressMessage = $progress->message ?? '処理中...';

        if ($progress->isCompleted()) {
            // 完了
            $this->isProcessing = false;
            $this->results = array_merge($this->results, $progress->result ?? []);
            $this->wizardStep = 3;
        } elseif ($progress->isFailed()) {
            // 失敗
            $this->isProcessing = false;
            $this->errorMessage = $progress->message ?? '処理中にエラーが発生しました';
        }
        // PENDING or PROCESSING の場合は継続してポーリング
    }

    public function closeWizard(): void
    {
        $this->resetWizard();
        // ページをリフレッシュしてモーダルを閉じる
        $this->redirect(static::getResource()::getUrl('index'));
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->getSelectedWarehouseId();

        // データが存在する倉庫のみ取得
        $warehouseIds = WmsAutoOrderJobControl::whereNotNull('warehouse_id')
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        $warehouses = Warehouse::whereIn('id', $warehouseIds)
            ->orderBy('code')
            ->get(['id', 'name']);

        $hasDefaultWarehouse = $userDefaultWarehouseId && $warehouses->contains('id', $userDefaultWarehouseId);
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
