<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Models\WmsOrderJxDocument;
use App\Models\WmsQueueProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 発注確定処理のクリーンアップサービス
 *
 * タイムアウトしたジョブの後処理を行う
 */
class OrderConfirmationCleanupService
{
    /**
     * タイムアウト時間（分）
     */
    public const TIMEOUT_MINUTES = 15;

    /**
     * タイムアウトしたジョブをチェックしてクリーンアップ
     *
     * @return array|null クリーンアップ結果、タイムアウトなしの場合はnull
     */
    public function checkAndCleanupTimedOutJob(WmsQueueProgress $progress): ?array
    {
        if (! $this->isTimedOut($progress)) {
            return null;
        }

        $startTime = $progress->started_at ?? $progress->created_at;
        Log::warning('Order confirmation job timed out, starting cleanup', [
            'job_id' => $progress->job_id,
            'job_type' => $progress->job_type,
            'started_at' => $startTime?->toDateTimeString(),
            'now' => now()->toDateTimeString(),
        ]);

        return $this->cleanup($progress);
    }

    /**
     * ジョブがタイムアウトしているかチェック
     */
    public function isTimedOut(WmsQueueProgress $progress): bool
    {
        if (! $progress->isActive()) {
            return false;
        }

        // started_atがnullの場合はcreated_atを使用
        $startTime = $progress->started_at ?? $progress->created_at;

        if (! $startTime) {
            return false;
        }

        // 開始時刻 + タイムアウト時間が現在時刻より前ならタイムアウト
        $timeoutAt = $startTime->copy()->addMinutes(self::TIMEOUT_MINUTES);

        return now()->gte($timeoutAt);
    }

    /**
     * タイムアウトしたジョブのクリーンアップを実行
     */
    public function cleanup(WmsQueueProgress $progress): array
    {
        $result = [
            'deleted_csv_files' => 0,
            'deleted_jx_documents' => 0,
            'reverted_candidates' => 0,
            'deleted_s3_files' => 0,
            'errors' => [],
        ];

        try {
            DB::beginTransaction();

            // ジョブ開始時刻以降に確定された候補のバッチコードを取得
            $startTime = $progress->started_at ?? $progress->created_at;
            $warehouseId = data_get($progress->metadata, 'warehouse_id');

            $affectedCandidatesQuery = WmsOrderCandidate::where('status', CandidateStatus::CONFIRMED)
                ->where('updated_at', '>=', $startTime);

            if ($warehouseId !== null) {
                $affectedCandidatesQuery->where('warehouse_id', $warehouseId);
            }

            $affectedBatchCodes = $affectedCandidatesQuery
                ->distinct()
                ->pluck('batch_code')
                ->toArray();

            if (! empty($affectedBatchCodes)) {
                // 1. S3からCSVファイルを削除し、DBレコードも削除
                $csvFilesQuery = WmsOrderDataFile::whereIn('batch_code', $affectedBatchCodes);
                if ($warehouseId !== null) {
                    $csvFilesQuery->where('warehouse_id', $warehouseId);
                }
                $csvFiles = $csvFilesQuery->get();
                foreach ($csvFiles as $file) {
                    try {
                        if ($file->file_path && Storage::disk('s3')->exists($file->file_path)) {
                            Storage::disk('s3')->delete($file->file_path);
                            $result['deleted_s3_files']++;
                        }
                    } catch (\Exception $e) {
                        $result['errors'][] = "CSV file delete error: {$file->file_path} - {$e->getMessage()}";
                    }
                }
                $csvDeleteQuery = WmsOrderDataFile::whereIn('batch_code', $affectedBatchCodes);
                if ($warehouseId !== null) {
                    $csvDeleteQuery->where('warehouse_id', $warehouseId);
                }
                $result['deleted_csv_files'] = $csvDeleteQuery->delete();

                // 2. S3からJXファイルを削除し、DBレコードも削除
                $jxDocumentsQuery = WmsOrderJxDocument::whereIn('batch_code', $affectedBatchCodes);
                if ($warehouseId !== null) {
                    $jxDocumentsQuery->where('warehouse_id', $warehouseId);
                }
                $jxDocuments = $jxDocumentsQuery->get();
                foreach ($jxDocuments as $doc) {
                    try {
                        // .datファイル
                        if ($doc->file_path && Storage::disk('s3')->exists($doc->file_path)) {
                            Storage::disk('s3')->delete($doc->file_path);
                            $result['deleted_s3_files']++;
                        }
                        // 確認用CSVファイル
                        if ($doc->csv_path && Storage::disk('s3')->exists($doc->csv_path)) {
                            Storage::disk('s3')->delete($doc->csv_path);
                            $result['deleted_s3_files']++;
                        }
                    } catch (\Exception $e) {
                        $result['errors'][] = "JX file delete error: {$doc->file_path} - {$e->getMessage()}";
                    }
                }

                // JXドキュメントへの参照をクリア
                $candidateDocumentClearQuery = WmsOrderCandidate::whereIn('batch_code', $affectedBatchCodes)
                    ->whereNotNull('wms_order_jx_document_id');
                if ($warehouseId !== null) {
                    $candidateDocumentClearQuery->where('warehouse_id', $warehouseId);
                }
                $candidateDocumentClearQuery->update(['wms_order_jx_document_id' => null]);

                $jxDeleteQuery = WmsOrderJxDocument::whereIn('batch_code', $affectedBatchCodes);
                if ($warehouseId !== null) {
                    $jxDeleteQuery->where('warehouse_id', $warehouseId);
                }
                $result['deleted_jx_documents'] = $jxDeleteQuery->delete();

                // 3. CONFIRMED状態の候補をAPPROVEDに戻す
                $candidateRevertQuery = WmsOrderCandidate::whereIn('batch_code', $affectedBatchCodes)
                    ->where('status', CandidateStatus::CONFIRMED);
                if ($warehouseId !== null) {
                    $candidateRevertQuery->where('warehouse_id', $warehouseId);
                }
                $result['reverted_candidates'] = $candidateRevertQuery->update([
                    'status' => CandidateStatus::APPROVED,
                    'updated_at' => now(),
                ]);
            }

            // 4. 進捗レコードをエラー終了としてマーク
            $progress->markAsFailed(
                'タイムアウト（'.self::TIMEOUT_MINUTES.'分経過）によりキャンセルされました。生成されたファイルは削除されました。',
                $result
            );

            DB::commit();

            Log::info('Order confirmation cleanup completed', [
                'job_id' => $progress->job_id,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            $result['errors'][] = $e->getMessage();

            // エラーでも進捗はエラー終了にする
            $progress->markAsFailed(
                'クリーンアップ中にエラーが発生しました: '.$e->getMessage(),
                $result
            );

            Log::error('Order confirmation cleanup failed', [
                'job_id' => $progress->job_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }
}
