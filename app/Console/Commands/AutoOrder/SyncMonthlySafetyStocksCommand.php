<?php

namespace App\Console\Commands\AutoOrder;

use App\Models\WmsMonthlySafetyStock;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 月別安全在庫の同期コマンド
 *
 * 月別安全在庫テーブルから該当月のデータを取得し、
 * item_contractors.safety_stock に反映する。
 */
class SyncMonthlySafetyStocksCommand extends Command
{
    protected $signature = 'wms:sync-monthly-safety-stocks
                            {--month= : 対象月 (1-12)。省略時は現在の月}
                            {--dry-run : 実際の更新を行わず、対象件数のみ表示}';

    protected $description = '月別安全在庫設定を item_contractors に同期';

    public function handle(): int
    {
        $month = $this->option('month') ?? Carbon::now()->month;
        $dryRun = $this->option('dry-run');

        $this->info("月別安全在庫の同期を開始します... (対象月: {$month}月)");

        if ($dryRun) {
            $this->warn('--dry-run モード: 実際の更新は行いません');
        }

        try {
            // 該当月のデータを取得
            $monthlySafetyStocks = WmsMonthlySafetyStock::forMonth($month)
                ->with(['item', 'warehouse', 'contractor'])
                ->get();

            if ($monthlySafetyStocks->isEmpty()) {
                $this->warn("対象月({$month}月)のデータがありません。処理をスキップします。");

                return self::SUCCESS;
            }

            $this->info("対象件数: {$monthlySafetyStocks->count()}件");

            $updatedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            $this->withProgressBar($monthlySafetyStocks, function ($monthlySafetyStock) use ($dryRun, &$updatedCount, &$skippedCount, &$errorCount) {
                try {
                    // 対応する item_contractor を検索（自動更新フラグがtrueのもののみ）
                    $itemContractor = DB::connection('sakemaru')
                        ->table('item_contractors')
                        ->where('item_id', $monthlySafetyStock->item_id)
                        ->where('warehouse_id', $monthlySafetyStock->warehouse_id)
                        ->where('contractor_id', $monthlySafetyStock->contractor_id)
                        ->first();

                    if (! $itemContractor) {
                        $skippedCount++;

                        return;
                    }

                    // 自動更新フラグがfalseの場合はスキップ
                    if (! $itemContractor->use_safety_stock_auto_update) {
                        $skippedCount++;

                        return;
                    }

                    if (! $dryRun) {
                        DB::connection('sakemaru')
                            ->table('item_contractors')
                            ->where('id', $itemContractor->id)
                            ->update([
                                'safety_stock' => $monthlySafetyStock->safety_stock,
                                'updated_at' => now(),
                            ]);
                    }

                    $updatedCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    Log::error('月別安全在庫同期エラー', [
                        'item_id' => $monthlySafetyStock->item_id,
                        'warehouse_id' => $monthlySafetyStock->warehouse_id,
                        'contractor_id' => $monthlySafetyStock->contractor_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

            $this->newLine(2);

            // 結果をテーブル形式で表示
            $this->table(
                ['項目', '件数'],
                [
                    ['更新成功', $updatedCount],
                    ['対象なし（スキップ）', $skippedCount],
                    ['エラー', $errorCount],
                ]
            );

            // ログ出力
            Log::info('月別安全在庫同期完了', [
                'month' => $month,
                'dry_run' => $dryRun,
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
                'errors' => $errorCount,
            ]);

            if ($dryRun) {
                $this->info('--dry-run モードのため、実際の更新は行われていません');
            } else {
                $this->info('同期が完了しました。');
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('エラーが発生しました: ' . $e->getMessage());
            Log::error('月別安全在庫同期エラー', [
                'month' => $month,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
