<?php

namespace App\Console\Commands\Stats;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillDailySalesBreakdownCommand extends Command
{
    protected $signature = 'wms:backfill-daily-sales-breakdown
        {--warehouse-id= : 特定倉庫のみ補正}
        {--from= : 対象開始日(Y-m-d)}
        {--to= : 対象終了日(Y-m-d)}
        {--chunk=50000 : 1回あたりの更新件数}
        {--dry-run : 実際の書き込みなしに件数だけ確認}';

    protected $description = 'stats_item_warehouse_daily_salesの既存行に販売/移動/返品内訳の暫定値を設定';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $conditions = [
            'sales_piece_qty = 0',
            'transfer_piece_qty = 0',
            'return_piece_qty = 0',
            'shipped_piece_qty <> 0',
        ];
        $bindings = [];

        if ($warehouseId = $this->option('warehouse-id')) {
            $conditions[] = 'warehouse_id = ?';
            $bindings[] = (int) $warehouseId;
        }

        if ($from = $this->option('from')) {
            $conditions[] = 'business_date >= ?';
            $bindings[] = $from;
        }

        if ($to = $this->option('to')) {
            $conditions[] = 'business_date <= ?';
            $bindings[] = $to;
        }

        $whereSql = implode(' AND ', $conditions);

        $targetCount = DB::connection('sakemaru')
            ->selectOne("SELECT COUNT(*) AS count FROM stats_item_warehouse_daily_sales WHERE {$whereSql}", $bindings)
            ?->count ?? 0;

        $this->line("対象件数: {$targetCount}");
        $this->line('補正内容: 正の合計は販売、負の合計は返品、移動は0として設定します。');

        if ($this->option('dry-run') || (int) $targetCount === 0) {
            return self::SUCCESS;
        }

        $totalUpdated = 0;
        do {
            $updateSql = "
                UPDATE stats_item_warehouse_daily_sales
                SET
                    sales_piece_qty = CASE WHEN shipped_piece_qty > 0 THEN shipped_piece_qty ELSE 0 END,
                    transfer_piece_qty = 0,
                    return_piece_qty = CASE WHEN shipped_piece_qty < 0 THEN -shipped_piece_qty ELSE 0 END,
                    updated_at = NOW()
                WHERE {$whereSql}
                LIMIT {$chunkSize}
            ";

            $updated = DB::connection('sakemaru')->update($updateSql, $bindings);
            $totalUpdated += $updated;
            $this->line("更新済み: {$totalUpdated}");
        } while ($updated === $chunkSize);

        $mismatchCount = DB::connection('sakemaru')
            ->selectOne('
                SELECT COUNT(*) AS count
                FROM stats_item_warehouse_daily_sales
                WHERE shipped_piece_qty <> sales_piece_qty + transfer_piece_qty - return_piece_qty
            ')
            ?->count ?? 0;

        if ((int) $mismatchCount > 0) {
            $this->error("内訳不一致が残っています: {$mismatchCount}");

            return self::FAILURE;
        }

        $this->info("補正完了: {$totalUpdated}件");

        return self::SUCCESS;
    }
}
