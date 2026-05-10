<?php

namespace App\Filament\Resources\WmsAutoOrderJobControls\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\QueueProgressStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsAutoOrderJobControls\WmsAutoOrderJobControlResource;
use App\Jobs\ProcessOrderCandidateGenerationJob;
use App\Jobs\ProcessSalesBasedOrderCandidateJob;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use App\Models\WmsWarehouseAutoOrderSetting;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
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

    // 表示基準日
    public string $filterDate = '';

    // 仕入先選択
    public array $selectedContractorIds = [];

    public array $contractorsData = [];

    public array $jxContractorsData = [];

    public array $salesBasedOtherContractorsData = [];

    public array $selectedJxContractorIds = [];

    // 倉庫選択（仕入先別生成用）
    public array $selectedWarehouseIds = [];

    public array $warehousesData = [];

    public function mount(): void
    {
        $this->filterDate = \App\Models\Sakemaru\ClientSetting::systemDateYMD();
        parent::mount();
        // ページ表示時にカウントを取得
        $this->pendingCount = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->forCreatedBy(auth()->id())->count();
        $this->pendingTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->forCreatedBy(auth()->id())->count();
        $this->approvedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->forCreatedBy(auth()->id())->count();
        $this->checkForStuckJob();

        // 仕入先データをページロード時に取得
        $this->contractorsData = $this->getContractorsForWarehouse();
        $this->selectedContractorIds = collect($this->contractorsData)->pluck('id')->values()->toArray();
        $this->jxContractorsData = $this->getJxContractorsForAutoOrderGeneration();
        $this->salesBasedOtherContractorsData = $this->getOtherContractorsForSalesBasedGeneration();
        $this->selectedJxContractorIds = collect($this->jxContractorsData)
            ->merge($this->salesBasedOtherContractorsData)
            ->pluck('id')
            ->values()
            ->toArray();

        // 倉庫データ（仕入先別生成用）
        $this->warehousesData = $this->getActiveWarehouses();
        $this->selectedWarehouseIds = collect($this->warehousesData)->pluck('id')->values()->toArray();
    }

    #[\Livewire\Attributes\On('filter-date-updated')]
    public function onFilterDateUpdated(string $filterDate): void
    {
        $this->filterDate = $filterDate;
        $this->resetTable();
    }

    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereDate('started_at', $this->filterDate));
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\DateFilterWidget::class,
            \App\Filament\Widgets\OrderStatusWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getSalesBasedGenerateAction(),

            ActionGroup::make([
                //                $this->getOrderGenerationWizardAction(),
                $this->getGenerateByWarehouseAction(),
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

        // HUB倉庫判定 + サテライト未発注NOTICE
        $satelliteNotice = '';
        if ($selectedWarehouseId) {
            $isHub = WmsContractorSetting::where('transmission_type', 'INTERNAL')
                ->where('supply_warehouse_id', $selectedWarehouseId)
                ->exists();

            if ($isHub) {
                $hubWarehouseIds = WmsContractorSetting::where('transmission_type', 'INTERNAL')
                    ->whereNotNull('supply_warehouse_id')
                    ->distinct()
                    ->pluck('supply_warehouse_id')
                    ->toArray();

                $satelliteWarehouseIds = WmsWarehouseAutoOrderSetting::where('is_auto_order_enabled', true)
                    ->whereNotIn('warehouse_id', $hubWarehouseIds)
                    ->pluck('warehouse_id')
                    ->toArray();

                // 当日の確定済みジョブの倉庫IDを取得
                $confirmedWarehouseIds = WmsAutoOrderJobControl::where('process_name', 'ORDER_CALC')
                    ->whereDate('started_at', today())
                    ->where('settlement_status', 'CONFIRMED')
                    ->whereNotNull('warehouse_id')
                    ->pluck('warehouse_id')
                    ->toArray();

                $unconfirmedWarehouses = Warehouse::whereIn('id', $satelliteWarehouseIds)
                    ->whereNotIn('id', $confirmedWarehouseIds)
                    ->orderBy('code')
                    ->pluck('name')
                    ->toArray();

                if (! empty($unconfirmedWarehouses)) {
                    $satelliteNotice = 'サテライト倉庫の発注が未完了です。サテライト倉庫の発注完了後にHUB倉庫の発注を行うことを推奨します。';
                }
            }
        }

        $baseDescription = $selectedWarehouse
            ? "倉庫「{$selectedWarehouseName}」の発注・移動候補を生成します。自動発注ON・発注点あり・自動発注数ありの商品が対象です。"
            : '倉庫が選択されていません。トップバーから倉庫を選択してください。';

        return Action::make('generateByWarehouse')
            ->label('発注・移動候補生成')
            ->icon('heroicon-o-building-storefront')
            ->color('warning')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalHeading("発注・移動候補生成（{$selectedWarehouseName}）")
            ->modalDescription($baseDescription)
            ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
            ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('生成開始')->color('danger'))
            ->modalCancelActionLabel('発注せず閉じる')
            ->disabled(! $selectedWarehouse)
            ->schema(array_filter([
                $satelliteNotice
                    ? \Filament\Forms\Components\Placeholder::make('satellite_notice')
                        ->hiddenLabel()
                        ->content(new \Illuminate\Support\HtmlString(
                            '<div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 dark:bg-red-950 dark:border-red-800 dark:text-red-300">'
                            .e($satelliteNotice)
                            .'</div>'
                        ))
                    : null,
                ViewField::make('contractor_selector')
                    ->view('filament.components.contractor-selection')
                    ->viewData([
                        'contractorsProperty' => 'jxContractorsData',
                        'selectedProperty' => 'selectedJxContractorIds',
                        'fallbackMethod' => 'getJxContractorsForAutoOrderGeneration',
                        'notice' => '自動発注の発注・移動候補生成は、JX-FINET手順の問屋と、そのJX問屋へ発注データを集約する問屋のみ対象です。',
                    ])
                    ->hiddenLabel(),
            ]))
            ->action(function () use ($selectedWarehouseId, $selectedWarehouseName) {
                $warehouseId = $selectedWarehouseId;
                $warehouseName = $selectedWarehouseName;
                $contractorIds = $this->selectedJxContractorIds;

                if (empty($contractorIds)) {
                    Notification::make()
                        ->title('仕入先が選択されていません')
                        ->body('最低1つの仕入先を選択してください')
                        ->danger()
                        ->send();

                    return;
                }

                // 選択仕入先を親+子に展開
                $allContractorIds = $this->expandContractorIds($contractorIds);

                // 選択仕入先のAPPROVED候補がある場合はブロック
                $hasApprovedOrders = WmsOrderCandidate::query()
                    ->where('status', CandidateStatus::APPROVED)
                    ->forCreatedBy(auth()->id())
                    ->where('warehouse_id', $warehouseId)
                    ->whereIn('contractor_id', $allContractorIds)
                    ->exists();

                $hasApprovedTransfers = WmsStockTransferCandidate::query()
                    ->where('status', CandidateStatus::APPROVED)
                    ->forCreatedBy(auth()->id())
                    ->where('satellite_warehouse_id', $warehouseId)
                    ->whereIn('contractor_id', $allContractorIds)
                    ->exists();

                if ($hasApprovedOrders || $hasApprovedTransfers) {
                    Notification::make()
                        ->title('選択した仕入先に承認済みの候補があります')
                        ->body('先に確定処理を行ってください')
                        ->danger()
                        ->send();

                    return;
                }

                // 選択仕入先のPENDING候補を削除
                $deletedOrders = WmsOrderCandidate::query()
                    ->where('status', CandidateStatus::PENDING)
                    ->forCreatedBy(auth()->id())
                    ->where('warehouse_id', $warehouseId)
                    ->whereIn('contractor_id', $allContractorIds)
                    ->delete();

                $deletedTransfers = WmsStockTransferCandidate::query()
                    ->where('status', CandidateStatus::PENDING)
                    ->forCreatedBy(auth()->id())
                    ->where('satellite_warehouse_id', $warehouseId)
                    ->whereIn('contractor_id', $allContractorIds)
                    ->delete();

                // batch_code 再利用チェック（同日同倉庫のPENDINGジョブ）
                $existingJob = WmsAutoOrderJobControl::findPendingSettlementForWarehouse($warehouseId, auth()->id());
                $batchCode = $existingJob?->batch_code;

                // 進捗レコードを作成
                $queueProgress = WmsQueueProgress::createJob(
                    WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                    auth()->id(),
                    ['warehouse_id' => $warehouseId, 'contractor_ids' => $contractorIds, 'source' => 'warehouse_contractor_specific']
                );

                // Job dispatch（warehouseId + contractorIds指定）
                ProcessOrderCandidateGenerationJob::dispatch(
                    jobId: $queueProgress->job_id,
                    deletePending: true,
                    contractorId: null,
                    executionLogId: null,
                    transferOnly: false,
                    warehouseId: $warehouseId,
                    createdBy: auth()->id(),
                    contractorIds: $contractorIds,
                    batchCode: $batchCode,
                    originType: \App\Enums\AutoOrder\OriginType::MANUAL_SAFETY_STOCK->value,
                );

                $contractorCount = count($contractorIds);
                $message = "倉庫「{$warehouseName}」の発注・移動候補生成を開始しました（仕入先{$contractorCount}件）";
                if ($deletedOrders > 0 || $deletedTransfers > 0) {
                    $message .= "（PENDING候補 発注:{$deletedOrders}件 移動:{$deletedTransfers}件 を削除）";
                }
                if ($batchCode) {
                    $message .= "（既存バッチ{$batchCode}に追加）";
                }

                Notification::make()
                    ->title($message)
                    ->success()
                    ->send();
            });
    }

    private function getSalesBasedGenerateAction(): Action
    {
        $selectedWarehouseId = auth()->user()?->getSelectedWarehouseId();
        $selectedWarehouse = $selectedWarehouseId ? Warehouse::find($selectedWarehouseId) : null;
        $selectedWarehouseName = $selectedWarehouse?->name ?? '未選択';

        $existingBatchCode = null;
        $batchNotice = '';
        if ($selectedWarehouseId) {
            $pendingJob = WmsAutoOrderJobControl::findPendingSettlementForWarehouse(
                $selectedWarehouseId,
                auth()->id(),
                [\App\Enums\AutoOrder\JobProcessName::ORDER_CALC, \App\Enums\AutoOrder\JobProcessName::SALES_BASED_CALC]
            );
            if ($pendingJob) {
                $existingBatchCode = $pendingJob->batch_code;
                $batchNotice = "既存バッチ {$existingBatchCode} に候補が追加されます。";
            }
        }

        $baseDescription = $selectedWarehouse
            ? "倉庫「{$selectedWarehouseName}」の発注候補を生成します。\n自動発注OFF・出荷実績あり・発注コードありで、見込み在庫が3日実績を下回る商品が対象です。"
            : '倉庫が選択されていません。トップバーから倉庫を選択してください。';

        return Action::make('generateSalesBased')
            ->label('実績ベース発注候補生成')
            ->icon('heroicon-o-chart-bar')
            ->color('info')
            ->modalWidth('6xl')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalHeading("実績ベース発注候補生成（{$selectedWarehouseName}）")
            ->modalDescription($baseDescription)
            ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
            ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('生成開始')->color('danger'))
            ->modalCancelActionLabel('発注せず閉じる')
            ->disabled(! $selectedWarehouse)
            ->schema(array_filter([
                $batchNotice
                    ? \Filament\Forms\Components\Placeholder::make('batch_notice')
                        ->hiddenLabel()
                        ->content(new \Illuminate\Support\HtmlString(
                            '<div class="rounded-lg bg-blue-50 border border-blue-200 px-4 py-3 text-sm text-blue-700 dark:bg-blue-950 dark:border-blue-800 dark:text-blue-300">'
                            .e($batchNotice)
                            .'</div>'
                        ))
                    : null,
                ViewField::make('contractor_selector')
                    ->view('filament.components.contractor-selection')
                    ->viewData([
                        'grouped' => true,
                        'primaryContractorsProperty' => 'jxContractorsData',
                        'primaryFallbackMethod' => 'getJxContractorsForAutoOrderGeneration',
                        'secondaryContractorsProperty' => 'salesBasedOtherContractorsData',
                        'secondaryFallbackMethod' => 'getOtherContractorsForSalesBasedGeneration',
                        'selectedProperty' => 'selectedJxContractorIds',
                        'primaryLabel' => '現在選択中の仕入先',
                        'secondaryLabel' => 'その他の仕入先',
                    ])
                    ->hiddenLabel(),
            ]))
            ->action(function () use ($selectedWarehouseId, $selectedWarehouseName, $existingBatchCode) {
                $warehouseId = $selectedWarehouseId;
                $warehouseName = $selectedWarehouseName;
                $contractorIds = $this->selectedJxContractorIds;

                if (empty($contractorIds)) {
                    Notification::make()
                        ->title('仕入先が選択されていません')
                        ->body('最低1つの仕入先を選択してください')
                        ->danger()
                        ->send();

                    return;
                }

                $queueProgress = WmsQueueProgress::createJob(
                    WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                    auth()->id(),
                    ['warehouse_id' => $warehouseId, 'contractor_ids' => $contractorIds, 'source' => 'sales_based']
                );

                ProcessSalesBasedOrderCandidateJob::dispatch(
                    jobId: $queueProgress->job_id,
                    warehouseId: $warehouseId,
                    createdBy: auth()->id(),
                    contractorIds: $contractorIds,
                    batchCode: $existingBatchCode,
                    originType: \App\Enums\AutoOrder\OriginType::MANUAL_SALES_BASED->value,
                );

                $contractorCount = count($contractorIds);
                $message = "倉庫「{$warehouseName}」の実績ベース発注候補生成を開始しました（仕入先{$contractorCount}件）";
                if ($existingBatchCode) {
                    $message .= "（既存バッチ{$existingBatchCode}に追加）";
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
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalFooterActionsAlignment(Alignment::End)
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
                ? "未承認の移動候補が {$this->pendingTransferCount}件 あります。削除せずに追加生成します。同じ不足の移動候補が重複生成される可能性があります。"
                : '全仕入先のINTERNAL移動候補を生成します。発注候補は生成しません。')
            ->action(function () {
                // APPROVED移動候補がある場合はブロック
                $approvedTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)
                    ->forCreatedBy(auth()->id())
                    ->count();
                if ($approvedTransferCount > 0) {
                    Notification::make()
                        ->title("確定待ちの移動候補が {$approvedTransferCount}件 あります")
                        ->body('先に確定処理を行ってください')
                        ->danger()
                        ->send();

                    return;
                }

                $hasPendingTransfers = $this->pendingTransferCount > 0;

                // 進捗レコードを作成
                $queueProgress = WmsQueueProgress::createJob(
                    WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                    auth()->id(),
                    [
                        'source' => 'transfer_only',
                        'preservePendingTransfers' => true,
                        'allowDuplicatePendingTransfers' => $hasPendingTransfers,
                    ]
                );

                // Job dispatch（transferOnly=true）
                ProcessOrderCandidateGenerationJob::dispatch(
                    jobId: $queueProgress->job_id,
                    deletePending: false,
                    contractorId: null,
                    executionLogId: null,
                    transferOnly: true,
                    createdBy: auth()->id(),
                    originType: \App\Enums\AutoOrder\OriginType::MANUAL_SAFETY_STOCK->value,
                );

                $message = '移動候補の生成を開始しました';
                if ($hasPendingTransfers) {
                    $message .= "（既存PENDING移動候補 {$this->pendingTransferCount}件 は削除しません）";
                }

                $notification = Notification::make()->title($message);

                if ($hasPendingTransfers) {
                    $notification
                        ->body('同じ不足に対する移動候補が重複生成される可能性があります。承認前に数量と明細を確認してください。')
                        ->warning();
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }

    private function getForceGenerateByContractorAction(): Action
    {
        return Action::make('forceGenerateByContractor')
            ->label('仕入先別発注候補生成')
            ->icon('heroicon-o-bolt')
            ->color('success')
            ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
            ->modalHeading('倉庫・仕入先別発注移動候補生成')
            ->modalDescription('指定した仕入先に対して発注・移動候補を生成します。未処理の候補がある場合は削除して再生成します。')
            ->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
            ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('生成開始')->color('danger'))
            ->modalCancelActionLabel('発注せず閉じる')
            ->schema([
                \Filament\Forms\Components\Placeholder::make('warehouse_label')
                    ->hiddenLabel()
                    ->content(new \Illuminate\Support\HtmlString(
                        '<div class="text-sm font-semibold text-gray-700 dark:text-gray-300">対象倉庫</div>'
                    )),
                ViewField::make('warehouse_selector_force')
                    ->view('filament.components.warehouse-selection')
                    ->hiddenLabel(),
                \Filament\Forms\Components\Placeholder::make('contractor_label')
                    ->hiddenLabel()
                    ->content(new \Illuminate\Support\HtmlString(
                        '<div class="text-sm font-semibold text-gray-700 dark:text-gray-300 mt-2">対象仕入先</div>'
                    )),
                ViewField::make('contractor_selector_force')
                    ->view('filament.components.contractor-selection')
                    ->viewData([
                        'contractorsProperty' => 'jxContractorsData',
                        'selectedProperty' => 'selectedJxContractorIds',
                        'fallbackMethod' => 'getJxContractorsForAutoOrderGeneration',
                        'notice' => '自動発注の発注・移動候補生成は、JX-FINET手順の問屋と、そのJX問屋へ発注データを集約する問屋のみ対象です。',
                    ])
                    ->hiddenLabel(),
            ])
            ->action(function () {
                $contractorIds = $this->selectedJxContractorIds;
                $warehouseIds = $this->selectedWarehouseIds;

                if (empty($contractorIds)) {
                    Notification::make()
                        ->title('仕入先が選択されていません')
                        ->body('最低1つの仕入先を選択してください')
                        ->danger()
                        ->send();

                    return;
                }

                if (empty($warehouseIds)) {
                    Notification::make()
                        ->title('倉庫が選択されていません')
                        ->body('最低1つの倉庫を選択してください')
                        ->danger()
                        ->send();

                    return;
                }

                // 選択仕入先を親+子に展開
                $allContractorIds = $this->expandContractorIds($contractorIds);

                // APPROVED候補がある場合はブロック
                $hasApprovedOrders = WmsOrderCandidate::query()
                    ->where('status', CandidateStatus::APPROVED)
                    ->forCreatedBy(auth()->id())
                    ->whereIn('warehouse_id', $warehouseIds)
                    ->whereIn('contractor_id', $allContractorIds)
                    ->exists();

                $hasApprovedTransfers = WmsStockTransferCandidate::query()
                    ->where('status', CandidateStatus::APPROVED)
                    ->forCreatedBy(auth()->id())
                    ->whereIn('satellite_warehouse_id', $warehouseIds)
                    ->whereIn('contractor_id', $allContractorIds)
                    ->exists();

                if ($hasApprovedOrders || $hasApprovedTransfers) {
                    Notification::make()
                        ->title('選択した倉庫・仕入先に承認済みの候補があります')
                        ->body('先に確定処理を行ってください')
                        ->danger()
                        ->send();

                    return;
                }

                // PENDING候補がある場合は削除してから再生成
                $deletedOrders = WmsOrderCandidate::query()
                    ->where('status', CandidateStatus::PENDING)
                    ->forCreatedBy(auth()->id())
                    ->whereIn('warehouse_id', $warehouseIds)
                    ->whereIn('contractor_id', $allContractorIds)
                    ->delete();

                $deletedTransfers = WmsStockTransferCandidate::query()
                    ->where('status', CandidateStatus::PENDING)
                    ->forCreatedBy(auth()->id())
                    ->whereIn('satellite_warehouse_id', $warehouseIds)
                    ->whereIn('contractor_id', $allContractorIds)
                    ->delete();

                // 倉庫ごとにJobをdispatch
                $dispatchedCount = 0;
                foreach ($warehouseIds as $warehouseId) {
                    $existingJob = WmsAutoOrderJobControl::findPendingSettlementForWarehouse($warehouseId, auth()->id());
                    $batchCode = $existingJob?->batch_code;

                    $queueProgress = WmsQueueProgress::createJob(
                        WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                        auth()->id(),
                        ['warehouse_id' => $warehouseId, 'contractor_ids' => $contractorIds, 'source' => 'force_generate']
                    );

                    ProcessOrderCandidateGenerationJob::dispatch(
                        jobId: $queueProgress->job_id,
                        deletePending: true,
                        warehouseId: $warehouseId,
                        contractorIds: $contractorIds,
                        createdBy: auth()->id(),
                        batchCode: $batchCode,
                        originType: \App\Enums\AutoOrder\OriginType::MANUAL_SAFETY_STOCK->value,
                    );

                    $dispatchedCount++;
                }

                $contractorCount = count($contractorIds);
                $message = "倉庫{$dispatchedCount}件 × 仕入先{$contractorCount}件の発注候補生成を開始しました";
                if ($deletedOrders > 0 || $deletedTransfers > 0) {
                    $message .= "（PENDING候補 発注:{$deletedOrders}件 移動:{$deletedTransfers}件 を削除）";
                }

                Notification::make()
                    ->title($message)
                    ->success()
                    ->send();
            });
    }

    /**
     * 倉庫に関連する仕入先一覧を取得（Blade UIから呼ばれる）
     * 親仕入先のみ（transmission_contractor_id IS NULL）
     */
    public function getContractorsForWarehouse(): array
    {
        // wms_contractor_settingsベース（スケジューラーと同じ基準）
        // 全倉庫横断で親仕入先を取得
        return WmsContractorSetting::query()
            ->whereNull('transmission_contractor_id')
            ->whereHas('contractor', fn ($q) => $q->where('is_auto_change_order', true))
            ->with('contractor:id,code,name')
            ->get()
            ->map(fn ($setting) => [
                'id' => $setting->contractor_id,
                'code' => (string) $setting->contractor->code,
                'name' => $setting->contractor->name,
                'transmission_type' => $setting->transmission_type?->value ?? 'UNKNOWN',
                'transmission_type_label' => $setting->transmission_type
                    ? $setting->transmission_type->label()
                    : '未設定',
                'generation_time' => $setting->auto_order_generation_time,
            ])
            ->sortBy('code')
            ->values()
            ->toArray();
    }

    /**
     * 自動発注の発注・移動候補生成対象。
     * JX-FINET手順の問屋と、そのJX問屋へ発注データを集約する問屋のみを返す。
     */
    public function getJxContractorsForAutoOrderGeneration(): array
    {
        $jxContractorIds = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::JX_FINET->value)
            ->pluck('contractor_id')
            ->toArray();

        if (empty($jxContractorIds)) {
            return [];
        }

        $aggregatedContractorIds = WmsContractorSetting::query()
            ->whereIn('transmission_contractor_id', $jxContractorIds)
            ->pluck('contractor_id')
            ->toArray();

        $targetContractorIds = array_values(array_unique(array_merge($jxContractorIds, $aggregatedContractorIds)));

        return WmsContractorSetting::query()
            ->whereIn('contractor_id', $targetContractorIds)
            ->whereHas('contractor', fn ($q) => $q->where('is_auto_change_order', true))
            ->with(['contractor:id,code,name', 'transmissionContractor:id,code,name'])
            ->get()
            ->map(fn ($setting) => [
                'id' => $setting->contractor_id,
                'code' => (string) $setting->contractor->code,
                'name' => $setting->contractor->name,
                'transmission_type' => $setting->transmission_type?->value ?? 'UNKNOWN',
                'transmission_type_label' => $setting->transmission_type
                    ? $setting->transmission_type->label()
                    : '未設定',
                'transmission_parent_code' => $setting->transmissionContractor?->code,
                'transmission_parent_name' => $setting->transmissionContractor?->name,
                'generation_time' => $setting->auto_order_generation_time,
            ])
            ->sortBy('code')
            ->values()
            ->toArray();
    }

    /**
     * 実績ベース発注で追加選択できる、JX送信対象以外の仕入先一覧。
     */
    public function getOtherContractorsForSalesBasedGeneration(): array
    {
        $jxContractorIds = collect($this->jxContractorsData ?: $this->getJxContractorsForAutoOrderGeneration())
            ->pluck('id')
            ->values()
            ->toArray();

        return WmsContractorSetting::query()
            ->when(! empty($jxContractorIds), fn ($query) => $query->whereNotIn('contractor_id', $jxContractorIds))
            ->whereHas('contractor', fn ($q) => $q->where('is_auto_change_order', true))
            ->with(['contractor:id,code,name', 'transmissionContractor:id,code,name'])
            ->get()
            ->map(fn ($setting) => [
                'id' => $setting->contractor_id,
                'code' => (string) $setting->contractor->code,
                'name' => $setting->contractor->name,
                'transmission_type' => $setting->transmission_type?->value ?? 'UNKNOWN',
                'transmission_type_label' => $setting->transmission_type
                    ? $setting->transmission_type->label()
                    : '未設定',
                'transmission_parent_code' => $setting->transmissionContractor?->code,
                'transmission_parent_name' => $setting->transmissionContractor?->name,
                'generation_time' => $setting->auto_order_generation_time,
            ])
            ->sortBy('code')
            ->values()
            ->toArray();
    }

    /**
     * アクティブな実倉庫一覧を取得（仕入先別生成の倉庫選択用）
     */
    public function getActiveWarehouses(): array
    {
        return Warehouse::where('is_active', true)
            ->where('is_virtual', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn ($w) => [
                'id' => $w->id,
                'code' => (string) $w->code,
                'name' => $w->name,
            ])
            ->toArray();
    }

    /**
     * 仕入先IDリストを親+子に展開
     */
    private function expandContractorIds(array $contractorIds): array
    {
        $expanded = [];
        foreach ($contractorIds as $id) {
            $expanded = array_merge($expanded, WmsContractorSetting::getContractorIdsWithChildren($id));
        }

        return array_values(array_unique($expanded));
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
        $this->pendingCount = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->forCreatedBy(auth()->id())->count();
        $this->pendingTransferCount = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->forCreatedBy(auth()->id())->count();
        $this->approvedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->forCreatedBy(auth()->id())->count();
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
            $deletedOrders = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->forCreatedBy(auth()->id())->delete();
            $deletedTransfers = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)->forCreatedBy(auth()->id())->delete();

            // 確定待ち（PENDING）のジョブをキャンセル
            $cancelledJobs = WmsAutoOrderJobControl::cancelPendingSettlements(createdBy: auth()->id());

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
            $approvedCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                ->forCreatedBy(auth()->id())
                ->count();
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
                originType: \App\Enums\AutoOrder\OriginType::MANUAL_SAFETY_STOCK->value,
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

        // トップメニューと同じ在庫拠点倉庫（is_active=true, is_virtual=false）
        $warehouses = Warehouse::where('is_virtual', false)
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
