<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Models\Sakemaru\ClientSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Models\WmsOrderIncomingSchedule;
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
     * @param  bool  $splitByWarehouse  納品先（倉庫）別にファイルを分割するか
     * @return array{success: bool, files: array, total_files: int, errors: array}
     */
    public function generateCsvFiles(string $batchCode, bool $splitByWarehouse = true): array
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

        // グルーピング: 納品先別 or 発注先のみ
        $grouped = $splitByWarehouse
            ? $candidates->groupBy(fn ($candidate) => "{$candidate->warehouse_id}_{$candidate->contractor_id}")
            : $candidates->groupBy(fn ($candidate) => (string) $candidate->contractor_id);

        $results = [];
        $errors = [];

        foreach ($grouped as $groupKey => $groupCandidates) {
            try {
                $result = $this->generateCsvFile($batchCode, $groupCandidates, $splitByWarehouse);
                $results[] = $result;

                Log::info('Order data CSV file generated', [
                    'batch_code' => $batchCode,
                    'warehouse_id' => $splitByWarehouse ? $groupCandidates->first()->warehouse_id : null,
                    'contractor_id' => $groupCandidates->first()->contractor_id,
                    'order_count' => $groupCandidates->count(),
                    'split_by_warehouse' => $splitByWarehouse,
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
    private function generateCsvFile(string $batchCode, Collection $candidates, bool $splitByWarehouse = true): array
    {
        $firstCandidate = $candidates->first();
        $contractorId = $firstCandidate->contractor_id;
        $contractor = $firstCandidate->contractor;

        // 納品先別分割の場合は単一倉庫、まとめる場合はNULL
        $warehouseId = $splitByWarehouse ? $firstCandidate->warehouse_id : null;
        $warehouse = $splitByWarehouse ? $firstCandidate->warehouse : null;

        // 入荷予定日（グループ内で最も早い日付）
        $expectedArrivalDate = $candidates->min('expected_arrival_date');

        // CSV生成
        $csvContent = $this->buildCsvContent($candidates);

        // S3に保存
        $date = now()->format('Y-m-d');
        $contractorCode = $contractor?->code ?? $contractorId;
        if ($splitByWarehouse) {
            $warehouseCode = $warehouse?->code ?? $warehouseId;
            $filename = "{$batchCode}_{$warehouseCode}_{$contractorCode}.csv";
        } else {
            $filename = "{$batchCode}_{$contractorCode}.csv";
        }
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
                'order_date' => ClientSetting::systemDateYMD(),
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
            'warehouse_name' => $splitByWarehouse ? $warehouse?->name : '全倉庫',
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
        // 候補IDから入荷予定レコードを取得（伝票番号・単価情報用）
        $candidateIds = $candidates->pluck('id')->toArray();
        $incomingSchedules = WmsOrderIncomingSchedule::whereIn('order_candidate_id', $candidateIds)
            ->get()
            ->keyBy('order_candidate_id');
        $slipNumberMap = $incomingSchedules->pluck('slip_number', 'order_candidate_id')->toArray();

        $headers = [
            // 伝票ヘッダー
            '伝票番号',
            '明細行',
            '発注日',
            '入荷予定日',
            '倉庫コード',
            '倉庫名',
            '発注先コード',
            '発注先名',
            // 明細
            '商品コード',
            '商品名',
            '規格',
            '発注コード',
            '発注数量',
            '単位',
            '単価',
        ];

        $rows = [];
        $rows[] = $headers;

        // 伝票番号順でソート
        $sortedCandidates = $candidates->sortBy(function ($candidate) use ($slipNumberMap) {
            return $slipNumberMap[$candidate->id] ?? '';
        });

        // 明細行番号をトラック（伝票ごと）
        $lineNumbers = [];

        foreach ($sortedCandidates as $candidate) {
            $slipNumber = $slipNumberMap[$candidate->id] ?? '';

            // 伝票内の明細行番号
            if (! isset($lineNumbers[$slipNumber])) {
                $lineNumbers[$slipNumber] = 0;
            }
            $lineNumbers[$slipNumber]++;

            // 単位の日本語表記
            $quantityType = $candidate->quantity_type;
            $unitLabel = match ($quantityType?->value ?? $quantityType) {
                'CASE' => 'ケース',
                'CARTON' => 'ボール',
                default => 'バラ',
            };

            // 単価（ケース/バラに合わせて）
            $schedule = $incomingSchedules[$candidate->id] ?? null;
            $unitPrice = '';
            if ($schedule) {
                $unitPrice = match ($schedule->price_type) {
                    'CASE' => $schedule->case_price,
                    default => $schedule->unit_price,
                };
                $unitPrice = $unitPrice !== null ? (float) $unitPrice : '';
            }

            $rows[] = [
                // 伝票ヘッダー（明細分重複）
                $slipNumber,
                $lineNumbers[$slipNumber],
                now()->format('Y-m-d'),
                $candidate->expected_arrival_date?->format('Y-m-d') ?? '',
                $candidate->warehouse?->code ?? '',
                $candidate->warehouse?->name ?? '',
                $candidate->contractor?->code ?? '',
                $candidate->contractor?->name ?? '',
                // 明細
                $candidate->item?->code ?? '',
                $candidate->item?->name ?? '',
                $candidate->item?->packaging ?? '',
                $candidate->ordering_code ?? '',
                $candidate->order_quantity,
                $unitLabel,
                $unitPrice,
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
