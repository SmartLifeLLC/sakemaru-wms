<?php

namespace App\Filament\Resources\WmsAutoOrderJobControls\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Filament\Resources\WmsAutoOrderJobControls\WmsAutoOrderJobControlResource;
use App\Jobs\ProcessOrderCandidateGenerationJob;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use Filament\Actions\Action;
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

    public int $approvedCount = 0;

    // Queue進捗管理
    public ?string $currentJobId = null;

    public function mount(): void
    {
        parent::mount();
        // ページ表示時にカウントを取得
        $this->pendingCount = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();
        $this->approvedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('orderGenerationWizard')
                ->label('発注候補生成')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->modalWidth('4xl')
                ->modalHeading('発注候補生成')
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
                            'approvedCount' => $this->approvedCount,
                            'currentJobId' => $this->currentJobId,
                        ]),
                ])
                ->action(fn () => null)
                ->before(function () {
                    $this->resetWizard();
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
        $this->approvedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->count();
    }

    public function executeStep1Delete(): void
    {
        $this->isProcessing = true;
        $this->progressMessage = '未承認の発注候補を削除中...';

        try {
            $deleted = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->delete();
            $this->results['deleted'] = $deleted;
            $this->pendingCount = 0;
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
