<?php

namespace App\Console\Commands;

use App\Services\Distribution\DistributionBatchService;
use Illuminate\Console\Command;

class MigrateDistributionBatches extends Command
{
    protected $signature = 'wms:migrate-distribution-batches {--execute : 初回分配への所属変更を実行する}';

    protected $description = '既存本部分配を初回分配へまとめます（既定は対象件数の確認のみ）';

    public function handle(DistributionBatchService $service): int
    {
        $groups = $service->migrateLegacy((bool) $this->option('execute'));
        $this->table(['顧客ID', '倉庫ID', '未所属行数'], $groups);
        $this->info($this->option('execute') ? '初回分配への所属を更新しました。' : '確認のみです。変更は行っていません。');

        return self::SUCCESS;
    }
}
