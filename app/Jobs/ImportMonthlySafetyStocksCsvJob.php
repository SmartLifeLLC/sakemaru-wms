<?php

namespace App\Jobs;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsImportLog;
use App\Models\WmsMonthlySafetyStock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportMonthlySafetyStocksCsvJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800; // 30 minutes for large files

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $filePath,
        protected int $importLogId
    ) {
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // メモリ制限を解除（大容量ファイル対応）
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
            if (! empty($result['errors'])) {
                $message .= ' エラー: '.count($result['errors']).'件';
                Log::warning('月別発注点CSVインポートエラー', ['errors' => array_slice($result['errors'], 0, 100)]);
            }

            Log::info('月別発注点CSVインポート完了', [
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
            Log::error('月別発注点CSVインポート失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $importLog->markAsFailed($e->getMessage());
        } finally {
            // ファイル削除
            Storage::disk('local')->delete($this->filePath);
        }
    }

    /**
     * CSVファイルを処理
     */
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
        $warehouses = Warehouse::query()->pluck('id', 'code');
        $contractors = Contractor::query()->pluck('id', 'code');

        // チャンクサイズ
        $chunkSize = 1000;
        $chunks = array_chunk($lines, $chunkSize);
        $processedRows = 0;

        foreach ($chunks as $chunkIndex => $chunk) {
            DB::beginTransaction();

            try {
                foreach ($chunk as $lineIndex => $line) {
                    $lineNum = ($chunkIndex * $chunkSize) + $lineIndex;
                    $line = trim($line);

                    if (empty($line)) {
                        $processedRows++;

                        continue;
                    }

                    $cols = str_getcsv($line);

                    if (count($cols) < 5) {
                        $errors[] = '行'.($lineNum + 2).': カラム数が不足';
                        $processedRows++;

                        continue;
                    }

                    [$itemCode, $warehouseCode, $contractorCode, $month, $safetyStock] = $cols;

                    // マスタチェック
                    $itemId = $items[$itemCode] ?? null;
                    $warehouseId = $warehouses[$warehouseCode] ?? null;
                    $contractorId = $contractors[$contractorCode] ?? null;

                    if (! $itemId) {
                        $errors[] = '行'.($lineNum + 2).": 商品コード {$itemCode} が見つかりません";
                        $processedRows++;

                        continue;
                    }
                    if (! $warehouseId) {
                        $errors[] = '行'.($lineNum + 2).": 倉庫コード {$warehouseCode} が見つかりません";
                        $processedRows++;

                        continue;
                    }
                    if (! $contractorId) {
                        $errors[] = '行'.($lineNum + 2).": 発注先コード {$contractorCode} が見つかりません";
                        $processedRows++;

                        continue;
                    }

                    $month = (int) $month;
                    if ($month < 1 || $month > 12) {
                        $errors[] = '行'.($lineNum + 2).": 月が不正です ({$month})";
                        $processedRows++;

                        continue;
                    }

                    $safetyStock = (int) $safetyStock;
                    if ($safetyStock < 0) {
                        $errors[] = '行'.($lineNum + 2).': 発注点が負の値です';
                        $processedRows++;

                        continue;
                    }

                    // Upsert
                    WmsMonthlySafetyStock::updateOrCreate(
                        [
                            'item_id' => $itemId,
                            'warehouse_id' => $warehouseId,
                            'contractor_id' => $contractorId,
                            'month' => $month,
                        ],
                        [
                            'safety_stock' => $safetyStock,
                        ]
                    );

                    $imported++;
                    $processedRows++;
                }

                DB::commit();

                // プログレス更新
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
