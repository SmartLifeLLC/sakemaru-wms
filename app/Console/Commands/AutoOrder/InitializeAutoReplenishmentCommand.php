<?php

namespace App\Console\Commands\AutoOrder;

use App\Services\AutoReplenishment\ReleaseInitializer;
use Illuminate\Console\Command;

class InitializeAutoReplenishmentCommand extends Command
{
    protected $signature = 'wms:initialize-auto-replenishment {client : 対象顧客ID} {--apply : 初回の全OFF・数量リセットを実行}';

    protected $description = '自動発注v2初回リリースの設定初期化（一度だけ・変更前データ保持）';

    public function handle(ReleaseInitializer $initializer): int
    {
        if (! $this->option('apply')) {
            $this->warn('全倉庫をOFFにし、保持対象を除く商品発注先の共有数量を0に初期化します。華様の倉庫CD 91は数量を保持します。保守時間帯に --apply を指定してください。');

            return self::SUCCESS;
        }
        try {
            throw_unless((int) $this->argument('client') === app(\App\Services\AutoReplenishment\ReplenishmentGuard::class)->clientId(), \RuntimeException::class, '配備先の顧客IDと初期化対象が一致しません。');
            $this->line(json_encode($initializer->initialize((int) $this->argument('client')), JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
