<?php

namespace App\Console\Commands\Stats;

use App\Models\StatsItemWarehouseSalesSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSalesSummariesCommand extends Command
{
    protected $signature = 'wms:sync-sales-summaries
        {--warehouse-id= : 特定倉庫のみ集計}
        {--dry-run : 実際の書き込みなしに集計結果を表示}';

    protected $description = 'stats_item_warehouse_daily_salesからsummariesを集計';

    public function handle(): int
    {
        $warehouseId = $this->option('warehouse-id');
        $dryRun = $this->option('dry-run');

        $this->info('出荷実績サマリ集計を開始します...');

        $query = DB::connection('sakemaru')
            ->table('stats_item_warehouse_daily_sales')
            ->select([
                'warehouse_id',
                'item_id',
                DB::raw("SUM(CASE WHEN business_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY) THEN shipped_piece_qty ELSE 0 END) AS last_3d_qty"),
                DB::raw("SUM(CASE WHEN business_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN shipped_piece_qty ELSE 0 END) AS last_7d_qty"),
                DB::raw("SUM(CASE WHEN business_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN shipped_piece_qty ELSE 0 END) AS last_14d_qty"),
                DB::raw("SUM(shipped_piece_qty) AS last_30d_qty"),
                DB::raw("ROUND(SUM(CASE WHEN business_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY) THEN shipped_piece_qty ELSE 0 END) / 3, 2) AS avg_3d_qty"),
                DB::raw("ROUND(SUM(CASE WHEN business_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN shipped_piece_qty ELSE 0 END) / 7, 2) AS avg_7d_qty"),
                DB::raw("ROUND(SUM(CASE WHEN business_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN shipped_piece_qty ELSE 0 END) / 14, 2) AS avg_14d_qty"),
                DB::raw("ROUND(SUM(shipped_piece_qty) / 30, 2) AS avg_30d_qty"),
                DB::raw("MAX(CASE WHEN shipped_piece_qty > 0 THEN business_date ELSE NULL END) AS last_shipped_at"),
            ])
            ->where('business_date', '>=', DB::raw('DATE_SUB(CURDATE(), INTERVAL 30 DAY)'))
            ->groupBy('warehouse_id', 'item_id');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $results = $query->get();

        $this->info("集計対象: {$results->count()} 件（倉庫×商品）");

        if ($dryRun) {
            $this->table(
                ['倉庫ID', '商品ID', '3d', '7d', '14d', '30d', 'avg3d', 'avg7d', '最終出荷'],
                $results->take(20)->map(fn ($r) => [
                    $r->warehouse_id, $r->item_id,
                    $r->last_3d_qty, $r->last_7d_qty, $r->last_14d_qty, $r->last_30d_qty,
                    $r->avg_3d_qty, $r->avg_7d_qty, $r->last_shipped_at,
                ])
            );
            $this->info('[DRY RUN] 書き込みはスキップしました。');

            return self::SUCCESS;
        }

        $now = now();
        $upsertData = $results->map(fn ($r) => [
            'warehouse_id' => $r->warehouse_id,
            'item_id' => $r->item_id,
            'last_3d_qty' => $r->last_3d_qty,
            'last_7d_qty' => $r->last_7d_qty,
            'last_14d_qty' => $r->last_14d_qty,
            'last_30d_qty' => $r->last_30d_qty,
            'avg_3d_qty' => $r->avg_3d_qty,
            'avg_7d_qty' => $r->avg_7d_qty,
            'avg_14d_qty' => $r->avg_14d_qty,
            'avg_30d_qty' => $r->avg_30d_qty,
            'last_shipped_at' => $r->last_shipped_at,
            'calculated_at' => $now,
            'updated_at' => $now,
        ])->toArray();

        // バッチupsert（1000件ずつ）
        $chunks = array_chunk($upsertData, 1000);
        $totalUpserted = 0;

        foreach ($chunks as $chunk) {
            StatsItemWarehouseSalesSummary::upsert(
                $chunk,
                ['warehouse_id', 'item_id'],
                [
                    'last_3d_qty', 'last_7d_qty', 'last_14d_qty', 'last_30d_qty',
                    'avg_3d_qty', 'avg_7d_qty', 'avg_14d_qty', 'avg_30d_qty',
                    'last_shipped_at', 'calculated_at', 'updated_at',
                ]
            );
            $totalUpserted += count($chunk);
        }

        $this->info("完了: {$totalUpserted} 件を upsert しました。");

        return self::SUCCESS;
    }
}
