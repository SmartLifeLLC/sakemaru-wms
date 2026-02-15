<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 発注データファイルサービス（共通CSVダウンロード用）
 *
 * 倉庫別×発注先別にCSVファイルを生成・管理
 */
class OrderDataFileService
{
    /**
     * 確定済み発注候補からCSVファイルを生成
     *
     * @param  string  $batchCode  バッチコード
     * @return array{success: bool, files: array, total_files: int, errors: array}
     */
    public function generateCsvFiles(string $batchCode): array
    {
        // CONFIRMED状態の発注候補を取得
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::CONFIRMED)
            ->with(['warehouse', 'item', 'contractor'])
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'success' => true,
                'files' => [],
                'total_files' => 0,
                'errors' => [],
                'message' => '生成対象の発注候補がありません',
            ];
        }

        // 倉庫×発注先でグルーピング
        $grouped = $candidates->groupBy(function ($candidate) {
            return "{$candidate->warehouse_id}_{$candidate->contractor_id}";
        });

        $results = [];
        $errors = [];

        foreach ($grouped as $groupKey => $groupCandidates) {
            try {
                $result = $this->generateCsvFile($batchCode, $groupCandidates);
                $results[] = $result;

                Log::info('Order data CSV file generated', [
                    'batch_code' => $batchCode,
                    'warehouse_id' => $groupCandidates->first()->warehouse_id,
                    'contractor_id' => $groupCandidates->first()->contractor_id,
                    'order_count' => $groupCandidates->count(),
                ]);
            } catch (\Exception $e) {
                $errors[] = [
                    'group' => $groupKey,
                    'error' => $e->getMessage(),
                ];
                Log::error('Order data CSV file generation failed', [
                    'batch_code' => $batchCode,
                    'group' => $groupKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => empty($errors),
            'files' => $results,
            'total_files' => count($results),
            'errors' => $errors,
        ];
    }

    /**
     * 1つのCSVファイルを生成
     */
    private function generateCsvFile(string $batchCode, Collection $candidates): array
    {
        $firstCandidate = $candidates->first();
        $warehouseId = $firstCandidate->warehouse_id;
        $contractorId = $firstCandidate->contractor_id;
        $warehouse = $firstCandidate->warehouse;
        $contractor = $firstCandidate->contractor;

        // 入荷予定日（グループ内で最も早い日付）
        $expectedArrivalDate = $candidates->min('expected_arrival_date');

        // CSV生成
        $csvContent = $this->buildCsvContent($candidates);

        // S3に保存
        // ファイル名: {実行CD}_{倉庫コード}_{発注先コード}.csv
        $date = now()->format('Y-m-d');
        $warehouseCode = $warehouse?->code ?? $warehouseId;
        $contractorCode = $contractor?->code ?? $contractorId;
        $filename = "{$batchCode}_{$warehouseCode}_{$contractorCode}.csv";
        $filePath = "order-data-files/{$date}/{$filename}";

        Storage::disk('s3')->put($filePath, $csvContent);

        // 合計数量を計算
        $totalQuantity = $candidates->sum('order_quantity');

        // DBに記録
        $dataFile = WmsOrderDataFile::updateOrCreate(
            [
                'batch_code' => $batchCode,
                'warehouse_id' => $warehouseId,
                'contractor_id' => $contractorId,
                'is_test' => false,
            ],
            [
                'order_date' => now()->toDateString(),
                'expected_arrival_date' => $expectedArrivalDate,
                'file_path' => $filePath,
                'file_size' => strlen($csvContent),
                'order_count' => $candidates->count(),
                'total_quantity' => $totalQuantity,
                'status' => OrderDataFileStatus::GENERATED,
                'csv_downloaded_at' => null,
                'csv_downloaded_by' => null,
            ]
        );

        return [
            'id' => $dataFile->id,
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $warehouse?->name,
            'contractor_id' => $contractorId,
            'contractor_name' => $contractor?->name,
            'file_path' => $filePath,
            'order_count' => $candidates->count(),
            'total_quantity' => $totalQuantity,
        ];
    }

    /**
     * CSV内容を生成
     *
     * 伝票ヘッダー情報と明細情報が1行に表示される形式
     * （ヘッダーは明細分重複表示される）
     */
    private function buildCsvContent(Collection $candidates): string
    {
        // 入荷予定日でグルーピングして伝票番号を付与
        $slipGroups = $candidates->groupBy(function ($candidate) {
            return $candidate->expected_arrival_date?->format('Y-m-d') ?? 'unknown';
        });

        // 伝票番号マッピングを作成
        $slipNumberMap = [];
        $slipNo = 1;
        foreach ($slipGroups as $date => $group) {
            $slipNumberMap[$date] = $slipNo++;
        }

        $headers = [
            // 伝票ヘッダー
            '伝票番号',
            '発注日',
            '入荷予定日',
            '倉庫コード',
            '倉庫名',
            '発注先コード',
            '発注先名',
            // 明細
            '明細行',
            '商品コード',
            '商品名',
            '規格',
            '発注コード',
            '発注数量',
            '単位',
        ];

        $rows = [];
        $rows[] = $headers;

        // 入荷予定日順でソート
        $sortedCandidates = $candidates->sortBy('expected_arrival_date');

        // 明細行番号をトラック（伝票ごと）
        $lineNumbers = [];

        foreach ($sortedCandidates as $candidate) {
            $arrivalDate = $candidate->expected_arrival_date?->format('Y-m-d') ?? 'unknown';
            $slipNo = $slipNumberMap[$arrivalDate] ?? 0;

            // 伝票内の明細行番号
            if (! isset($lineNumbers[$slipNo])) {
                $lineNumbers[$slipNo] = 0;
            }
            $lineNumbers[$slipNo]++;

            $rows[] = [
                // 伝票ヘッダー（明細分重複）
                $slipNo,
                now()->format('Y-m-d'),
                $candidate->expected_arrival_date?->format('Y-m-d') ?? '',
                $candidate->warehouse?->code ?? '',
                $candidate->warehouse?->name ?? '',
                $candidate->contractor?->code ?? '',
                $candidate->contractor?->name ?? '',
                // 明細
                $lineNumbers[$slipNo],
                $candidate->item?->code ?? '',
                $candidate->item?->name ?? '',
                $candidate->item?->packaging ?? '',
                $candidate->ordering_code ?? '',
                $candidate->order_quantity,
                $candidate->quantity_type?->value ?? 'PIECE',
            ];
        }

        // BOM付きUTF-8 CSV生成
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    /**
     * ダウンロードURLを取得
     */
    public function getDownloadUrl(WmsOrderDataFile $dataFile): ?string
    {
        if (! $dataFile->file_path) {
            return null;
        }

        return Storage::disk('s3')->temporaryUrl(
            $dataFile->file_path,
            now()->addHour()
        );
    }
}
