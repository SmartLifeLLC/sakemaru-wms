<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoOrder\MultiEchelonCalculationService;
use App\Services\AutoOrder\StockSnapshotService;
use Illuminate\Console\Command;

class AutoOrderCalculateCommand extends Command
{
    protected $signature = 'wms:auto-order-calculate
                            {--skip-snapshot : スナップショット生成をスキップ}';

    protected $description = '自動発注計算を実行（Multi-Echelon対応）';

    public function handle(
        StockSnapshotService $snapshotService,
        MultiEchelonCalculationService $calculationService
    ): int {
        $skipSnapshot = $this->option('skip-snapshot');

        $this->info('=== 自動発注計算開始 ===');
        $this->newLine();

        try {
            // Phase 0: スナップショット生成
            if (!$skipSnapshot) {
                $this->info('Phase 0: 在庫スナップショット生成...');
                $job = $snapshotService->generateAll();
                $this->info("  完了: {$job->processed_records}件");
                $this->newLine();
            }

            // Phase 1: 多段階計算（Level 0 → Level N）
            $this->info('Phase 1: 多段階供給計算 (Multi-Echelon)...');
            $job = $calculationService->calculateAll();
            $this->info("  完了: {$job->processed_records}件 (バッチ: {$job->batch_code})");
            $this->newLine();

            $this->info('=== 自動発注計算完了 ===');

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('エラーが発生しました: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
