<?php

namespace App\Services;

use App\Models\Sakemaru\Earning;
use App\Models\Wave;
use App\Models\WaveGroup;
use App\Models\WaveSetting;
use App\Models\WmsPickingItemResult;
use App\Models\WmsQueueProgress;
use App\Models\WmsShortage;
use App\Services\PickingList\PickingListPdfService;
use App\Services\PickingList\PickingListService;
use App\Support\DbMutex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WaveGroupGenerationService
{
    private ?WaveAllocationBudget $allocationBudget = null;

    private array $allocationResults = [];

    private array $allocationWarnings = [];

    private array $pickingListErrors = [];

    /**
     * @return array{wave_ids: array<int>, earning_count: int, stock_transfer_count: int, picking_lists: array<string, array<string, mixed>>, timings_ms: array<string, int>}
     */
    /**
     * 波動生成の前段でロット調節を実行する。
     *
     * 【最重要】調節の成否・例外は波動生成に一切影響させない。
     * - LotAdjustmentRunner::runForWaveGeneration() は内部で全例外を握りつぶし、失敗を
     *   ロット調節履歴（wms_lot_adjustment_logs）に残す。
     * - 念のためここでも Throwable を握りつぶし、波動生成を必ず継続する。
     * - 調節は独立したトランザクションで完結しており、波動生成と DB トランザクションを共有しない。
     */
    private function runPreWaveLotAdjustment(int $warehouseId): void
    {
        try {
            app(\App\Services\LotAdjustment\LotAdjustmentRunner::class)
                ->runForWaveGeneration($warehouseId);
        } catch (\Throwable $e) {
            // 二重の安全網: ここで握りつぶし、波動生成は継続する
            Log::error('[LOT_ADJUSTMENT] pre-wave lot adjustment failed; wave generation continues', [
                'warehouse_id' => $warehouseId,
                'error' => $e->getMessage(),
                'error_class' => $e::class,
            ]);
        }
    }

    public function generate(WaveGroup $waveGroup, int $userId, ?WmsQueueProgress $progress = null): array
    {
        $result = DbMutex::withLockOrFail(
            'wave_group_generation:'.$waveGroup->id,
            fn () => $this->generateLocked($waveGroup, $userId, $progress),
            10,
            'sakemaru',
        );
        if ($result === null) {
            throw new \RuntimeException('この生成グループは既に処理中です。生成状況を確認してください');
        }

        return $result;
    }

    private function generateLocked(WaveGroup $waveGroup, int $userId, ?WmsQueueProgress $progress): array
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(3600);

        $waveGroup->refresh();
        $startedAt = microtime(true);
        $timings = [];
        $data = $waveGroup->conditions ?? [];
        $shippingDate = $waveGroup->shipping_date->format('Y-m-d');
        $data['shipping_date'] = $shippingDate;
        $data['shipping_dates'] = $this->normalizeShippingDates($data['shipping_dates'] ?? ($data['shipping_date'] ?? null)) ?: [$shippingDate];
        $data['warehouse_id'] = $waveGroup->warehouse_id;
        $data['generation_type'] = $waveGroup->generation_type;
        $data['target_document_types'] = $waveGroup->target_document_types ?? ['shipment', 'transfer'];

        $phaseStartedAt = microtime(true);
        $progress?->markAsProcessing(100, '波動生成を開始しています');
        if ($waveGroup->cancelled_at || $waveGroup->waves()->where('status', 'CANCELLED')->exists()) {
            throw new \RuntimeException('取消済みの生成グループは再実行できません');
        }
        $existingWaveIds = $waveGroup->waves()->orderBy('id')->pluck('id')->all();
        if ($existingWaveIds !== []) {
            // A PDF/storage failure happens after allocation has committed.
            // Retrying this group renders the same waves, never allocates again.
            $generationResult = array_merge(['earning_count' => 0, 'stock_transfer_count' => 0, 'allocation_warnings' => []], $waveGroup->generation_result ?? [], ['wave_ids' => $existingWaveIds]);
        } else {
            // Serialize only stock adjustment/allocation for this warehouse.
            // PDF rendering/storage for other groups must not wait for that lock.
            $generationResult = DbMutex::withLockOrFail('wave_generation:'.$waveGroup->warehouse_id, function () use ($waveGroup, $data, $userId) {
                $this->runPreWaveLotAdjustment((int) $waveGroup->warehouse_id);

                return $this->createWavesFromCourses($data, $waveGroup, $userId);
            }, 10, 'sakemaru');
            if ($generationResult === null) {
                throw new \RuntimeException('同じ倉庫の在庫引当が実行中です。完了後に再実行してください');
            }
            $waveGroup->update(['generation_result' => $generationResult]);
        }
        $this->allocationWarnings = $generationResult['allocation_warnings'] ?? [];
        $timings['wave_generation'] = $this->elapsedMilliseconds($phaseStartedAt);

        $waveIds = $generationResult['wave_ids'];
        $progress?->updateProgress(70, '波動生成が完了しました。ピッキングリストを保存しています');

        $phaseStartedAt = microtime(true);
        $pickingLists = empty($waveIds)
            ? []
            : $this->savePickingLists($waveGroup, $waveIds);
        $timings['picking_list_save'] = $this->elapsedMilliseconds($phaseStartedAt);
        $timings['total'] = $this->elapsedMilliseconds($startedAt);

        $result = [
            ...$generationResult,
            'stock_sync' => [
                'skipped' => true,
                'reason' => 'automatic stock lot sync is disabled for wave generation',
            ],
            'picking_lists' => $pickingLists,
            'picking_list_errors' => $this->pickingListErrors,
            'timings_ms' => $timings,
            'started_at' => now()->toDateTimeString(),
            'completed_at' => now()->toDateTimeString(),
        ];

        $waveGroup->update([
            'generation_result' => $result,
            'picking_lists' => $pickingLists,
        ]);

        Log::info('Wave group generation completed', [
            'wave_group_id' => $waveGroup->id,
            'group_no' => $waveGroup->group_no,
            'wave_count' => count($waveIds),
            'earning_count' => $generationResult['earning_count'],
            'stock_transfer_count' => $generationResult['stock_transfer_count'],
            'timings_ms' => $timings,
        ]);

        $message = '波動生成とピッキングリスト保存が完了しました';
        if ($this->allocationWarnings !== [] || $this->pickingListErrors !== []) {
            $message = '波動生成完了：再計算・欠品処理記録 '.count($this->allocationWarnings).'件、帳票出力失敗 '.count($this->pickingListErrors).'種類。生成結果を確認してください';
        }
        $progress?->markAsCompleted($result, $message);

        return $result;
    }

    /**
     * @param  array<int>  $waveIds
     * @return array<string, array<string, mixed>>
     */
    public function savePickingLists(WaveGroup $waveGroup, array $waveIds): array
    {
        $listService = new PickingListService;
        $pdfService = new PickingListPdfService($this->allocationWarnings ?: ($waveGroup->generation_result['allocation_warnings'] ?? []));
        $separateFloors = (bool) (($waveGroup->conditions['separate_floors'] ?? true));
        $shippingDate = $waveGroup->shipping_date->format('Y-m-d');
        $datePath = $waveGroup->shipping_date->format('Y/m/d');

        $renderers = $this->pickingListRenderers($listService, $pdfService, $waveIds, $separateFloors);
        $this->pickingListErrors = [];
        $saved = $waveGroup->picking_lists ?? [];
        $previousErrors = $waveGroup->generation_result['picking_list_errors'] ?? [];
        foreach ($renderers as $listType => $renderer) {
            try {
                if (isset($saved[$listType]) && ! isset($previousErrors[$listType]) && Storage::disk($saved[$listType]['disk'])->exists($saved[$listType]['path'])) {
                    continue;
                }
                $pdf = $renderer();
                $filename = "wave-group-{$waveGroup->group_no}-{$listType}-{$shippingDate}.pdf";
                $path = "wms/picking-lists/{$datePath}/wave-group-{$waveGroup->id}/{$filename}";
                if (! $this->storePickingList($path, $pdf)) {
                    throw new \RuntimeException("ピッキングリストの保存に失敗しました: {$listType}");
                }
                $saved[$listType] = [
                    'disk' => 's3', 'path' => $path, 'filename' => $filename,
                    'mime_type' => 'application/pdf', 'size' => strlen($pdf),
                    'checksum' => 'sha256:'.hash('sha256', $pdf), 'generated_at' => now()->toDateTimeString(),
                    'source' => ['wave_ids' => array_values($waveIds), 'separate_floors' => $separateFloors],
                ];
                // Save every successful format before attempting the next one.
                $waveGroup->update(['picking_lists' => $saved]);
            } catch (\Throwable $e) {
                $this->pickingListErrors[$listType] = '帳票の生成または保存に失敗しました。この生成グループを再実行してください';
                Log::error('Picking list failed; remaining formats continue', ['wave_group_id' => $waveGroup->id, 'list_type' => $listType, 'error' => $e->getMessage()]);
            }
        }

        return $saved;
    }

    protected function storePickingList(string $path, string $pdf): bool
    {
        return Storage::disk('s3')->put($path, $pdf);
    }

    protected function pickingListRenderers(PickingListService $listService, PickingListPdfService $pdfService, array $waveIds, bool $separateFloors): array
    {
        return [
            'primary' => fn (): string => $pdfService->renderBatchPrimaryPdf(
                $listService->generatePrimaryCourseListPages($waveIds, $separateFloors)
            ),
            'primary_total' => fn (): string => $pdfService->renderBatchPrimaryPdf(
                $listService->generatePrimaryTotalListPages($waveIds, $separateFloors)
            ),
            'shortage' => fn (): string => $pdfService->renderBatchShortagePdf(
                $listService->generateShortageCourseLists($waveIds)
            ),
            'secondary' => fn (): string => $pdfService->renderCourseGroupedPdf(
                $listService->generateCourseGroupedListByWaveIds($waveIds)
            ),
            'secondary_v2' => fn (): string => $pdfService->renderCourseGroupedPdf(
                $listService->generateCourseGroupedListV2ByWaveIds($waveIds)
            ),
            'tertiary' => fn (): string => $pdfService->renderBuyerGroupedPdf(
                $listService->generateBuyerGroupedListByWaveIds($waveIds)
            ),
        ];

    }

    /**
     * @return array{wave_ids: array<int>, earning_count: int, stock_transfer_count: int}
     */
    protected function createWavesFromCourses(array $data, WaveGroup $waveGroup, int $userId): array
    {
        $warehouseId = (int) $data['warehouse_id'];
        $shippingDates = $this->normalizeShippingDates($data['shipping_dates'] ?? ($data['shipping_date'] ?? null));
        $generationType = $data['generation_type'] ?? 'delivery_course';
        $deliveryCourseIds = $data['delivery_course_ids'] ?? [];
        $buyerIds = $data['buyer_ids'] ?? [];
        $includePast = (bool) ($data['include_past'] ?? false);
        $targetDocumentTypes = $this->normalizeTargetDocumentTypes($data['target_document_types'] ?? null);

        if (empty($shippingDates)) {
            return ['wave_ids' => [], 'earning_count' => 0, 'stock_transfer_count' => 0];
        }

        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

        if ($generationType === 'buyer') {
            $earningQuery = Earning::query()
                ->join('delivery_courses', 'earnings.delivery_course_id', '=', 'delivery_courses.id')
                ->whereIn('delivery_courses.warehouse_id', $warehouseIds)
                ->where('earnings.is_active', true)
                ->where('earnings.is_delivered', 0)
                ->where('earnings.picking_status', 'BEFORE')
                ->whereNotNull('earnings.delivery_course_id')
                ->whereIn('earnings.buyer_id', $buyerIds)
                ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query))
                ->select('earnings.*');

            $earnings = $this->applyDateFilter($earningQuery, 'earnings.delivered_date', $shippingDates, $includePast)
                ->get();

            $stockTransfers = collect();
        } else {
            $validCourseIds = DB::connection('sakemaru')
                ->table('delivery_courses')
                ->whereIn('warehouse_id', $warehouseIds)
                ->whereIn('id', $deliveryCourseIds)
                ->pluck('id')
                ->toArray();

            $earnings = collect();
            if ($this->includesShipmentDocuments($targetDocumentTypes)) {
                $earningQuery = Earning::query()
                    ->where('is_delivered', 0)
                    ->where('is_active', true)
                    ->where('picking_status', 'BEFORE')
                    ->whereNotNull('delivery_course_id')
                    ->whereIn('delivery_course_id', $validCourseIds)
                    ->whereExists(fn ($query) => $this->activeTradeItemsExistsQuery($query));

                $earnings = $this->applyDateFilter($earningQuery, 'delivered_date', $shippingDates, $includePast)
                    ->get();
            }

            $stockTransfers = collect();
            if ($this->includesTransferDocuments($targetDocumentTypes)) {
                $stockTransfers = $this->getEligibleStockTransfersQuery($shippingDates, $warehouseId, $includePast)
                    ->whereIn('st.delivery_course_id', $validCourseIds)
                    ->get();
            }
        }

        if ($earnings->isEmpty() && $stockTransfers->isEmpty()) {
            return ['wave_ids' => [], 'earning_count' => 0, 'stock_transfer_count' => 0];
        }

        $generationShippingDate = Carbon::parse($data['shipping_date'] ?? $this->latestShippingDate($shippingDates))->format('Y-m-d');

        $createdWaveIds = [];
        $totalEarnings = 0;
        $totalStockTransfers = 0;

        DB::connection('sakemaru')->transaction(function () use (
            $warehouseId,
            $generationShippingDate,
            $earnings,
            $stockTransfers,
            $waveGroup,
            $userId,
            &$createdWaveIds,
            &$totalEarnings,
            &$totalStockTransfers
        ) {
            $createdWaveIds = [];
            $totalEarnings = 0;
            $totalStockTransfers = 0;
            $snapshots = [];
            foreach (['EARNING' => $earnings, 'STOCK_TRANSFER' => $stockTransfers] as $type => $sources) {
                foreach ($sources as $source) {
                    $values = (array) ($source instanceof \Illuminate\Database\Eloquent\Model ? $source->getAttributes() : $source);
                    $snapshots[$type.':'.$source->id] = array_intersect_key($values, array_flip(['trade_id', 'delivery_course_id', 'delivered_date', 'picking_date', 'warehouse_id', 'from_warehouse_id', 'to_warehouse_id']));
                }
            }
            $this->allocationBudget = new WaveAllocationBudget(
                $earnings->pluck('id')->all(),
                $stockTransfers->pluck('id')->all(),
                $snapshots,
            );
            $this->allocationResults = [];
            $this->allocationWarnings = [];
            $this->allocationBudget->prepare($warehouseId, skipChangedSources: true);
            $this->allocationWarnings = $this->allocationBudget->warnings();
            $earnings = $earnings->whereIn('id', $this->allocationBudget->earningIds());
            $stockTransfers = $stockTransfers->whereIn('id', $this->allocationBudget->stockTransferIds());
            $earningsByKey = $earnings->groupBy(fn ($earning) => Carbon::parse($earning->delivered_date)->format('Y-m-d').'|'.$earning->delivery_course_id);
            $stockTransfersByKey = $stockTransfers->groupBy(fn ($transfer) => $generationShippingDate.'|'.$transfer->delivery_course_id);
            $keys = $earningsByKey->keys()->merge($stockTransfersByKey->keys())->unique()->sort()->values();
            $courses = [];
            $warehouse = DB::connection('sakemaru')
                ->table('warehouses')
                ->where('id', $warehouseId)
                ->first();

            foreach ($keys as $key) {
                [$shippingDate, $deliveryCourseId] = explode('|', (string) $key, 2);
                $deliveryCourseId = (int) $deliveryCourseId;
                $courseEarnings = $earningsByKey->get($key, collect());
                $courseStockTransfers = $stockTransfersByKey->get($key, collect());

                $waveSetting = WaveSetting::where('delivery_course_id', $deliveryCourseId)->first();
                if (! $waveSetting) {
                    $waveSetting = WaveSetting::create([
                        'delivery_course_id' => $deliveryCourseId,
                        'picking_start_time' => null,
                        'picking_deadline_time' => null,
                        'creator_id' => $userId,
                        'last_updater_id' => $userId,
                    ]);
                }

                $course = DB::connection('sakemaru')
                    ->table('delivery_courses')
                    ->where('id', $deliveryCourseId)
                    ->first();

                $wave = $this->createWaveSafely($waveSetting, $warehouse, $course, $shippingDate, $waveGroup);

                $courses[] = compact('wave', 'waveSetting', 'course', 'shippingDate', 'courseEarnings', 'courseStockTransfers');
                $createdWaveIds[] = $wave->id;
            }

            // Course numbers are only a grouping key. Allocate every selected
            // line in order of its core trade creation, across all courses and source types.
            $this->allocateOrderedLines($courses, $warehouseId);
            foreach ($courses as $courseData) {
                ['wave' => $wave, 'waveSetting' => $waveSetting, 'course' => $course, 'shippingDate' => $shippingDate, 'courseEarnings' => $courseEarnings, 'courseStockTransfers' => $courseStockTransfers] = $courseData;
                if ($courseEarnings->isNotEmpty()) {
                    $this->processEarningsForWave($wave, $waveSetting, $courseEarnings, $warehouse, $course, $shippingDate);
                    $totalEarnings += $courseEarnings->count();
                }

                if ($courseStockTransfers->isNotEmpty()) {
                    $this->processStockTransfersForWave($wave, $waveSetting, $courseStockTransfers, $warehouse, $course, $shippingDate);
                    $totalStockTransfers += $courseStockTransfers->count();
                }

            }
            $waveGroup->update(['generation_result' => ['wave_ids' => $createdWaveIds, 'earning_count' => $totalEarnings, 'stock_transfer_count' => $totalStockTransfers, 'allocation_warnings' => $this->allocationWarnings, 'allocation_recalculations' => $this->allocationBudget->recalculations()]]);
        });

        return [
            'wave_ids' => $createdWaveIds,
            'earning_count' => $totalEarnings,
            'stock_transfer_count' => $totalStockTransfers,
            'allocation_warnings' => $this->allocationWarnings,
            'allocation_recalculations' => $this->allocationBudget->recalculations(),
        ];
    }

    protected function earningAllocationService(): StockAllocationService
    {
        return new StockAllocationService($this->allocationBudget);
    }

    protected function transferAllocationService(): StockTransferLotAllocationService
    {
        return new StockTransferLotAllocationService($this->allocationBudget);
    }

    private function allocateOrderedLines(array $courses, int $warehouseId): void
    {
        $sources = [];
        foreach ($courses as $course) {
            foreach (['EARNING' => 'courseEarnings', 'STOCK_TRANSFER' => 'courseStockTransfers'] as $type => $key) {
                foreach ($course[$key] as $source) {
                    $sources[$source->trade_id] = ['type' => $type, 'source' => $source, 'wave_id' => $course['wave']->id];
                }
            }
        }
        $lines = DB::connection('sakemaru')->table('trade_items as ti')
            ->join('trades as t', 'ti.trade_id', '=', 't.id')
            ->leftJoin('items as i', 'ti.item_id', '=', 'i.id')
            ->whereIn('ti.trade_id', array_keys($sources))->where('ti.is_active', true)
            ->orderBy('t.created_at')->orderBy('t.id')->orderBy('ti.id')->select('ti.*', 'i.code as item_code', 't.serial_id as serial_id')->get();
        $earningService = $this->earningAllocationService();
        $transferService = $this->transferAllocationService();
        foreach ($lines as $line) {
            $scope = $sources[$line->trade_id];
            $source = $scope['source'];
            $key = $scope['type'].':'.$source->id.':'.$line->id;
            if ((int) $line->quantity <= 0) {
                // Returns and zero-quantity lines do not create an outbound claim.
                $this->allocationResults[$key] = ['allocated' => 0, 'shortage' => 0, 'ordered_qty' => 0, 'qty_type' => in_array($line->quantity_type, ['CASE', 'CARTON', 'PIECE'], true) ? $line->quantity_type : 'PIECE'];

                continue;
            }
            $result = $this->allocateLineWithOneRecalculation($scope, $source, $warehouseId, $line, $earningService, $transferService);
            $this->allocationResults[$key] = $result;
        }
    }

    private function allocateLineWithOneRecalculation(array $scope, object $source, int $warehouseId, object $line, StockAllocationService $earningService, StockTransferLotAllocationService $transferService): array
    {
        $recalculated = false;
        // One initial attempt plus, only after this item's one reload, one retry.
        // No recursive generation, queue redispatch, or whole-wave recalculation.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $checkpoint = $this->allocationBudget->checkpoint();
            try {
                if ($line->item_code === null || ! in_array($line->quantity_type, ['CASE', 'CARTON', 'PIECE'], true)) {
                    throw new \RuntimeException('注文の商品・数量単位が不正です');
                }
                $result = DB::connection('sakemaru')->transaction(function () use ($scope, $source, $warehouseId, $line, $earningService, $transferService) {
                    $result = $scope['type'] === 'EARNING'
                        ? $earningService->allocateForItem($scope['wave_id'], $warehouseId, $line->item_id, $line->quantity, $line->quantity_type, $source->id, $line->id, 'EARNING', $source->buyer_id)
                        : $transferService->allocateForTradeItem($scope['wave_id'], $warehouseId, $source->id, $line);
                    if (($result['lock_failed'] ?? false) || ($result['allocation_issue'] ?? false)) {
                        throw new \RuntimeException($result['allocation_issue'] ?? '在庫引当ロックを取得できませんでした');
                    }

                    $allocated = (int) ($result['allocated'] ?? -1);
                    $shortage = (int) ($result['shortage'] ?? -1);
                    $ordered = (int) ($result['ordered_qty'] ?? $line->quantity);
                    $type = $result['qty_type'] ?? $line->quantity_type;
                    if ($allocated < 0 || $shortage < 0 || $ordered <= 0 || $allocated + $shortage !== $ordered
                        || ! in_array($type, ['CASE', 'CARTON', 'PIECE'], true)) {
                        throw new \RuntimeException('引当数量と欠品数量の合計が注文数量に一致しません');
                    }

                    return $result;
                });
                if ($recalculated) {
                    $this->recordAllocationProcessing($scope, $source, $line, '該当商品の再計算1回後、引当・欠品数量を確定しました');
                }

                return $result;
            } catch (\Throwable $e) {
                $this->allocationBudget->restore($checkpoint);
                $this->throwIfTransactionCannotContinue($e);
                Log::warning('Wave line allocation failed; limited item recalculation or shortage follows', ['trade_item_id' => $line->id, 'attempt' => $attempt + 1, 'error' => $e->getMessage()]);
                if ($attempt === 0) {
                    try {
                        $recalculated = $this->allocationBudget->recalculateItem($warehouseId, (int) $line->item_id);
                        if ($recalculated) {
                            continue;
                        }
                    } catch (\Throwable $reloadError) {
                        $this->throwIfTransactionCannotContinue($reloadError);
                        Log::error('Wave item recalculation failed; line becomes shortage', ['item_id' => $line->item_id, 'error' => $reloadError->getMessage()]);
                    }
                }
                break;
            }
        }

        // A recoverable line error has a terminal allocation outcome: full
        // shortage. It consumes no stock and never holds later orders.
        $type = in_array($line->quantity_type, ['CASE', 'CARTON', 'PIECE'], true) ? $line->quantity_type : 'PIECE';
        $quantity = max(0, (int) $line->quantity);
        DB::connection('sakemaru')->table('wms_reservations')->insert([
            'warehouse_id' => $warehouseId, 'location_id' => null, 'real_stock_id' => null,
            'item_id' => $line->item_id, 'expiry_date' => null, 'received_at' => null,
            'purchase_id' => null, 'unit_cost' => null, 'qty_each' => 0, 'qty_type' => $type,
            'shortage_qty' => $quantity, 'source_type' => $scope['type'], 'source_id' => $source->id,
            'source_line_id' => $line->id, 'wave_id' => $scope['wave_id'], 'status' => 'SHORTAGE',
            'created_by' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $note = '該当商品の再計算は最大1回。引当不能のため全数を欠品処理しました';
        $this->recordAllocationProcessing($scope, $source, $line, $note);

        return ['allocated' => 0, 'shortage' => $quantity, 'qty_type' => $type, 'ordered_qty' => $quantity, 'shortage_note' => $note];
    }

    private function throwIfTransactionCannotContinue(\Throwable $e): void
    {
        if (! DB::connection('sakemaru')->getPdo()->inTransaction()
            || ($e instanceof \Illuminate\Database\QueryException && in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1305, 2006, 2013], true))) {
            throw $e; // A lost database transaction cannot safely persist any outcome.
        }
    }

    private function recordAllocationProcessing(array $scope, object $source, object $line, string $message): void
    {
        $this->allocationWarnings[] = ['source_type' => $scope['type'], 'source_id' => (int) $source->id, 'trade_item_id' => (int) $line->id, 'item_id' => (int) $line->item_id, 'item_code' => $line->item_code, 'serial_id' => $line->serial_id, 'message' => $message];
    }

    private function reservationSegments(int $waveId, string $sourceType, int $sourceId, object $line, array $result): array
    {
        $rows = DB::connection('sakemaru')->table('wms_reservations')
            ->where('wave_id', $waveId)->where('source_type', $sourceType)
            ->where('source_id', $sourceId)->where('source_line_id', $line->id)
            ->where('qty_each', '>', 0)->orderBy('id')->get();
        $segments = [];
        foreach ($rows->groupBy(fn ($row) => $row->real_stock_id.':'.($row->location_id ?? 'null')) as $group) {
            $row = $group->first();
            $segments[] = ['allocated_qty' => (int) $group->sum('qty_each'), 'real_stock_id' => $row->real_stock_id, 'location_id' => $row->location_id];
        }
        $segments = $segments ?: [['allocated_qty' => $result['allocated'], 'real_stock_id' => null, 'location_id' => null]];
        foreach ($segments as $index => &$segment) {
            // Put the unallocated remainder on one segment, exactly once.
            // Both order and shortage totals still equal the original line.
            $segment['shortage_qty'] = $index === 0 ? ($result['shortage'] ?? 0) : 0;
            $segment['ordered_qty'] = $segment['allocated_qty'] + $segment['shortage_qty'];
            $segment['qty_type'] = $result['qty_type'] ?? $line->quantity_type ?? 'PIECE';
            $segment['walking_order'] = null;
            $segment['shortage_note'] = $result['shortage_note'] ?? null;
        }
        unset($segment);

        return $segments;
    }

    private function recordAllocationShortage(int $pickResultId, Wave $wave, WaveSetting $setting, object $line, array $result, string $type, int $orderQty, ?int $earningId = null): void
    {
        if (! ($result['shortage_note'] ?? null)) {
            return;
        }
        // The reservation/PIR allocation outcome is already SHORTAGE. BEFORE
        // remains the existing shortage confirmation workflow, not an allocation hold.
        WmsShortage::create([
            'wave_id' => $wave->id, 'warehouse_id' => $setting->warehouse_id,
            'shipment_date' => $wave->shipping_date, 'delivery_course_id' => $setting->delivery_course_id,
            'item_id' => $line->item_id, 'trade_id' => $line->trade_id, 'trade_item_id' => $line->id,
            'earning_id' => $earningId, 'source_pick_result_id' => $pickResultId,
            'order_qty' => $orderQty, 'planned_qty' => 0, 'picked_qty' => 0,
            'shortage_qty' => $orderQty, 'allocation_shortage_qty' => $orderQty, 'picking_shortage_qty' => 0,
            'qty_type_at_order' => $type, 'case_size_snap' => max(1, (int) $line->capacity_case),
            'status' => WmsShortage::STATUS_BEFORE, 'reason_code' => WmsShortage::REASON_OTHER,
            'note' => $result['shortage_note'], 'is_confirmed' => false, 'is_synced' => false,
        ]);
    }

    protected function createWaveSafely(WaveSetting $waveSetting, $warehouse, $course, string $shippingDate, WaveGroup $waveGroup): Wave
    {
        $wave = Wave::create([
            'wave_group_id' => $waveGroup->id,
            'wms_wave_setting_id' => $waveSetting->id,
            'wave_no' => uniqid('TEMP_'),
            'shipping_date' => $shippingDate,
            'status' => 'PENDING',
        ]);

        $wave->update([
            'wave_no' => Wave::generateWaveNo(
                $warehouse->code ?? 0,
                $course->code ?? 0,
                $shippingDate,
                $wave->id
            ),
        ]);

        return $wave;
    }

    protected function processEarningsForWave(Wave $wave, WaveSetting $waveSetting, $earnings, $warehouse, $course, string $shippingDate): void
    {
        $earningIds = $earnings->pluck('id')->toArray();
        $tradeIds = $earnings->pluck('trade_id')->toArray();

        DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('wave_id', $wave->id)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('wms_picking_item_results')
                    ->whereColumn('wms_picking_item_results.picking_task_id', 'wms_picking_tasks.id');
            })
            ->delete();

        if ($this->allocationResults === []) {
            DB::connection('sakemaru')
                ->table('wms_reservations')
                ->where('wave_id', $wave->id)
                ->whereIn('source_id', $earningIds)
                ->where('source_type', 'EARNING')
                ->delete();
        }

        $tradeItems = DB::connection('sakemaru')
            ->table('trade_items')
            ->join('trades as ti_trade', 'trade_items.trade_id', '=', 'ti_trade.id')
            ->whereIn('trade_items.trade_id', $tradeIds)
            ->where('trade_items.is_active', true)
            ->orderBy('ti_trade.serial_id')
            ->orderBy('trade_items.id')
            ->select('trade_items.*')
            ->get();

        $tradeIdToEarningId = $earnings->pluck('id', 'trade_id')->toArray();
        $tradeIdToBuyerId = $earnings->pluck('buyer_id', 'trade_id')->toArray();
        $allocationService = new StockAllocationService($this->allocationBudget);
        $locationCache = [];
        $itemLocationCache = [];
        $defaultAreaCache = [];

        $itemsByGroup = [];
        $reservationResults = [];

        foreach ($tradeItems as $tradeItem) {
            $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;
            $buyerId = $tradeIdToBuyerId[$tradeItem->trade_id] ?? null;
            if (! $earningId) {
                continue;
            }

            $result = $this->allocationResults['EARNING:'.$earningId.':'.$tradeItem->id] ?? $allocationService->allocateForItem(
                $wave->id, $waveSetting->warehouse_id, $tradeItem->item_id,
                $tradeItem->quantity, $tradeItem->quantity_type ?? 'PIECE',
                $earningId, $tradeItem->id, 'EARNING', $buyerId
            );
            if ($result['lock_failed'] ?? false) {
                throw new \RuntimeException('在庫引当のロックを取得できませんでした。波動生成を再実行してください');
            }

            $segments = $this->reservationSegments($wave->id, 'EARNING', $earningId, $tradeItem, $result);
            foreach ($segments as $segmentIndex => $reservationResult) {
                $tradeItem = clone $tradeItem;
                $tradeItem->allocation_row_key = $tradeItem->id.':'.$segmentIndex;
                $reservationResults[$tradeItem->allocation_row_key] = $reservationResult;

                $pickingAreaId = null;
                $floorId = null;
                $temperatureType = null;
                $isRestrictedArea = false;

                if ($reservationResult['location_id']) {
                    $location = $locationCache[$reservationResult['location_id']] ??= DB::connection('sakemaru')
                        ->table('locations')
                        ->where('id', $reservationResult['location_id'])
                        ->first();
                    $floorId = $location->floor_id ?? null;
                    $temperatureType = $location->temperature_type ?? null;
                    $isRestrictedArea = $location->is_restricted_area ?? false;
                    $pickingAreaId = $location->wms_picking_area_id ?? null;
                }

                if ($pickingAreaId === null || $floorId === null) {
                    $itemLocation = $itemLocationCache[$tradeItem->item_id] ??= DB::connection('sakemaru')
                        ->table('real_stocks as rs')
                        ->join('real_stock_lots as rsl', 'rs.id', '=', 'rsl.real_stock_id')
                        ->join('locations as l', 'rsl.location_id', '=', 'l.id')
                        ->where('rs.warehouse_id', $waveSetting->warehouse_id)
                        ->where('rs.item_id', $tradeItem->item_id)
                        ->whereNotNull('l.wms_picking_area_id')
                        ->select('l.id as location_id', 'rs.id as real_stock_id', 'l.wms_picking_area_id', 'l.floor_id', 'l.temperature_type', 'l.is_restricted_area')
                        ->first();

                    if ($itemLocation) {
                        if ($reservationResult['location_id'] === null) {
                            $reservationResult['location_id'] = $itemLocation->location_id;
                            $reservationResult['real_stock_id'] = $itemLocation->real_stock_id;
                            $reservationResults[$tradeItem->allocation_row_key ?? $tradeItem->id] = $reservationResult;
                        }
                        $pickingAreaId = $pickingAreaId ?? $itemLocation->wms_picking_area_id;
                        $floorId = $floorId ?? $itemLocation->floor_id;
                        $temperatureType = $temperatureType ?? $itemLocation->temperature_type;
                        $isRestrictedArea = $isRestrictedArea ?? $itemLocation->is_restricted_area;
                    } else {
                        $defaultArea = $defaultAreaCache[$waveSetting->warehouse_id] ??= DB::connection('sakemaru')
                            ->table('wms_picking_areas')
                            ->where('warehouse_id', $waveSetting->warehouse_id)
                            ->where('is_active', true)
                            ->orderBy('display_order', 'asc')
                            ->first();
                        $pickingAreaId = $pickingAreaId ?? ($defaultArea->id ?? null);
                    }
                }

                $groupKey = ($floorId ?? 'null');
                if (! isset($itemsByGroup[$groupKey])) {
                    $itemsByGroup[$groupKey] = [
                        'floor_id' => $floorId,
                        'picking_area_id' => $pickingAreaId,
                        'temperature_type' => $temperatureType,
                        'is_restricted_area' => $isRestrictedArea,
                        'items' => [],
                    ];
                }
                $itemsByGroup[$groupKey]['items'][] = $tradeItem;
            }
        }

        foreach ($itemsByGroup as $groupData) {
            $validItems = [];
            $hasRestrictedItem = false;
            foreach ($groupData['items'] as $tradeItem) {
                $reservationResult = $reservationResults[$tradeItem->allocation_row_key ?? $tradeItem->id] ?? null;
                if (! $reservationResult) {
                    continue;
                }
                $validItems[] = $tradeItem;
                if ($reservationResult['location_id']) {
                    $location = $locationCache[$reservationResult['location_id']] ??= DB::connection('sakemaru')
                        ->table('locations')
                        ->where('id', $reservationResult['location_id'])
                        ->first();
                    $hasRestrictedItem = $hasRestrictedItem || (bool) ($location->is_restricted_area ?? false);
                }
            }

            if (empty($validItems)) {
                continue;
            }

            $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                'wave_id' => $wave->id,
                'wms_picking_area_id' => $groupData['picking_area_id'],
                'warehouse_id' => $waveSetting->warehouse_id,
                'warehouse_code' => $warehouse->code,
                'floor_id' => $groupData['floor_id'],
                'temperature_type' => $groupData['temperature_type'],
                'is_restricted_area' => $hasRestrictedItem,
                'delivery_course_id' => $waveSetting->delivery_course_id,
                'delivery_course_code' => $course->code,
                'shipment_date' => $shippingDate,
                'status' => 'PENDING',
                'task_type' => 'WAVE',
                'picker_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($validItems as $tradeItem) {
                $reservationResult = $reservationResults[$tradeItem->allocation_row_key ?? $tradeItem->id];
                $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;

                $quantityType = $reservationResult['qty_type'];
                $pickResultId = DB::connection('sakemaru')->table('wms_picking_item_results')->insertGetId([
                    'picking_task_id' => $pickingTaskId,
                    'earning_id' => $earningId,
                    'source_type' => WmsPickingItemResult::SOURCE_TYPE_EARNING,
                    'stock_transfer_id' => null,
                    'trade_id' => $tradeItem->trade_id,
                    'trade_item_id' => $tradeItem->id,
                    'item_id' => $tradeItem->item_id,
                    'real_stock_id' => $reservationResult['real_stock_id'],
                    'location_id' => $reservationResult['location_id'],
                    'walking_order' => $reservationResult['walking_order'],
                    'ordered_qty' => $reservationResult['ordered_qty'],
                    'ordered_qty_type' => $quantityType,
                    'planned_qty' => $reservationResult['allocated_qty'],
                    'planned_qty_type' => $quantityType,
                    'picked_qty' => 0,
                    'picked_qty_type' => $quantityType,
                    'shortage_qty' => $reservationResult['shortage_qty'] ?? 0,
                    'status' => 'PENDING',
                    'picker_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->recordAllocationShortage($pickResultId, $wave, $waveSetting, $tradeItem, $reservationResult, $quantityType, (int) $reservationResult['ordered_qty'], $earningId);
            }
        }

        DB::connection('sakemaru')
            ->table('earnings')
            ->whereIn('id', $earningIds)
            ->update([
                'picking_status' => 'BEFORE_PICKING',
                'updated_at' => now(),
            ]);
    }

    protected function processStockTransfersForWave(Wave $wave, WaveSetting $waveSetting, $stockTransfers, $warehouse, $course, string $shippingDate): void
    {
        $stockTransferIds = $stockTransfers->pluck('id')->toArray();
        $tradeIds = $stockTransfers->pluck('trade_id')->toArray();

        DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('wave_id', $wave->id)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('wms_picking_item_results')
                    ->whereColumn('wms_picking_item_results.picking_task_id', 'wms_picking_tasks.id');
            })
            ->delete();

        if ($this->allocationResults === []) {
            DB::connection('sakemaru')
                ->table('wms_reservations')
                ->where('wave_id', $wave->id)
                ->whereIn('source_id', $stockTransferIds)
                ->where('source_type', 'STOCK_TRANSFER')
                ->delete();
        }

        $tradeIdToStockTransferId = $stockTransfers->pluck('id', 'trade_id')->toArray();
        $tradeItems = DB::connection('sakemaru')
            ->table('trade_items')
            ->join('trades as ti_trade', 'trade_items.trade_id', '=', 'ti_trade.id')
            ->whereIn('trade_items.trade_id', $tradeIds)
            ->where('trade_items.is_active', true)
            ->orderBy('ti_trade.serial_id')
            ->orderBy('trade_items.id')
            ->select('trade_items.*')
            ->get();

        $allocationService = new StockTransferLotAllocationService($this->allocationBudget);
        $locationCache = [];
        $itemLocationCache = [];
        $defaultAreaCache = [];

        foreach ($tradeItems as $tradeItem) {
            $stockTransferId = $tradeIdToStockTransferId[$tradeItem->trade_id] ?? null;
            if (! $stockTransferId) {
                continue;
            }

            $result = $this->allocationResults['STOCK_TRANSFER:'.$stockTransferId.':'.$tradeItem->id] ?? $allocationService->allocateForTradeItem(
                $wave->id, $waveSetting->warehouse_id, $stockTransferId, $tradeItem
            );
            $wmsQuantityType = $result['qty_type'] ?? $tradeItem->quantity_type;
            $wmsOrderQty = $result['ordered_qty'] ?? $tradeItem->quantity;

            $segments = $this->reservationSegments($wave->id, 'STOCK_TRANSFER', $stockTransferId, $tradeItem, $result);
            foreach ($segments as $segmentIndex => $reservationResult) {
                $tradeItem = clone $tradeItem;
                $tradeItem->allocation_row_key = $tradeItem->id.':'.$segmentIndex;
                $wmsOrderQty = $reservationResult['ordered_qty'];

                $pickingAreaId = null;
                $floorId = null;

                if ($reservationResult['location_id']) {
                    $location = $locationCache[$reservationResult['location_id']] ??= DB::connection('sakemaru')
                        ->table('locations')
                        ->where('id', $reservationResult['location_id'])
                        ->first();
                    $floorId = $location->floor_id ?? null;
                    $pickingAreaId = $location->wms_picking_area_id ?? null;
                }

                if ($pickingAreaId === null || $floorId === null) {
                    $itemLocation = $itemLocationCache[$tradeItem->item_id] ??= DB::connection('sakemaru')
                        ->table('real_stocks as rs')
                        ->join('real_stock_lots as rsl', 'rs.id', '=', 'rsl.real_stock_id')
                        ->join('locations as l', 'rsl.location_id', '=', 'l.id')
                        ->where('rs.warehouse_id', $waveSetting->warehouse_id)
                        ->where('rs.item_id', $tradeItem->item_id)
                        ->whereNotNull('l.wms_picking_area_id')
                        ->select('l.id as location_id', 'rs.id as real_stock_id', 'l.wms_picking_area_id', 'l.floor_id')
                        ->first();

                    if ($itemLocation) {
                        if ($reservationResult['location_id'] === null) {
                            $reservationResult['location_id'] = $itemLocation->location_id;
                            $reservationResult['real_stock_id'] = $itemLocation->real_stock_id;
                        }
                        $pickingAreaId = $pickingAreaId ?? $itemLocation->wms_picking_area_id;
                        $floorId = $floorId ?? $itemLocation->floor_id;
                    } else {
                        $defaultArea = $defaultAreaCache[$waveSetting->warehouse_id] ??= DB::connection('sakemaru')
                            ->table('wms_picking_areas')
                            ->where('warehouse_id', $waveSetting->warehouse_id)
                            ->where('is_active', true)
                            ->orderBy('display_order', 'asc')
                            ->first();
                        $pickingAreaId = $pickingAreaId ?? ($defaultArea->id ?? null);
                    }
                }

                $existingTask = DB::connection('sakemaru')
                    ->table('wms_picking_tasks')
                    ->where('wave_id', $wave->id)
                    ->where('floor_id', $floorId)
                    ->first();

                if ($existingTask) {
                    $pickingTaskId = $existingTask->id;
                } else {
                    $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                        'wave_id' => $wave->id,
                        'wms_picking_area_id' => $pickingAreaId,
                        'warehouse_id' => $waveSetting->warehouse_id,
                        'warehouse_code' => $warehouse->code,
                        'floor_id' => $floorId,
                        'delivery_course_id' => $waveSetting->delivery_course_id,
                        'delivery_course_code' => $course->code,
                        'shipment_date' => $shippingDate,
                        'status' => 'PENDING',
                        'task_type' => 'WAVE',
                        'picker_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $wmsQuantityType = $wmsQuantityType ?: 'PIECE';
                $pickResultId = DB::connection('sakemaru')->table('wms_picking_item_results')->insertGetId([
                    'picking_task_id' => $pickingTaskId,
                    'earning_id' => null,
                    'source_type' => WmsPickingItemResult::SOURCE_TYPE_STOCK_TRANSFER,
                    'stock_transfer_id' => $stockTransferId,
                    'trade_id' => $tradeItem->trade_id,
                    'trade_item_id' => $tradeItem->id,
                    'item_id' => $tradeItem->item_id,
                    'real_stock_id' => $reservationResult['real_stock_id'],
                    'location_id' => $reservationResult['location_id'],
                    'walking_order' => $reservationResult['walking_order'],
                    'ordered_qty' => $wmsOrderQty,
                    'ordered_qty_type' => $wmsQuantityType,
                    'planned_qty' => $reservationResult['allocated_qty'],
                    'planned_qty_type' => $wmsQuantityType,
                    'picked_qty' => 0,
                    'picked_qty_type' => $wmsQuantityType,
                    'shortage_qty' => $reservationResult['shortage_qty'] ?? 0,
                    'status' => 'PENDING',
                    'picker_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->recordAllocationShortage($pickResultId, $wave, $waveSetting, $tradeItem, $reservationResult, $wmsQuantityType, (int) $wmsOrderQty);
            }
        }

        DB::connection('sakemaru')
            ->table('stock_transfers')
            ->whereIn('id', $stockTransferIds)
            ->update([
                'picking_status' => 'BEFORE_PICKING',
                'updated_at' => now(),
            ]);
    }

    protected function activeTradeItemsExistsQuery($query)
    {
        return $query
            ->select(DB::raw(1))
            ->from('trade_items as active_trade_items')
            ->join('trades as active_trades', 'active_trade_items.trade_id', '=', 'active_trades.id')
            ->whereColumn('active_trade_items.trade_id', 'earnings.trade_id')
            ->where('active_trade_items.is_active', true)
            ->where('active_trades.is_active', true);
    }

    protected function getEligibleStockTransfersQuery(string|array $shippingDate, int $warehouseId, bool $includePast = false)
    {
        $shippingDates = $this->normalizeShippingDates($shippingDate);
        $warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

        $query = DB::connection('sakemaru')
            ->table('stock_transfers as st')
            ->join('trades as st_trade', 'st.trade_id', '=', 'st_trade.id')
            ->join('delivery_courses as dc', 'st.delivery_course_id', '=', 'dc.id')
            ->join('warehouses as fw', 'st.from_warehouse_id', '=', 'fw.id')
            ->join('warehouses as tw', 'st.to_warehouse_id', '=', 'tw.id')
            ->where('st.is_active', true)
            ->where('st_trade.is_active', true)
            ->where('st.picking_status', 'BEFORE')
            ->where('st.is_delivered', false)
            ->where('st.is_confirmed', false)
            ->whereIn('st.from_warehouse_id', $warehouseIds)
            ->whereIn('dc.warehouse_id', $warehouseIds)
            ->whereNotNull('st.delivery_course_id')
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('fw.is_virtual', false)
                        ->orWhere('tw.is_virtual', false);
                })
                    ->where(function ($q) {
                        $q->whereRaw('COALESCE(fw.stock_warehouse_id, fw.id) != COALESCE(tw.stock_warehouse_id, tw.id)');
                    });
            })
            ->select('st.*');

        return $this->applyRawDateFilter($query, $this->stockTransferPickingDateExpression(), $shippingDates, $includePast);
    }

    private function normalizeTargetDocumentTypes(mixed $targetDocumentTypes): array
    {
        if (! is_array($targetDocumentTypes)) {
            return ['shipment', 'transfer'];
        }

        return array_values(array_intersect($targetDocumentTypes, ['shipment', 'transfer']));
    }

    private function includesShipmentDocuments(array $targetDocumentTypes): bool
    {
        return in_array('shipment', $targetDocumentTypes, true);
    }

    private function includesTransferDocuments(array $targetDocumentTypes): bool
    {
        return in_array('transfer', $targetDocumentTypes, true);
    }

    private function normalizeShippingDates(mixed $shippingDates): array
    {
        if (! is_array($shippingDates)) {
            $shippingDates = $shippingDates ? [$shippingDates] : [];
        }

        return collect($shippingDates)
            ->filter(fn ($date): bool => is_string($date) && $date !== '')
            ->map(function (string $date): ?string {
                try {
                    return Carbon::parse($date)->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function latestShippingDate(array $shippingDates): ?string
    {
        return empty($shippingDates) ? null : max($shippingDates);
    }

    private function applyDateFilter($query, string $column, array $shippingDates, bool $includePast)
    {
        if (empty($shippingDates)) {
            return $query->whereRaw('1 = 0');
        }

        if ($includePast) {
            return $query->where($column, '<=', $this->latestShippingDate($shippingDates));
        }

        return count($shippingDates) === 1
            ? $query->where($column, $shippingDates[0])
            : $query->whereIn($column, $shippingDates);
    }

    private function applyRawDateFilter($query, string $expression, array $shippingDates, bool $includePast)
    {
        if (empty($shippingDates)) {
            return $query->whereRaw('1 = 0');
        }

        if ($includePast) {
            return $query->whereRaw("{$expression} <= ?", [$this->latestShippingDate($shippingDates)]);
        }

        return $query->where(function ($query) use ($expression, $shippingDates) {
            foreach ($shippingDates as $shippingDate) {
                $query->orWhereRaw("{$expression} = ?", [$shippingDate]);
            }
        });
    }

    private function stockTransferPickingDateExpression(): string
    {
        return 'COALESCE(st.picking_date, st.delivered_date)';
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
