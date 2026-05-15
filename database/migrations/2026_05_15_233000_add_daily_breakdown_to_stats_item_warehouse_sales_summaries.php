<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('stats_item_warehouse_sales_summaries', function (Blueprint $table) {
            $table->integer('sales_today_qty')->default(0)->after('item_id')->comment('当日出荷バラ数量');
            $table->integer('sales_yesterday_qty')->default(0)->after('sales_today_qty')->comment('前日出荷バラ数量');
            $table->integer('sales_2days_ago_qty')->default(0)->after('sales_yesterday_qty')->comment('前々日出荷バラ数量');
            $table->integer('last_5d_qty')->default(0)->after('last_3d_qty')->comment('直近5日合計バラ数量');
            $table->decimal('avg_5d_qty', 10, 2)->default(0)->after('avg_3d_qty')->comment('直近5日平均バラ数量');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('stats_item_warehouse_sales_summaries', function (Blueprint $table) {
            $table->dropColumn([
                'sales_today_qty',
                'sales_yesterday_qty',
                'sales_2days_ago_qty',
                'last_5d_qty',
                'avg_5d_qty',
            ]);
        });
    }
};
