<?php

namespace App\Services\AutoOrder;

use App\Contracts\OrderFileGeneratorInterface;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\AutoOrder\TransmissionDocumentType;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderJxDocument;
use App\Models\WmsOrderJxSetting;
use App\Models\WmsOrderTransmissionLog;
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
     * 発注送信ファイルを生成
     *
     * @param  string  $batchCode  バッチコード
     * @return array{success: bool, files: array, errors: array}
     */
    public function generateOrderFiles(string $batchCode): array
    {
        $generator = $this->getOrderFileGenerator();

        if (! $generator) {
            return [
                'success' => false,
                'files' => [],
                'errors' => ['発注ファイル生成クラスが設定されていません'],
            ];
        }

        // EXECUTED状態（確定済み）で未送信の発注候補を取得
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::EXECUTED)
            ->whereNull('wms_order_jx_document_id')
            ->with(['warehouse', 'item', 'contractor'])
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'success' => true,
                'files' => [],
                'errors' => [],
                'message' => '生成対象の発注候補がありません',
            ];
        }

        $results = [];
        $errors = [];

        try {
            // Generatorでファイル生成
            $files = $generator->generate($candidates);

            foreach ($files as $file) {
                // S3に保存
                $s3Path = $this->saveOrderFileToS3($batchCode, $file);

                // この送信先に属する候補からwarehouse_idを取得
                $mapping = $generator->getTransmissionContractorMapping();
                $fileCandidates = $candidates->filter(function ($c) use ($file, $mapping) {
                    $transmissionId = $mapping[$c->contractor_id] ?? $c->contractor_id;

                    return $transmissionId === $file['contractor_id'];
                });
                $warehouseId = $fileCandidates->first()?->warehouse_id;

                // wms_order_jx_documentsに記録
                $document = $this->createOrderDocument($batchCode, $file, $s3Path, $warehouseId);

                // 発注候補とドキュメントを紐付け
                $this->linkCandidatesToDocument($candidates, $file['contractor_id'], $document);

                $results[] = [
                    'contractor_id' => $file['contractor_id'],
                    'contractor_code' => $file['contractor_code'] ?? null,
                    'filename' => $file['filename'],
                    's3_path' => $s3Path,
                    'document_id' => $document->id,
                    'record_count' => $file['record_count'],
                    'order_count' => $file['order_count'],
                ];

                Log::info('Order file generated', [
                    'batch_code' => $batchCode,
                    'contractor_id' => $file['contractor_id'],
                    'filename' => $file['filename'],
                ]);
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
            'errors' => $errors,
        ];
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
    private function saveOrderFileToS3(string $batchCode, array $file): string
    {
        $date = now()->format('Y-m-d');
        $path = "jx-orders/{$date}/{$file['filename']}";

        Storage::disk('s3')->put($path, $file['content']);

        Log::info('Order file saved to S3', [
            'batch_code' => $batchCode,
            'path' => $path,
        ]);

        return $path;
    }

    /**
     * 発注ドキュメントを作成
     */
    private function createOrderDocument(string $batchCode, array $file, string $s3Path, ?int $warehouseId = null): WmsOrderJxDocument
    {
        return WmsOrderJxDocument::create([
            'batch_code' => $batchCode,
            'wms_order_jx_setting_id' => $file['jx_setting_id'],
            'warehouse_id' => $warehouseId,
            'contractor_id' => $file['contractor_id'],
            'document_type' => TransmissionDocumentType::PURCHASE,
            'status' => TransmissionDocumentStatus::PENDING,
            'file_path' => $s3Path,
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
