<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoOrder\StockSnapshotService;
use Illuminate\Console\Command;

class SnapshotStocksCommand extends Command
{
    protected $signature = 'wms:snapshot-stocks';

    protected $description = '自動発注用の在庫スナップショットを生成';

    public function handle(StockSnapshotService $service): int
    {
        $this->info('在庫スナップショット生成を開始します...');

        try {
            $job = $service->generateAll();

            $this->info("完了しました。バッチコード: {$job->batch_code}");
            $this->info("処理件数: {$job->processed_records}");

            return self::SUCCESS;

        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;

        } catch (\Exception $e) {
            $this->error('エラーが発生しました: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
