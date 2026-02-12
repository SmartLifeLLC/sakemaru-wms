<?php

namespace App\Services\AutoOrder;

use App\Contracts\OrderFileGeneratorInterface;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\AutoOrder\TransmissionDocumentType;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Models\WmsOrderJxDocument;
use App\Models\WmsOrderJxSetting;
use App\Models\WmsOrderTransmissionLog;
use App\Services\AutoOrder\Generators\HanaOrderFileGenerator;
use App\Services\JX\JxClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 発注送信サービス
 */
class OrderTransmissionService
{
    public function __construct(
        private readonly OrderValidationService $validationService
    ) {}

    /**
     * 確定済み発注候補を送信
     *
     * @throws \RuntimeException バリデーションエラー時
     */
    public function transmitConfirmedOrders(string $batchCode): WmsAutoOrderJobControl
    {
        if (WmsAutoOrderJobControl::hasRunningJob(JobProcessName::ORDER_TRANSMISSION)) {
            throw new \RuntimeException('Order transmission job is already running');
        }

        // 送信前バリデーション: 全候補がCONFIRMED状態であることを確認
        $validation = $this->validationService->validateBatchForTransmission($batchCode);
        if (! $validation['valid']) {
            throw new \RuntimeException($validation['message']);
        }

        $job = WmsAutoOrderJobControl::startJob(JobProcessName::ORDER_TRANSMISSION);
        $job->update(['batch_code' => $batchCode]);

        try {
            // 確定済みの発注候補をグループ化（APPROVED → CONFIRMED に変更）
            $candidateGroups = WmsOrderCandidate::where('batch_code', $batchCode)
                ->where('status', CandidateStatus::CONFIRMED)
                ->whereNull('transmitted_at')
                ->with(['warehouse', 'item', 'contractor'])
                ->get()
                ->groupBy(fn ($c) => "{$c->warehouse_id}_{$c->contractor_id}");

            if ($candidateGroups->isEmpty()) {
                Log::info('No confirmed candidates to transmit', ['batch_code' => $batchCode]);
                $job->markAsSuccess(0);

                return $job;
            }

            $processedCount = 0;
            $totalGroups = $candidateGroups->count();

            foreach ($candidateGroups as $groupKey => $candidates) {
                [$warehouseId, $contractorId] = explode('_', $groupKey);

                $document = $this->createJxDocument(
                    $batchCode,
                    (int) $warehouseId,
                    (int) $contractorId,
                    $candidates
                );

                // 発注先のWMS送信設定を取得
                $setting = WmsContractorSetting::where('contractor_id', $contractorId)->first();
                $transmissionType = $setting?->transmission_type ?? TransmissionType::MANUAL_CSV;

                // 送信実行
                $success = $this->executeTransmission($document, $transmissionType);

                if ($success) {
                    // 候補のステータス更新
                    $candidates->each(function ($candidate) use ($document) {
                        $candidate->update([
                            'status' => CandidateStatus::EXECUTED,
                            'transmission_status' => 'TRANSMITTED',
                            'transmitted_at' => now(),
                            'wms_order_jx_document_id' => $document->id,
                        ]);
                    });
                    $processedCount += $candidates->count();
                }

                $job->updateProgress($processedCount, $totalGroups);
            }

            $job->markAsSuccess($processedCount);

            Log::info('Order transmission completed', [
                'batch_code' => $batchCode,
                'processed_count' => $processedCount,
            ]);

        } catch (\Exception $e) {
            $job->markAsFailed($e->getMessage());
            Log::error('Order transmission failed', [
                'batch_code' => $batchCode,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $job;
    }

    /**
     * JXドキュメントを作成
     */
    private function createJxDocument(
        string $batchCode,
        int $warehouseId,
        int $contractorId,
        $candidates
    ): WmsOrderJxDocument {
        return DB::transaction(function () use ($batchCode, $warehouseId, $contractorId, $candidates) {
            $totalQuantity = $candidates->sum('order_quantity');

            return WmsOrderJxDocument::create([
                'batch_code' => $batchCode,
                'warehouse_id' => $warehouseId,
                'contractor_id' => $contractorId,
                'document_type' => TransmissionDocumentType::PURCHASE,
                'status' => TransmissionDocumentStatus::PENDING,
                'total_items' => $candidates->count(),
                'total_quantity' => $totalQuantity,
                'jx_request_data' => $this->buildJxRequestData($candidates),
            ]);
        });
    }

    /**
     * JX送信データを構築
     */
    private function buildJxRequestData($candidates): array
    {
        $items = [];

        foreach ($candidates as $candidate) {
            $items[] = [
                'item_code' => $candidate->item?->jan_code ?? $candidate->item_id,
                'item_name' => $candidate->item?->item_name ?? '',
                'quantity' => $candidate->order_quantity,
                'unit' => $candidate->quantity_type?->value ?? 'PIECE',
                'expected_arrival_date' => $candidate->expected_arrival_date?->format('Y-m-d'),
            ];
        }

        return [
            'document_date' => now()->format('Y-m-d'),
            'items' => $items,
        ];
    }

    /**
     * 送信を実行
     */
    private function executeTransmission(WmsOrderJxDocument $document, TransmissionType $type): bool
    {
        try {
            if ($type === TransmissionType::JX_FINET) {
                return $this->transmitViaJxFinet($document);
            } else {
                return $this->transmitViaFtp($document);
            }
        } catch (\Exception $e) {
            $this->logTransmission($document, $type, 'TRANSMIT', 'FAILED', null, $e->getMessage());
            $document->update([
                'status' => TransmissionDocumentStatus::ERROR,
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * JX-FINET経由で送信
     */
    private function transmitViaJxFinet(WmsOrderJxDocument $document): bool
    {
        // JX-FINET設定を取得
        $jxSetting = WmsOrderJxSetting::where('warehouse_id', $document->warehouse_id)
            ->where('is_enabled', true)
            ->first();

        if (! $jxSetting) {
            throw new \RuntimeException("JX-FINET settings not found for warehouse {$document->warehouse_id}");
        }

        // TODO: 実際のJX-FINET APIコールを実装
        // 現在はモック実装
        Log::info('Simulating JX-FINET transmission', [
            'document_id' => $document->id,
            'endpoint' => $jxSetting->endpoint_url,
        ]);

        // モック: 成功として処理
        $mockDocumentNo = 'JX'.date('Ymd').str_pad($document->id, 6, '0', STR_PAD_LEFT);

        $document->update([
            'jx_document_no' => $mockDocumentNo,
            'status' => TransmissionDocumentStatus::TRANSMITTED,
            'transmitted_at' => now(),
            'transmitted_by' => auth()->id(),
            'jx_response_data' => [
                'document_no' => $mockDocumentNo,
                'status' => 'ACCEPTED',
                'timestamp' => now()->toIso8601String(),
            ],
        ]);

        $this->logTransmission(
            $document,
            TransmissionType::JX_FINET,
            'TRANSMIT',
            'SUCCESS',
            $document->jx_response_data
        );

        return true;
    }

    /**
     * FTP経由で送信
     */
    private function transmitViaFtp(WmsOrderJxDocument $document): bool
    {
        // TODO: 実際のFTP送信を実装
        Log::info('Simulating FTP transmission', [
            'document_id' => $document->id,
        ]);

        // モック: 成功として処理
        $document->update([
            'status' => TransmissionDocumentStatus::TRANSMITTED,
            'transmitted_at' => now(),
            'transmitted_by' => auth()->id(),
        ]);

        $this->logTransmission(
            $document,
            TransmissionType::FTP,
            'TRANSMIT',
            'SUCCESS'
        );

        return true;
    }

    /**
     * 送信ログを記録
     */
    private function logTransmission(
        WmsOrderJxDocument $document,
        TransmissionType $type,
        string $action,
        string $status,
        ?array $responseData = null,
        ?string $errorMessage = null
    ): void {
        WmsOrderTransmissionLog::create([
            'batch_code' => $document->batch_code,
            'wms_order_jx_document_id' => $document->id,
            'transmission_type' => $type,
            'action' => $action,
            'status' => $status,
            'request_data' => $document->jx_request_data,
            'response_data' => $responseData,
            'error_message' => $errorMessage,
            'executed_by' => auth()->id(),
        ]);
    }

    /**
     * ドキュメントの送信ステータスを確認
     */
    public function checkTransmissionStatus(WmsOrderJxDocument $document): array
    {
        if ($document->status !== TransmissionDocumentStatus::TRANSMITTED) {
            return [
                'confirmed' => false,
                'message' => 'Document not yet transmitted',
            ];
        }

        // TODO: 実際のステータス確認APIコールを実装
        // 現在はモック実装

        return [
            'confirmed' => true,
            'message' => 'Order confirmed by supplier',
        ];
    }

    /**
     * 確定済み発注候補を purchase_create_queue にバッチ登録
     *
     * 仕様書: storage/specifications/inbound/purchase-create-queue-batching.md
     *
     * グルーピング基準:
     * - warehouse_code (倉庫コード)
     * - supplier_code (仕入先コード)
     * - delivered_date (入荷日 = expected_arrival_date)
     *
     * @param  string  $batchCode  バッチコード
     * @return array ['success' => bool, 'queue_count' => int, 'candidate_count' => int, 'errors' => array]
     */
    public function transmitToPurchaseQueue(string $batchCode): array
    {
        // 送信前バリデーション: 全候補がCONFIRMED状態であることを確認
        $validation = $this->validationService->validateBatchForTransmission($batchCode);
        if (! $validation['valid']) {
            throw new \RuntimeException($validation['message']);
        }

        // 確定済みの発注候補を取得
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::CONFIRMED)
            ->whereNull('transmitted_at')
            ->with(['warehouse', 'item', 'contractor'])
            ->get();

        if ($candidates->isEmpty()) {
            Log::info('No confirmed candidates to transmit', ['batch_code' => $batchCode]);

            return [
                'success' => true,
                'queue_count' => 0,
                'candidate_count' => 0,
                'errors' => [],
            ];
        }

        // グルーピング: 倉庫 + 仕入先 + 入荷予定日
        $grouped = $candidates->groupBy(function ($candidate) {
            $warehouseCode = $candidate->warehouse?->code ?? 'UNKNOWN';
            $contractorCode = $candidate->contractor?->code ?? '';
            $deliveredDate = $candidate->expected_arrival_date?->format('Y-m-d') ?? now()->format('Y-m-d');

            return "{$warehouseCode}_{$contractorCode}_{$deliveredDate}";
        });

        $queueCount = 0;
        $candidateCount = 0;
        $errors = [];

        foreach ($grouped as $groupKey => $groupCandidates) {
            try {
                // 100件以下で分割（仕様推奨）
                $chunks = $groupCandidates->chunk(100);

                foreach ($chunks as $chunk) {
                    $queueId = $this->createPurchaseQueueRecord($chunk);

                    // 候補のステータスをEXECUTEDに更新
                    foreach ($chunk as $candidate) {
                        $candidate->update([
                            'status' => CandidateStatus::EXECUTED,
                            'transmitted_at' => now(),
                        ]);
                    }

                    $queueCount++;
                    $candidateCount += $chunk->count();

                    Log::info('Purchase queue created', [
                        'batch_code' => $batchCode,
                        'group_key' => $groupKey,
                        'queue_id' => $queueId,
                        'candidate_count' => $chunk->count(),
                    ]);
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'group_key' => $groupKey,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to create purchase queue', [
                    'batch_code' => $batchCode,
                    'group_key' => $groupKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => empty($errors),
            'queue_count' => $queueCount,
            'candidate_count' => $candidateCount,
            'errors' => $errors,
        ];
    }

    /**
     * purchase_create_queue にレコードを作成
     *
     * @param  Collection  $candidates  同一グループの発注候補
     */
    private function createPurchaseQueueRecord(Collection $candidates): int
    {
        $first = $candidates->first();

        // マスタ情報を取得
        $warehouse = $first->warehouse;
        $contractor = $first->contractor;
        $deliveredDate = $first->expected_arrival_date?->format('Y-m-d') ?? now()->format('Y-m-d');

        // 明細を構築
        $details = $candidates->map(function ($candidate) {
            return [
                'item_code' => $candidate->item?->code ?? '',
                'quantity' => $candidate->order_quantity,
                'quantity_type' => $candidate->quantity_type?->value ?? 'PIECE',
            ];
        })->toArray();

        // 仕入データを構築
        $purchaseData = [
            'process_date' => $deliveredDate,
            'delivered_date' => $deliveredDate,
            'account_date' => $deliveredDate,
            'supplier_code' => $contractor?->code ?? '',
            'warehouse_code' => $warehouse?->code ?? '',
            'note' => $this->buildBatchPurchaseNote($first),
            'details' => $details,
        ];

        // キューに挿入
        $queueId = DB::connection('sakemaru')->table('purchase_create_queue')->insertGetId([
            'request_uuid' => Str::uuid()->toString(),
            'delivered_date' => $deliveredDate,
            'items' => json_encode($purchaseData, JSON_UNESCAPED_UNICODE),
            'status' => 'BEFORE',
            'retry_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $queueId;
    }

    /**
     * バッチ送信用の仕入れ伝票備考を構築
     */
    private function buildBatchPurchaseNote(WmsOrderCandidate $candidate): string
    {
        $parts = [];
        $parts[] = '自動発注';
        $parts[] = "バッチ:{$candidate->batch_code}";

        return implode(' / ', $parts);
    }

    // ========================================
    // JX送信用ファイル生成・送信機能
    // ========================================

    /**
     * 承認済み発注候補からテスト用発注ファイルを生成（JX送信不可）
     *
     * @param  string  $batchCode  バッチコード
     * @return array{success: bool, files: array, total_orders: int, errors: array}
     */
    public function generateTestOrderFiles(string $batchCode): array
    {
        $startTime = microtime(true);
        Log::info('[generateTestOrderFiles] 開始', ['batch_code' => $batchCode]);

        $generator = $this->getOrderFileGenerator();

        if (! $generator) {
            return [
                'success' => false,
                'files' => [],
                'total_orders' => 0,
                'errors' => ['発注ファイル生成クラスが設定されていません'],
            ];
        }

        // APPROVED状態の発注候補を取得
        $queryStart = microtime(true);
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::APPROVED)
            ->with(['warehouse', 'item', 'contractor'])
            ->get();
        Log::info('[generateTestOrderFiles] 候補取得完了', [
            'batch_code' => $batchCode,
            'count' => $candidates->count(),
            'elapsed_ms' => round((microtime(true) - $queryStart) * 1000),
        ]);

        if ($candidates->isEmpty()) {
            return [
                'success' => true,
                'files' => [],
                'total_orders' => 0,
                'errors' => [],
                'message' => '生成対象の承認済み発注候補がありません',
            ];
        }

        // 既存のTESTドキュメントとデータファイルを削除
        $deleteStart = microtime(true);
        WmsOrderJxDocument::where('batch_code', $batchCode)
            ->where('status', TransmissionDocumentStatus::TEST)
            ->delete();
        WmsOrderDataFile::where('batch_code', $batchCode)
            ->where('is_test', true)
            ->delete();
        Log::info('[generateTestOrderFiles] 既存ドキュメント削除完了', [
            'batch_code' => $batchCode,
            'elapsed_ms' => round((microtime(true) - $deleteStart) * 1000),
        ]);

        $result = $this->doGenerateOrderFiles($batchCode, $candidates, TransmissionDocumentStatus::TEST, false);

        Log::info('[generateTestOrderFiles] 完了', [
            'batch_code' => $batchCode,
            'total_elapsed_ms' => round((microtime(true) - $startTime) * 1000),
        ]);

        return $result;
    }

    /**
     * 承認済み発注候補から進捗コールバック付きでテスト用発注ファイルを生成
     *
     * 全てのAPPROVED状態の発注候補を対象とし、ファイル生成後にコールバックを呼び出す。
     * コールバックには各ファイルの結果（order_count等）が渡される。
     *
     * @param  callable  $progressCallback  ファイル生成後に呼び出されるコールバック
     * @return array{success: bool, files: array, total_orders: int, errors: array}
     */
    public function generateTestOrderFilesWithProgress(callable $progressCallback): array
    {
        $startTime = microtime(true);
        Log::info('[generateTestOrderFilesWithProgress] 開始');

        $generator = $this->getOrderFileGenerator();

        if (! $generator) {
            return [
                'success' => false,
                'files' => [],
                'total_orders' => 0,
                'errors' => ['発注ファイル生成クラスが設定されていません'],
            ];
        }

        // 全てのAPPROVED状態の発注候補を取得（バッチコード不問）
        $queryStart = microtime(true);
        $candidates = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
            ->with(['warehouse', 'item', 'contractor'])
            ->get();
        Log::info('[generateTestOrderFilesWithProgress] 候補取得完了', [
            'count' => $candidates->count(),
            'elapsed_ms' => round((microtime(true) - $queryStart) * 1000),
        ]);

        if ($candidates->isEmpty()) {
            return [
                'success' => true,
                'files' => [],
                'total_orders' => 0,
                'errors' => [],
                'message' => '生成対象の承認済み発注候補がありません',
            ];
        }

        // バッチコードを取得（代表値を使用）
        $batchCode = $candidates->first()->batch_code;

        // 既存のTESTドキュメントとデータファイルを全削除
        $deleteStart = microtime(true);
        WmsOrderJxDocument::where('status', TransmissionDocumentStatus::TEST)->delete();
        WmsOrderDataFile::where('is_test', true)->delete();
        Log::info('[generateTestOrderFilesWithProgress] 既存ドキュメント削除完了', [
            'elapsed_ms' => round((microtime(true) - $deleteStart) * 1000),
        ]);

        // 進捗コールバック付きでファイル生成
        $result = $this->doGenerateOrderFilesWithProgress($batchCode, $candidates, TransmissionDocumentStatus::TEST, false, $progressCallback);

        Log::info('[generateTestOrderFilesWithProgress] 完了', [
            'total_files' => count($result['files']),
            'total_orders' => $result['total_orders'],
            'total_elapsed_ms' => round((microtime(true) - $startTime) * 1000),
        ]);

        return $result;
    }

    /**
     * 承認済み発注候補から発注送信ファイルを生成（テスト用・確定前）
     *
     * @deprecated generateTestOrderFiles を使用してください
     *
     * @param  string  $batchCode  バッチコード
     * @return array{success: bool, files: array, total_orders: int, errors: array}
     */
    public function generateOrderFilesForApproved(string $batchCode): array
    {
        return $this->generateTestOrderFiles($batchCode);
    }

    /**
     * 確定済み発注候補から発注送信ファイルを生成（JX対象のみ）
     *
     * @param  string  $batchCode  バッチコード
     * @return array{success: bool, files: array, total_orders: int, errors: array}
     */
    public function generateOrderFiles(string $batchCode): array
    {
        $generator = $this->getOrderFileGenerator();

        if (! $generator) {
            return [
                'success' => false,
                'files' => [],
                'total_orders' => 0,
                'errors' => ['発注ファイル生成クラスが設定されていません'],
            ];
        }

        // JX送信対象の発注先IDを取得
        $jxContractorIds = WmsContractorSetting::where('transmission_type', TransmissionType::JX_FINET)
            ->pluck('contractor_id')
            ->toArray();

        // Generatorの送信先マッピングを考慮（複数発注先→1つのJX代表発注先）
        $mapping = $generator->getTransmissionContractorMapping();
        $mappedContractorIds = array_keys($mapping);

        // JX対象発注先 = 直接JX設定がある OR マッピングされている発注先
        $targetContractorIds = array_unique(array_merge($jxContractorIds, $mappedContractorIds));

        if (empty($targetContractorIds)) {
            return [
                'success' => true,
                'files' => [],
                'total_orders' => 0,
                'errors' => [],
                'message' => 'JX送信対象の発注先がありません',
            ];
        }

        // CONFIRMED状態（確定済み）でファイル未生成のJX対象発注候補を取得
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::CONFIRMED)
            ->whereNull('wms_order_jx_document_id')
            ->whereIn('contractor_id', $targetContractorIds)
            ->with(['warehouse', 'item', 'contractor'])
            ->get();

        // 候補が空でも空ファイル生成のために処理を続行する
        return $this->doGenerateOrderFiles($batchCode, $candidates, TransmissionDocumentStatus::PENDING, true);
    }

    /**
     * 発注送信ファイルを生成（共通処理）
     */
    private function doGenerateOrderFiles(
        string $batchCode,
        Collection $candidates,
        TransmissionDocumentStatus $status,
        bool $linkCandidates
    ): array {
        return $this->doGenerateOrderFilesWithProgress($batchCode, $candidates, $status, $linkCandidates, null);
    }

    /**
     * 発注送信ファイルを生成（進捗コールバック付き）
     *
     * @param  string  $batchCode  バッチコード
     * @param  Collection  $candidates  発注候補
     * @param  TransmissionDocumentStatus  $status  ドキュメントステータス
     * @param  bool  $linkCandidates  候補とドキュメントを紐付けるか
     * @param  callable|null  $progressCallback  ファイル生成後に呼び出されるコールバック
     */
    private function doGenerateOrderFilesWithProgress(
        string $batchCode,
        Collection $candidates,
        TransmissionDocumentStatus $status,
        bool $linkCandidates,
        ?callable $progressCallback
    ): array {
        $methodStart = microtime(true);
        Log::info('[doGenerateOrderFiles] 開始', [
            'batch_code' => $batchCode,
            'candidate_count' => $candidates->count(),
            'status' => $status->value,
            'has_progress_callback' => $progressCallback !== null,
        ]);

        $generator = $this->getOrderFileGenerator();
        $results = [];
        $errors = [];
        $totalOrders = 0;

        try {
            // Generatorでファイル生成
            $generatorStart = microtime(true);
            $files = $generator->generate($candidates);
            Log::info('[doGenerateOrderFiles] Generator完了', [
                'batch_code' => $batchCode,
                'file_count' => count($files),
                'elapsed_ms' => round((microtime(true) - $generatorStart) * 1000),
            ]);

            foreach ($files as $fileIndex => $file) {
                $fileStart = microtime(true);

                // S3に保存（.datファイル）
                $s3Start = microtime(true);
                $s3Path = $this->saveOrderFileToS3($batchCode, $file, $status);
                $s3Elapsed = round((microtime(true) - $s3Start) * 1000);

                // この送信先に属する候補からwarehouse_idを取得
                $mapping = $generator->getTransmissionContractorMapping();
                $fileCandidates = $candidates->filter(function ($c) use ($file, $mapping) {
                    $transmissionId = $mapping[$c->contractor_id] ?? $c->contractor_id;

                    return $transmissionId === $file['contractor_id'];
                });
                $warehouseId = $fileCandidates->first()?->warehouse_id;

                // 入荷予定日を取得（同一グループは同じ日付のはず）
                $expectedArrivalDate = $fileCandidates->first()?->expected_arrival_date;

                // 確認用CSVファイルも生成・保存
                $csvStart = microtime(true);
                $csvPath = $this->generateAndSaveCsvFile($batchCode, $file, $fileCandidates, $status);
                $csvElapsed = round((microtime(true) - $csvStart) * 1000);

                // wms_order_jx_documentsに記録
                $dbStart = microtime(true);
                $document = $this->createOrderDocument($batchCode, $file, $s3Path, $warehouseId, $status, $expectedArrivalDate, $csvPath);
                $dbElapsed = round((microtime(true) - $dbStart) * 1000);

                // 発注候補とドキュメントを紐付け（確定済みの場合のみ）
                if ($linkCandidates) {
                    $this->linkCandidatesToDocument($candidates, $file['contractor_id'], $document);
                }

                $fileResult = [
                    'contractor_id' => $file['contractor_id'],
                    'contractor_code' => $file['contractor_code'] ?? null,
                    'filename' => $file['filename'],
                    's3_path' => $s3Path,
                    'csv_path' => $csvPath,
                    'document_id' => $document->id,
                    'record_count' => $file['record_count'],
                    'order_count' => $file['order_count'],
                ];

                $results[] = $fileResult;
                $totalOrders += $file['order_count'];

                // 進捗コールバックを呼び出し
                if ($progressCallback !== null) {
                    $progressCallback($fileResult);
                }

                Log::info('[doGenerateOrderFiles] ファイル処理完了', [
                    'batch_code' => $batchCode,
                    'file_index' => $fileIndex + 1,
                    'contractor_id' => $file['contractor_id'],
                    'filename' => $file['filename'],
                    's3_ms' => $s3Elapsed,
                    'csv_ms' => $csvElapsed,
                    'db_ms' => $dbElapsed,
                    'total_ms' => round((microtime(true) - $fileStart) * 1000),
                ]);
            }

            // データなしJX設定に対する空ファイル生成
            $emptyFileResults = $this->generateEmptyFilesForMissingSettings(
                $batchCode,
                $files,
                $status,
                $progressCallback
            );
            foreach ($emptyFileResults as $emptyResult) {
                $results[] = $emptyResult;
            }
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
            Log::error('Order file generation failed', [
                'batch_code' => $batchCode,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'success' => empty($errors),
            'files' => $results,
            'total_orders' => $totalOrders,
            'errors' => $errors,
        ];
    }

    /**
     * 確認用CSVファイルを生成・保存
     *
     * S3にCSVファイルを保存し、wms_order_data_filesにも記録する
     */
    private function generateAndSaveCsvFile(
        string $batchCode,
        array $file,
        Collection $candidates,
        TransmissionDocumentStatus $status
    ): ?string {
        if ($candidates->isEmpty()) {
            return null;
        }

        // CSVヘッダー
        $headers = [
            '発注先コード',
            '発注先名',
            '倉庫コード',
            '倉庫名',
            '商品コード',
            '商品名',
            '発注コード',
            '発注数量',
            '入荷予定日',
            '発注日',
        ];

        // CSVデータ
        $rows = [];
        $rows[] = $headers;

        foreach ($candidates as $candidate) {
            $rows[] = [
                $candidate->contractor?->code ?? '',
                $candidate->contractor?->name ?? '',
                $candidate->warehouse?->code ?? '',
                $candidate->warehouse?->name ?? '',
                $candidate->item?->code ?? '',
                $candidate->item?->name ?? '',
                $candidate->ordering_code ?? '',
                $candidate->order_quantity,
                $candidate->expected_arrival_date?->format('Y-m-d') ?? '',
                now()->format('Y-m-d'),
            ];
        }

        // CSV生成
        $stream = fopen('php://temp', 'r+');
        // BOM付きUTF-8
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $csvContent = stream_get_contents($stream);
        fclose($stream);

        // S3に保存
        // ファイル名: {実行CD}_{倉庫コード}_{発注先コード}.csv (テストは test_ プレフィックス)
        $folder = $status === TransmissionDocumentStatus::TEST ? 'jx-orders-test' : 'jx-orders';
        $date = now()->format('Y-m-d');
        $contractorCode = $file['contractor_code'] ?? $file['contractor_id'];
        $warehouseCode = $candidates->first()?->warehouse?->code ?? 'UNKNOWN';
        $isTest = $status === TransmissionDocumentStatus::TEST;
        $prefix = $isTest ? 'test_' : '';
        $csvFilename = "{$prefix}{$batchCode}_{$warehouseCode}_{$contractorCode}.csv";
        $csvPath = "{$folder}/{$date}/{$csvFilename}";

        Storage::disk('s3')->put($csvPath, $csvContent);

        // wms_order_data_filesにも記録（テストファイルは毎回新規作成、本番は更新）
        $firstCandidate = $candidates->first();

        if ($isTest) {
            // テストファイルは毎回新規作成（同じものを何回でも生成可能）
            WmsOrderDataFile::create([
                'batch_code' => $batchCode,
                'warehouse_id' => $firstCandidate?->warehouse_id,
                'contractor_id' => $file['contractor_id'],
                'order_date' => now()->toDateString(),
                'expected_arrival_date' => $firstCandidate?->expected_arrival_date,
                'file_path' => $csvPath,
                'file_size' => strlen($csvContent),
                'order_count' => $candidates->count(),
                'total_quantity' => $candidates->sum('order_quantity'),
                'status' => OrderDataFileStatus::GENERATED,
                'is_test' => true,
            ]);
        } else {
            // 本番ファイルは重複時は更新
            WmsOrderDataFile::updateOrCreate(
                [
                    'batch_code' => $batchCode,
                    'warehouse_id' => $firstCandidate?->warehouse_id,
                    'contractor_id' => $file['contractor_id'],
                ],
                [
                    'order_date' => now()->toDateString(),
                    'expected_arrival_date' => $firstCandidate?->expected_arrival_date,
                    'file_path' => $csvPath,
                    'file_size' => strlen($csvContent),
                    'order_count' => $candidates->count(),
                    'total_quantity' => $candidates->sum('order_quantity'),
                    'status' => OrderDataFileStatus::GENERATED,
                    'is_test' => false,
                    'downloaded_at' => null,
                    'downloaded_by' => null,
                ]
            );
        }

        return $csvPath;
    }

    /**
     * ドキュメントのダウンロードURLを取得
     */
    public function getDownloadUrl(WmsOrderJxDocument $document): ?string
    {
        if (! $document->file_path) {
            return null;
        }

        // 署名付きURL（1時間有効）
        return Storage::disk('s3')->temporaryUrl(
            $document->file_path,
            now()->addHour()
        );
    }

    /**
     * ドキュメントの内容を取得
     */
    public function getDocumentContent(WmsOrderJxDocument $document): ?string
    {
        if (! $document->file_path) {
            return null;
        }

        return Storage::disk('s3')->get($document->file_path);
    }

    /**
     * 発注ファイルをJX-FINETで送信
     *
     * @param  string  $batchCode  バッチコード
     * @return array{success: bool, transmitted: array, errors: array}
     */
    public function transmitOrderFilesViaJx(string $batchCode): array
    {
        // PENDING状態のドキュメントを取得
        $documents = WmsOrderJxDocument::where('batch_code', $batchCode)
            ->where('status', TransmissionDocumentStatus::PENDING)
            ->get();

        if ($documents->isEmpty()) {
            return [
                'success' => true,
                'transmitted' => [],
                'errors' => [],
                'message' => '送信対象のドキュメントがありません',
            ];
        }

        $transmitted = [];
        $errors = [];

        foreach ($documents as $document) {
            try {
                $result = $this->transmitDocumentViaJx($document);

                if ($result['success']) {
                    $transmitted[] = [
                        'document_id' => $document->id,
                        'contractor_id' => $document->contractor_id,
                        'message_id' => $result['message_id'] ?? null,
                    ];
                } else {
                    $errors[] = [
                        'document_id' => $document->id,
                        'error' => $result['error'] ?? '送信失敗',
                    ];
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => empty($errors),
            'transmitted' => $transmitted,
            'errors' => $errors,
        ];
    }

    /**
     * 指定されたドキュメントIDのJXファイルを送信
     *
     * @param  int  $documentId  ドキュメントID
     * @return array{success: bool, message_id?: string, error?: string}
     */
    public function transmitDocumentById(int $documentId): array
    {
        $document = WmsOrderJxDocument::find($documentId);

        if (! $document) {
            return ['success' => false, 'error' => 'ドキュメントが見つかりません'];
        }

        if ($document->status !== TransmissionDocumentStatus::PENDING) {
            return ['success' => false, 'error' => '送信可能なステータスではありません'];
        }

        return $this->transmitDocumentViaJx($document);
    }

    /**
     * 発注ファイル生成クラスを取得
     */
    private function getOrderFileGenerator(): ?OrderFileGeneratorInterface
    {
        return OrderServiceFactory::generator();
    }

    /**
     * 発注ファイルをS3に保存
     *
     * 注: AWS_BUCKET_PREFIXがfilesystems.phpで設定されているため、
     * ここでは追加のprefixを付けない
     */
    private function saveOrderFileToS3(string $batchCode, array $file, TransmissionDocumentStatus $status): string
    {
        $date = now()->format('Y-m-d');

        // ステータスに応じてフォルダを分ける
        $folder = match ($status) {
            TransmissionDocumentStatus::TEST => 'jx-orders-test',
            TransmissionDocumentStatus::DRAFT => 'jx-orders-draft',
            default => 'jx-orders',
        };

        $path = "{$folder}/{$date}/{$file['filename']}";

        Storage::disk('s3')->put($path, $file['content']);

        Log::info('Order file saved to S3', [
            'batch_code' => $batchCode,
            'path' => $path,
            'status' => $status->value,
        ]);

        return $path;
    }

    /**
     * 発注ドキュメントを作成
     */
    private function createOrderDocument(
        string $batchCode,
        array $file,
        string $s3Path,
        ?int $warehouseId = null,
        TransmissionDocumentStatus $status = TransmissionDocumentStatus::PENDING,
        $expectedArrivalDate = null,
        ?string $csvPath = null
    ): WmsOrderJxDocument {
        return WmsOrderJxDocument::create([
            'batch_code' => $batchCode,
            'wms_order_jx_setting_id' => $file['jx_setting_id'] ?? null,
            'warehouse_id' => $warehouseId,
            'contractor_id' => $file['contractor_id'],
            'order_date' => now()->toDateString(),
            'expected_arrival_date' => $expectedArrivalDate,
            'document_type' => TransmissionDocumentType::PURCHASE,
            'status' => $status,
            'file_path' => $s3Path,
            'csv_path' => $csvPath,
            'file_size' => strlen($file['content']),
            'record_count' => $file['record_count'],
            'order_count' => $file['order_count'],
            'encoding' => $file['encoding'],
        ]);
    }

    /**
     * 発注候補とドキュメントを紐付け
     */
    private function linkCandidatesToDocument(Collection $candidates, int $contractorId, WmsOrderJxDocument $document): void
    {
        // transmission_contractor_idを考慮して紐付け
        $generator = $this->getOrderFileGenerator();
        $mapping = $generator?->getTransmissionContractorMapping() ?? [];

        $candidates->each(function ($candidate) use ($contractorId, $document, $mapping) {
            // この候補が送信先発注先と一致するか確認
            $candidateTransmissionId = $mapping[$candidate->contractor_id] ?? $candidate->contractor_id;

            if ($candidateTransmissionId === $contractorId) {
                $candidate->update([
                    'wms_order_jx_document_id' => $document->id,
                ]);
            }
        });
    }

    /**
     * データなしJX設定に対して空ファイルを生成
     *
     * 生成済みファイルのJX設定IDを確認し、まだファイルが生成されていない
     * アクティブなJX設定に対して空ファイルを生成する。
     * add_zero_record=true: Aレコード付き空ファイル
     * add_zero_record=false: JXラッパーのみの完全空ファイル
     *
     * @param  string  $batchCode  バッチコード
     * @param  array  $generatedFiles  生成済みファイル情報
     * @param  TransmissionDocumentStatus  $status  ドキュメントステータス
     * @param  callable|null  $progressCallback  進捗コールバック
     * @return array 空ファイル生成結果
     */
    private function generateEmptyFilesForMissingSettings(
        string $batchCode,
        array $generatedFiles,
        TransmissionDocumentStatus $status,
        ?callable $progressCallback
    ): array {
        // 生成済みファイルのJX設定IDを収集
        $generatedSettingIds = collect($generatedFiles)
            ->pluck('jx_setting_id')
            ->filter()
            ->unique()
            ->toArray();

        // 全アクティブJX設定を取得
        $allActiveSettings = WmsOrderJxSetting::where('is_active', true)->get();

        $results = [];
        $generator = $this->getOrderFileGenerator();

        if (! ($generator instanceof HanaOrderFileGenerator)) {
            return $results;
        }

        foreach ($allActiveSettings as $jxSetting) {
            // 既にファイルが生成されている設定はスキップ
            if (in_array($jxSetting->id, $generatedSettingIds)) {
                continue;
            }

            try {
                $file = $generator->generateEmptyFile($jxSetting);

                // S3に保存
                $s3Path = $this->saveOrderFileToS3($batchCode, $file, $status);

                // ドキュメント作成（CSV生成・候補紐付けなし）
                $document = $this->createOrderDocument($batchCode, $file, $s3Path, null, $status);

                $fileResult = [
                    'contractor_id' => $file['contractor_id'],
                    'contractor_code' => $file['contractor_code'] ?? null,
                    'filename' => $file['filename'],
                    's3_path' => $s3Path,
                    'csv_path' => null,
                    'document_id' => $document->id,
                    'record_count' => $file['record_count'],
                    'order_count' => 0,
                ];

                $results[] = $fileResult;

                if ($progressCallback !== null) {
                    $progressCallback($fileResult);
                }

                Log::info('[doGenerateOrderFiles] 空ファイル生成完了', [
                    'batch_code' => $batchCode,
                    'jx_setting_id' => $jxSetting->id,
                    'contractor_id' => $file['contractor_id'],
                    'filename' => $file['filename'],
                ]);
            } catch (\Exception $e) {
                Log::error('[doGenerateOrderFiles] 空ファイル生成失敗', [
                    'jx_setting_id' => $jxSetting->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * ドキュメントをJX-FINETで送信
     */
    private function transmitDocumentViaJx(WmsOrderJxDocument $document): array
    {
        // JX設定を取得
        $jxSetting = null;
        if ($document->wms_order_jx_setting_id) {
            $jxSetting = WmsOrderJxSetting::find($document->wms_order_jx_setting_id);
        }
        if (! $jxSetting && $document->contractor_id) {
            $jxSetting = WmsOrderJxSetting::findByContractorId($document->contractor_id);
        }

        if (! $jxSetting) {
            $document->update([
                'status' => TransmissionDocumentStatus::ERROR,
                'error_message' => 'JX設定が見つかりません',
            ]);

            return ['success' => false, 'error' => 'JX設定が見つかりません'];
        }

        // ファイル内容を取得
        $fileContent = Storage::disk('s3')->get($document->file_path);
        if (! $fileContent) {
            $document->update([
                'status' => TransmissionDocumentStatus::ERROR,
                'error_message' => 'ファイルが見つかりません',
            ]);

            return ['success' => false, 'error' => 'ファイルが見つかりません'];
        }

        // JX送信実行
        $client = new JxClient($jxSetting);
        $result = $client->putDocumentWithWrapper(
            $fileContent,
            $jxSetting->send_document_type ?? '91',
            'SecondGenEDI'
        );

        if ($result->succeeded()) {
            // バックアップをS3に保存
            $this->saveBackupToS3($document, $fileContent);

            $document->update([
                'status' => TransmissionDocumentStatus::TRANSMITTED,
                'transmitted_at' => now(),
                'transmitted_by' => auth()->id(),
                'jx_message_id' => $result->messageId,
                'jx_response_data' => [
                    'message_id' => $result->messageId,
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

            Log::info('JX transmission succeeded', [
                'document_id' => $document->id,
                'message_id' => $result->messageId,
            ]);

            return ['success' => true, 'message_id' => $result->messageId];
        } else {
            $document->update([
                'status' => TransmissionDocumentStatus::ERROR,
                'error_message' => $result->error,
            ]);

            Log::error('JX transmission failed', [
                'document_id' => $document->id,
                'error' => $result->error,
            ]);

            return ['success' => false, 'error' => $result->error];
        }
    }

    /**
     * 送信済みファイルのバックアップをS3に保存
     *
     * 注: AWS_BUCKET_PREFIXがfilesystems.phpで設定されているため、
     * ここでは追加のprefixを付けない
     */
    private function saveBackupToS3(WmsOrderJxDocument $document, string $content): void
    {
        $date = now()->format('Y-m-d');
        $timestamp = now()->format('His');
        $filename = pathinfo($document->file_path, PATHINFO_FILENAME);
        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);

        $backupPath = "jx-backup/{$date}/{$filename}_{$timestamp}.{$extension}";

        Storage::disk('s3')->put($backupPath, $content);

        Log::info('Backup saved to S3', [
            'document_id' => $document->id,
            'path' => $backupPath,
        ]);
    }
}
