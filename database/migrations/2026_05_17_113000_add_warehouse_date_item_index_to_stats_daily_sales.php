<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        DB::connection('sakemaru')->statement('
            CREATE INDEX stats_daily_iws_wh_date_item_index
            ON stats_item_warehouse_daily_sales (warehouse_id, business_date, item_id)
        ');
    }

    public function down(): void
    {
        DB::connection('sakemaru')->statement('
            DROP INDEX stats_daily_iws_wh_date_item_index
            ON stats_item_warehouse_daily_sales
        ');
    }
};
