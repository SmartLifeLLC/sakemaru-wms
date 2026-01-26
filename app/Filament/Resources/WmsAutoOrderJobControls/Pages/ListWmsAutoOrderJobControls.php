<?php

namespace App\Filament\Resources\WmsAutoOrderJobControls\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Filament\Resources\WmsAutoOrderJobControls\WmsAutoOrderJobControlResource;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderCandidateCalculationService;
use App\Services\AutoOrder\StockSnapshotService;
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

    public function mount(): void
    {
        parent::mount();
        // ページ表示時にカウントを取得
        $this->pendingCount = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();
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
        // ウィザード開始時のみカウントを取得（毎回のレンダリングで実行しない）
        $this->pendingCount = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();
    }

    public function executeStep1Delete(): void
    {
        $this->isProcessing = true;
        $this->progressMessage = '未承認の発注候補を削除中...';

        try {
            $deleted = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->delete();
            $this->results['deleted'] = $deleted;
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

    public function executeStep2Snapshot(): void
    {
        // プログレスバーを表示（実際の処理はAlpine x-initで発火）
        $this->isProcessing = true;
        $this->progress = 0;
        $this->progressMessage = 'スナップショットを生成中...';
    }

    public function runSnapshot(): void
    {
        try {
            $service = app(StockSnapshotService::class);
            $job = $service->generateAll();

            $this->results['snapshot'] = $job->processed_records;
            $this->progress = 100;
            $this->wizardStep = 2;
            $this->isProcessing = false;
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
            $this->isProcessing = false;
        }
    }

    public function executeStep3Calculate(): void
    {
        // プログレスバーを表示（実際の処理はAlpine x-initで発火）
        $this->isProcessing = true;
        $this->progress = 0;
        $this->progressMessage = '発注候補を生成中...';
    }

    public function runCalculation(): void
    {
        try {
            $service = app(OrderCandidateCalculationService::class);
            $job = $service->calculate();

            // 計算完了後、結果をセッションに保存してリダイレクト
            session()->flash('order_generation_result', [
                'batchCode' => $job->batch_code,
                'calculated' => $job->processed_records,
            ]);

            // 発注候補一覧へ直接リダイレクト
            $this->redirect(
                route('filament.admin.resources.wms-order-candidates.index'),
                navigate: true
            );
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
            $this->isProcessing = false;
        }
    }

    public function closeWizard(): void
    {
        $this->resetWizard();
        // ページをリフレッシュしてモーダルを閉じる
        $this->redirect(static::getResource()::getUrl('index'));
    }
}
