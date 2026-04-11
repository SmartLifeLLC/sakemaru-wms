<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * stats_item_warehouse_daily_sales: id削除 → 複合PK(business_date, warehouse_id, item_id)
     * stats_item_warehouse_sales_summaries: id削除 → 複合PK(warehouse_id, item_id)
     * 冗長インデックス削除で約1.1GB削減見込み
     */
    public function up(): void
    {
        // --- stats_item_warehouse_daily_sales ---
        // 既存インデックス:
        //   PRIMARY(id), stats_daily_iws_date_wh_item_unique(business_date,warehouse_id,item_id),
        //   stats_daily_iws_wh_item_date_index(warehouse_id,item_id,business_date),
        //   stats_daily_iws_date_index(business_date), stats_daily_iws_item_index(item_id)
        DB::connection('sakemaru')->statement('
            ALTER TABLE stats_item_warehouse_daily_sales
                DROP PRIMARY KEY,
                DROP COLUMN id,
                ADD PRIMARY KEY (business_date, warehouse_id, item_id),
                DROP INDEX stats_daily_iws_wh_item_date_index,
                DROP INDEX stats_daily_iws_date_index
        ');
        // 残すインデックス: stats_daily_iws_date_wh_item_unique(UNIQUE), stats_daily_iws_item_index(item_id)

        // --- stats_item_warehouse_sales_summaries ---
        // 既存インデックス:
        //   PRIMARY(id), stats_iws_summaries_wh_item_unique(warehouse_id,item_id),
        //   stats_iws_summaries_item_index(item_id),
        //   stats_iws_summaries_wh_7d_index, stats_iws_summaries_wh_30d_index
        DB::connection('sakemaru')->statement('
            ALTER TABLE stats_item_warehouse_sales_summaries
                DROP PRIMARY KEY,
                DROP COLUMN id,
                ADD PRIMARY KEY (warehouse_id, item_id),
                DROP INDEX stats_iws_summaries_wh_item_unique
        ');
        // 残すインデックス: stats_iws_summaries_item_index, stats_iws_summaries_wh_7d_index, stats_iws_summaries_wh_30d_index
    }

    public function down(): void
    {
        // --- stats_item_warehouse_daily_sales: id復元 ---
        DB::connection('sakemaru')->statement('
            ALTER TABLE stats_item_warehouse_daily_sales
                DROP PRIMARY KEY,
                ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
                ADD PRIMARY KEY (id),
                ADD INDEX stats_daily_iws_wh_item_date_index (warehouse_id, item_id, business_date),
                ADD INDEX stats_daily_iws_date_index (business_date)
        ');

        // --- stats_item_warehouse_sales_summaries: id復元 ---
        DB::connection('sakemaru')->statement('
            ALTER TABLE stats_item_warehouse_sales_summaries
                DROP PRIMARY KEY,
                ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
                ADD PRIMARY KEY (id),
                ADD UNIQUE KEY stats_iws_summaries_wh_item_unique (warehouse_id, item_id)
        ');
    }
};
