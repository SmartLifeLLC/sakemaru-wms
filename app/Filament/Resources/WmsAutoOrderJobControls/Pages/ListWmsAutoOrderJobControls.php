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
use Livewire\Attributes\Computed;

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('orderGenerationWizard')
                ->label('発注生成プロセス')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->modalWidth('4xl')
                ->modalHeading('発注生成プロセス')
                ->modalSubmitAction(false)
                ->modalCancelAction(false)
                ->schema([
                    View::make('filament.components.order-generation-wizard')
                        ->viewData([
                            'step' => $this->wizardStep,
                            'isProcessing' => $this->isProcessing,
                            'progress' => $this->progress,
                            'progressMessage' => $this->progressMessage,
                            'results' => $this->results,
                            'errorMessage' => $this->errorMessage,
                            'pendingCount' => $this->getPendingCount(),
                        ]),
                ])
                ->action(fn () => null)
                ->before(function () {
                    $this->resetWizard();
                }),
        ];
    }

    #[Computed]
    public function getPendingCount(): int
    {
        return WmsOrderCandidate::where('status', CandidateStatus::PENDING)->count();
    }

    public function resetWizard(): void
    {
        $this->wizardStep = 0;
        $this->isProcessing = false;
        $this->progress = 0;
        $this->progressMessage = '';
        $this->results = [];
        $this->errorMessage = null;
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
        $this->isProcessing = true;
        $this->progress = 0;
        $this->progressMessage = 'スナップショットを生成中...';

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
        $this->isProcessing = true;
        $this->progress = 0;
        $this->progressMessage = '発注候補を生成中...';

        try {
            $service = app(OrderCandidateCalculationService::class);
            $job = $service->calculate();

            $this->results['batchCode'] = $job->batch_code;
            $this->results['calculated'] = $job->processed_records;

            // 倉庫別の件数を取得
            $this->results['byWarehouse'] = WmsOrderCandidate::where('batch_code', $job->batch_code)
                ->join('warehouses', 'wms_order_candidates.warehouse_id', '=', 'warehouses.id')
                ->selectRaw('warehouses.name as warehouse_name, COUNT(*) as count')
                ->groupBy('warehouses.id', 'warehouses.name')
                ->orderBy('warehouses.name')
                ->get()
                ->toArray();

            $this->progress = 100;
            $this->wizardStep = 3;
            $this->isProcessing = false;
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
