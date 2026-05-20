<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Enums\EVolumeUnit;
use App\Models\Sakemaru\ClientSetting;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    public function generateCsvFiles(string $batchCode, bool $splitByWarehouse = true, ?int $warehouseId = null): array
    {
        $shouldSplitByWarehouse = $splitByWarehouse || $warehouseId !== null;

        // CONFIRMED状態の発注候補を取得
        $query = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::CONFIRMED)
            ->with(['warehouse', 'item', 'contractor']);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            return [
                'success' => true,
                'files' => [],
                'total_files' => 0,
                'errors' => [],
                'message' => '生成対象の発注候補がありません',
            ];
        }

        // グルーピング: FAX/MAIL/CSVのヘッダー日付が混在しないよう、入荷予定日単位でも分割する
        $grouped = $this->groupCandidatesForDataFiles($candidates, $shouldSplitByWarehouse);

        $results = [];
        $errors = [];

        foreach ($grouped as $groupKey => $groupCandidates) {
            try {
                $result = $this->generateCsvFile($batchCode, $groupCandidates, $shouldSplitByWarehouse);
                $results[] = $result;

                Log::info('Order data CSV file generated', [
                    'batch_code' => $batchCode,
                    'warehouse_id' => $shouldSplitByWarehouse ? $groupCandidates->first()->warehouse_id : null,
                    'contractor_id' => $groupCandidates->first()->contractor_id,
                    'expected_arrival_date' => $groupCandidates->first()->expected_arrival_date?->format('Y-m-d'),
                    'order_count' => $groupCandidates->count(),
                    'split_by_warehouse' => $shouldSplitByWarehouse,
                    'requested_warehouse_id' => $warehouseId,
                ]);
            } catch (\Throwable $e) {
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
     * 選択された確定済み発注候補からFAX/MAIL/CSV用の発注データを生成する。
     *
     * @param  array<int>  $candidateIds
     * @return array{success: bool, files: array, total_files: int, errors: array}
     */
    public function generateCsvFilesForCandidates(array $candidateIds, bool $splitByWarehouse = true): array
    {
        $candidates = WmsOrderCandidate::whereIn('id', $candidateIds)
            ->where('status', CandidateStatus::CONFIRMED)
            ->with(['warehouse', 'item', 'contractor'])
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'success' => true,
                'files' => [],
                'total_files' => 0,
                'errors' => [],
                'message' => '生成対象の確定済み発注候補がありません',
            ];
        }

        $results = [];
        $errors = [];

        foreach ($candidates->groupBy('batch_code') as $batchCode => $batchCandidates) {
            $grouped = $this->groupCandidatesForDataFiles($batchCandidates, $splitByWarehouse);

            foreach ($grouped as $groupKey => $groupCandidates) {
                try {
                    $results[] = $this->generateCsvFile((string) $batchCode, $groupCandidates, $splitByWarehouse);
                } catch (\Throwable $e) {
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

        // 入荷予定日（グループ化済みのため同一日付）
        $expectedArrivalDate = $firstCandidate->expected_arrival_date;

        $quantityResolver = app(OrderOutputQuantityResolver::class);

        // CSV生成
        $csvContent = $this->buildCsvContent($candidates, $quantityResolver);

        // S3に保存
        $date = now()->format('Y-m-d');
        $contractorCode = $contractor?->code ?? $contractorId;
        if ($splitByWarehouse) {
            $warehouseCode = $warehouse?->code ?? $warehouseId;
            $filename = "{$batchCode}_{$warehouseCode}_{$contractorCode}_".now()->format('YmdHisv').'_'.Str::random(6).'.csv';
        } else {
            $filename = "{$batchCode}_{$contractorCode}_".now()->format('YmdHisv').'_'.Str::random(6).'.csv';
        }
        $filePath = "order-data-files/{$date}/{$filename}";

        Storage::disk('s3')->put($filePath, $csvContent);

        // 合計数量を計算
        $totalQuantity = $quantityResolver->sumOutputOrderQuantity($candidates);

        // DBに記録
        $dataFile = WmsOrderDataFile::create([
            'batch_code' => $batchCode,
            'created_by_name' => $this->resolveCreatedByName($batchCode),
            'warehouse_id' => $warehouseId,
            'contractor_id' => $contractorId,
            'candidate_ids' => $candidates->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'order_date' => ClientSetting::systemDateYMD(),
            'expected_arrival_date' => $expectedArrivalDate,
            'file_path' => $filePath,
            'file_size' => strlen($csvContent),
            'order_count' => $candidates->count(),
            'total_quantity' => $totalQuantity,
            'is_test' => false,
            'status' => OrderDataFileStatus::GENERATED,
        ]);

        $faxError = null;
        if ($warehouseId !== null) {
            try {
                app(PurchaseOrderPdfService::class)->generateAndStoreFromCandidates($candidates, $dataFile);
                $dataFile->refresh();
            } catch (\Throwable $e) {
                $faxError = $e->getMessage();
                Log::error('Order data FAX PDF generation failed', [
                    'batch_code' => $batchCode,
                    'warehouse_id' => $warehouseId,
                    'contractor_id' => $contractorId,
                    'candidate_ids' => $candidates->pluck('id')->all(),
                    'error' => $faxError,
                ]);
            }
        }

        return [
            'id' => $dataFile->id,
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $splitByWarehouse ? $warehouse?->name : '全倉庫',
            'contractor_id' => $contractorId,
            'contractor_name' => $contractor?->name,
            'expected_arrival_date' => $expectedArrivalDate?->format('Y-m-d'),
            'file_path' => $filePath,
            'fax_file_path' => $dataFile->fax_file_path,
            'fax_error' => $faxError,
            'order_count' => $candidates->count(),
            'total_quantity' => $totalQuantity,
        ];
    }

    private function groupCandidatesForDataFiles(Collection $candidates, bool $splitByWarehouse): Collection
    {
        return $candidates->groupBy(fn (WmsOrderCandidate $candidate): string => $this->dataFileGroupKey($candidate, $splitByWarehouse));
    }

    private function dataFileGroupKey(WmsOrderCandidate $candidate, bool $splitByWarehouse): string
    {
        $supplierId = $candidate->supplier_id ?? 'no-supplier';
        $arrivalDate = $candidate->expected_arrival_date?->format('Y-m-d') ?? 'no-date';

        return $splitByWarehouse
            ? "{$candidate->warehouse_id}_{$candidate->contractor_id}_{$supplierId}_{$arrivalDate}"
            : "{$candidate->contractor_id}_{$supplierId}_{$arrivalDate}";
    }

    private function resolveCreatedByName(string $batchCode): ?string
    {
        return WmsAutoOrderJobControl::query()
            ->where('batch_code', $batchCode)
            ->whereNotNull('created_by')
            ->with('createdByUser:id,name')
            ->latest('id')
            ->first()
            ?->createdByUser
            ?->name;
    }

    /**
     * CSV内容を生成
     *
     * 伝票ヘッダー情報と明細情報が1行に表示される形式
     * （ヘッダーは明細分重複表示される）
     */
    private function buildCsvContent(Collection $candidates, OrderOutputQuantityResolver $quantityResolver): string
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
            '容量',
            '発注コード',
            '発注単位',
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

            $outputQuantity = $quantityResolver->resolve($candidate);

            // 単価（ケース/バラに合わせて）
            $schedule = $incomingSchedules[$candidate->id] ?? null;
            $unitPrice = $quantityResolver->resolveUnitPrice($candidate, $schedule);

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
                $this->formatVolume($candidate->item),
                $outputQuantity['ordering_code'] ?? '',
                $outputQuantity['display_capacity'] ?? '',
                $outputQuantity['order_quantity'],
                $outputQuantity['unit_label'],
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

    private function formatVolume($item): string
    {
        if (! $item || ! $item->volume || ! $item->volume_unit) {
            return '';
        }

        $unit = EVolumeUnit::tryFrom($item->volume_unit);

        return $unit ? $item->volume.$unit->name() : '';
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
