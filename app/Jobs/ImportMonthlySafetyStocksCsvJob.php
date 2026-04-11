<?php

namespace App\Jobs;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsImportLog;
use App\Models\WmsMonthlySafetyStock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 月別発注点CSVインポートJob
 *
 * monthly_order_points.csv 形式（14列）を処理する。
 * CSV: warehouse_code, item_code, month, ..., order_point_int(10列目)
 * contractor_id は item_contractors テーブルから (warehouse_id, item_id) で逆引き。
 */
class ImportMonthlySafetyStocksCsvJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        protected string $filePath,
        protected int $importLogId
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        ini_set('memory_limit', '-1');

        $importLog = WmsImportLog::find($this->importLogId);
        if (! $importLog) {
            Log::error('ImportLogが見つかりません', ['id' => $this->importLogId]);

            return;
        }

        $importLog->markAsProcessing();

        Log::info('月別発注点CSVインポート開始', [
            'file_path' => $this->filePath,
            'import_log_id' => $this->importLogId,
        ]);

        $fullPath = Storage::disk('local')->path($this->filePath);

        if (! file_exists($fullPath)) {
            $importLog->markAsFailed('ファイルが見つかりません');

            return;
        }

        try {
            $result = $this->processFile($fullPath, $importLog);

            $message = "{$result['imported']}件をインポートしました。";
            if ($result['skipped'] > 0) {
                $message .= " スキップ（マスタ不在）: {$result['skipped']}件";
            }
            if (! empty($result['errors'])) {
                $message .= ' エラー: '.count($result['errors']).'件';
                Log::warning('月別発注点CSVインポートエラー', ['errors' => array_slice($result['errors'], 0, 100)]);
            }

            Log::info('月別発注点CSVインポート完了', [
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'errors' => count($result['errors']),
            ]);

            $importLog->markAsCompleted(
                $result['imported'],
                count($result['errors']) + $result['skipped'],
                $result['errors'],
                $message
            );

        } catch (\Exception $e) {
            Log::error('月別発注点CSVインポート失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $importLog->markAsFailed($e->getMessage());
        } finally {
            Storage::disk('local')->delete($this->filePath);
        }
    }

    protected function processFile(string $fullPath, WmsImportLog $importLog): array
    {
        $content = file_get_contents($fullPath);

        // BOMを除去
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Shift-JISの場合はUTF-8に変換
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
        }

        $lines = array_filter(explode("\n", $content));

        if (count($lines) < 2) {
            throw new \RuntimeException('データがありません');
        }

        // ヘッダー行をスキップ
        array_shift($lines);

        $totalRows = count($lines);
        $importLog->update(['total_rows' => $totalRows]);

        // マスタデータをキャッシュ
        $items = Item::query()->pluck('id', 'code');
        $warehouses = Warehouse::query()->pluck('id', 'code');

        // item_contractors: warehouse_id → (item_id → [contractor_id, ...])
        $itemContractors = [];
        DB::connection('sakemaru')
            ->table('item_contractors')
            ->select(['item_id', 'warehouse_id', 'contractor_id'])
            ->orderBy('id')
            ->chunk(5000, function ($rows) use (&$itemContractors) {
                foreach ($rows as $row) {
                    $itemContractors[$row->warehouse_id][$row->item_id][] = $row->contractor_id;
                }
            });

        $imported = 0;
        $skipped = 0;
        $errors = [];

        $chunkSize = 1000;
        $upsertBatchSize = 500;
        $chunks = array_chunk($lines, $chunkSize);
        $processedRows = 0;

        foreach ($chunks as $chunkIndex => $chunk) {
            $upsertBuffer = [];

            DB::beginTransaction();

            try {
                foreach ($chunk as $lineIndex => $line) {
                    $lineNum = ($chunkIndex * $chunkSize) + $lineIndex;
                    $line = trim($line);

                    if (empty($line)) {
                        $processedRows++;

                        continue;
                    }

                    $cols = str_getcsv($line, ',', '"', '');

                    if (count($cols) < 10) {
                        $errors[] = '行'.($lineNum + 2).': カラム数が不足（10列以上必要）';
                        $processedRows++;

                        continue;
                    }

                    $warehouseCode = trim($cols[0]);
                    $itemCode = trim($cols[1]);
                    $month = (int) $cols[2];
                    $orderPointInt = max(0, (int) $cols[9]); // order_point_int

                    $warehouseId = $warehouses[$warehouseCode] ?? null;
                    $itemId = $items[$itemCode] ?? null;

                    if (! $warehouseId) {
                        $errors[] = '行'.($lineNum + 2).": 倉庫コード {$warehouseCode} が見つかりません";
                        $processedRows++;

                        continue;
                    }

                    if (! $itemId) {
                        $errors[] = '行'.($lineNum + 2).": 商品コード {$itemCode} が見つかりません";
                        $processedRows++;

                        continue;
                    }

                    if ($month < 1 || $month > 12) {
                        $errors[] = '行'.($lineNum + 2).": 月が不正です ({$month})";
                        $processedRows++;

                        continue;
                    }

                    $contractorIds = $itemContractors[$warehouseId][$itemId] ?? [];

                    if (empty($contractorIds)) {
                        $skipped++;
                        $processedRows++;

                        continue;
                    }

                    $now = now()->format('Y-m-d H:i:s');

                    foreach ($contractorIds as $contractorId) {
                        $upsertBuffer[] = [
                            'item_id' => $itemId,
                            'warehouse_id' => $warehouseId,
                            'contractor_id' => $contractorId,
                            'month' => $month,
                            'safety_stock' => $orderPointInt,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $imported++;
                    }

                    if (count($upsertBuffer) >= $upsertBatchSize) {
                        WmsMonthlySafetyStock::upsert(
                            $upsertBuffer,
                            ['item_id', 'warehouse_id', 'contractor_id', 'month'],
                            ['safety_stock', 'updated_at']
                        );
                        $upsertBuffer = [];
                    }

                    $processedRows++;
                }

                if (! empty($upsertBuffer)) {
                    WmsMonthlySafetyStock::upsert(
                        $upsertBuffer,
                        ['item_id', 'warehouse_id', 'contractor_id', 'month'],
                        ['safety_stock', 'updated_at']
                    );
                }

                DB::commit();

                $importLog->updateProgress($processedRows);

                Log::debug('チャンク処理完了', [
                    'chunk' => $chunkIndex + 1,
                    'total_chunks' => count($chunks),
                    'imported_so_far' => $imported,
                ]);

            } catch (\Exception $e) {
                DB::rollBack();

                throw $e;
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
