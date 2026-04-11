<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoOrder\OrderCandidateCalculationService;
use App\Services\AutoOrder\OrderValidationService;
use Illuminate\Console\Command;

class AutoOrderCalculateCommand extends Command
{
    protected $signature = 'wms:auto-order-calculate
                            {--force : 確定済み候補がある場合も強制実行}';

    protected $description = '自動発注計算を実行';

    public function handle(
        OrderCandidateCalculationService $calculationService,
        OrderValidationService $validationService
    ): int {
        $force = $this->option('force');

        $this->info('=== 自動発注計算開始 ===');
        $this->newLine();

        try {
            // Pre-check: 再計算可能かチェック
            $this->info('再計算前チェック...');
            $validation = $validationService->validateForRecalculation();

            if (! $validation['valid']) {
                $this->warn($validation['message']);

                if (! empty($validation['details'])) {
                    $this->table(
                        ['ステータス', '件数'],
                        [
                            ['承認前 (PENDING)', $validation['details']['pending_count'] ?? 0],
                            ['承認済 (APPROVED)', $validation['details']['approved_count'] ?? 0],
                            ['確定済 (CONFIRMED)', $validation['details']['confirmed_count'] ?? 0],
                        ]
                    );
                }

                if (! $force) {
                    $this->error('再計算を中止しました。--force オプションで強制実行できます。');

                    return self::FAILURE;
                }

                $this->warn('--force オプションが指定されたため、強制実行します。');
            } else {
                $this->info('  チェック完了: '.$validation['message']);
            }
            $this->newLine();

            // 発注候補計算（在庫はwms_v_stock_availableから直接読み込み）
            $this->info('発注候補計算...');
            $job = $calculationService->calculate();
            $this->info("  完了: {$job->processed_records}件 (バッチ: {$job->batch_code})");
            $this->newLine();

            $this->info('=== 自動発注計算完了 ===');

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('エラーが発生しました: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
