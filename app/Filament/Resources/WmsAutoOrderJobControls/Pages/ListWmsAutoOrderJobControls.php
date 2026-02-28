<?php

namespace App\Filament\Resources\WmsAutoOrderJobControls\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Filament\Resources\WmsAutoOrderJobControls\WmsAutoOrderJobControlResource;
use App\Jobs\ProcessOrderCandidateGenerationJob;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsAutoOrderExecutionLog;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View;

class ListWmsAutoOrderJobControls extends ListRecords
{
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

    public function mount(): void
    {
        parent::mount();
        // ページ表示時にカウントを取得
        $this->pendingCount = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();
        $this->pendingTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();
        $this->approvedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('orderGenerationWizard')
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
                        ]),
                ])
                ->action(fn () => null)
                ->before(function () {
                    $this->resetWizard();
                }),

            Action::make('generateTransferCandidates')
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
                    );

                    $message = '移動候補の生成を開始しました';
                    if ($deletedTransfers > 0) {
                        $message .= "（PENDING移動候補 {$deletedTransfers}件 を削除）";
                    }

                    Notification::make()
                        ->title($message)
                        ->success()
                        ->send();
                }),

            Action::make('forceGenerateByContractor')
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
                }),
        ];
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
        // ウィザード開始時のみカウントを取得（毎回のレンダリングで実行しない）
        $this->pendingCount = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();
        $this->pendingTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->count();
        $this->approvedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->count();
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
                $queueProgress->job_id,
                false // 削除は既にステップ0で行われている
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
}
