<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->table('stats_item_warehouse_daily_sales', function (Blueprint $table) {
            $table->integer('sales_piece_qty')->default(0)->after('shipped_piece_qty')->comment('販売バラ数');
            $table->integer('transfer_piece_qty')->default(0)->after('sales_piece_qty')->comment('移動バラ数');
            $table->integer('return_piece_qty')->default(0)->after('transfer_piece_qty')->comment('返品・戻しバラ数');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('stats_item_warehouse_daily_sales', function (Blueprint $table) {
            $table->dropColumn([
                'sales_piece_qty',
                'transfer_piece_qty',
                'return_piece_qty',
            ]);
        });
    }
};
