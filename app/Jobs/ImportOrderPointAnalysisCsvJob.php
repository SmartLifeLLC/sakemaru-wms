<?php

namespace App\Jobs;

use App\Models\Sakemaru\Item;
use App\Models\WmsImportLog;
use App\Models\WmsMonthlySafetyStock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * HanaDB発注点分析CSVをインポートするジョブ
 *
 * CSVフォーマット:
 * store_code,item_code,avg_daily_sales,std_daily_sales,avg_daily_orders,
 * lead_time_days,safety_stock,order_point,total_sales_qty_2y,total_order_qty_2y,sales_days_count
 */
class ImportOrderPointAnalysisCsvJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800; // 30 minutes for large files

    public function __construct(
        protected string $filePath,
        protected int $importLogId,
        protected int $warehouseId,
        protected array $months,
        protected string $valueColumn,
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

        Log::info('発注点分析CSVインポート開始', [
            'file_path' => $this->filePath,
            'import_log_id' => $this->importLogId,
            'warehouse_id' => $this->warehouseId,
            'months' => $this->months,
            'value_column' => $this->valueColumn,
        ]);

        $fullPath = Storage::disk('local')->path($this->filePath);

        if (! file_exists($fullPath)) {
            $importLog->markAsFailed('ファイルが見つかりません');

            return;
        }

        try {
            $result = $this->processFile($fullPath, $importLog);

            $message = "{$result['imported']}件をインポートしました。";
            if (! empty($result['errors'])) {
                $message .= ' エラー: '.count($result['errors']).'件';
                Log::warning('発注点分析CSVインポートエラー', ['errors' => array_slice($result['errors'], 0, 100)]);
            }

            Log::info('発注点分析CSVインポート完了', [
                'imported' => $result['imported'],
                'errors' => count($result['errors']),
            ]);

            $importLog->markAsCompleted(
                $result['imported'],
                count($result['errors']),
                $result['errors'],
                $message
            );

        } catch (\Exception $e) {
            Log::error('発注点分析CSVインポート失敗', [
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

        $imported = 0;
        $errors = [];

        // マスタデータをキャッシュ
        $items = Item::query()->pluck('id', 'code');

        // item_contractors: (item_id, warehouse_id) → contractor_id[]
        $itemContractors = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $this->warehouseId)
            ->get(['item_id', 'contractor_id'])
            ->groupBy('item_id');

        // 値カラムのインデックス（order_point=7, safety_stock=6）
        $valueIndex = $this->valueColumn === 'order_point' ? 7 : 6;

        // チャンク処理
        $chunkSize = 1000;
        $chunks = array_chunk($lines, $chunkSize);
        $processedRows = 0;
        $upsertBatchSize = 500;

        foreach ($chunks as $chunkIndex => $chunk) {
            DB::beginTransaction();

            try {
                $upsertBuffer = [];

                foreach ($chunk as $lineIndex => $line) {
                    $lineNum = ($chunkIndex * $chunkSize) + $lineIndex;
                    $line = trim($line);

                    if (empty($line)) {
                        $processedRows++;

                        continue;
                    }

                    $cols = str_getcsv($line);

                    if (count($cols) < 8) {
                        $errors[] = '行'.($lineNum + 2).': カラム数が不足（8列以上必要）';
                        $processedRows++;

                        continue;
                    }

                    $itemCode = trim($cols[1]);
                    $itemId = $items[$itemCode] ?? null;

                    if (! $itemId) {
                        $errors[] = '行'.($lineNum + 2).": 商品コード {$itemCode} が見つかりません";
                        $processedRows++;

                        continue;
                    }

                    $contractorRows = $itemContractors[$itemId] ?? collect();

                    if ($contractorRows->isEmpty()) {
                        $errors[] = '行'.($lineNum + 2).": 商品 {$itemCode} の発注先が未設定です（倉庫ID: {$this->warehouseId}）";
                        $processedRows++;

                        continue;
                    }

                    $rawValue = (float) $cols[$valueIndex];
                    $value = max(0, (int) round($rawValue));
                    $now = now()->format('Y-m-d H:i:s');

                    foreach ($contractorRows as $row) {
                        foreach ($this->months as $month) {
                            $upsertBuffer[] = [
                                'item_id' => $itemId,
                                'warehouse_id' => $this->warehouseId,
                                'contractor_id' => $row->contractor_id,
                                'month' => $month,
                                'safety_stock' => $value,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];

                            $imported++;
                        }
                    }

                    // バッチupsert
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

                // 残りをupsert
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
            'errors' => $errors,
        ];
    }
}
