<?php

namespace App\Services\AutoOrder;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 発注送信サービス
 */
class OrderTransmissionService
{
    /**
     * 承認済み発注候補を送信
     */
    public function transmitApprovedOrders(string $batchCode): WmsAutoOrderJobControl
    {
        if (WmsAutoOrderJobControl::hasRunningJob(JobProcessName::ORDER_TRANSMISSION)) {
            throw new \RuntimeException('Order transmission job is already running');
        }

        $job = WmsAutoOrderJobControl::startJob(JobProcessName::ORDER_TRANSMISSION);
        $job->update(['batch_code' => $batchCode]);

        try {
            // 承認済みの発注候補をグループ化
            $candidateGroups = WmsOrderCandidate::where('batch_code', $batchCode)
                ->where('status', CandidateStatus::APPROVED)
                ->whereNull('transmitted_at')
                ->with(['warehouse', 'item', 'contractor'])
                ->get()
                ->groupBy(fn ($c) => "{$c->warehouse_id}_{$c->contractor_id}");

            if ($candidateGroups->isEmpty()) {
                Log::info('No approved candidates to transmit', ['batch_code' => $batchCode]);
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
}
