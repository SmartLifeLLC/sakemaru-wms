<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wms_item_stock_snapshots', function (Blueprint $table) {
            // 既存のユニークキーを削除
            $table->dropUnique('uk_warehouse_item');

            // job_control_idを含む新しいユニークキーを追加
            $table->unique(['job_control_id', 'warehouse_id', 'item_id'], 'uk_job_warehouse_item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wms_item_stock_snapshots', function (Blueprint $table) {
            // 新しいユニークキーを削除
            $table->dropUnique('uk_job_warehouse_item');

            // 元のユニークキーを復元
            $table->unique(['warehouse_id', 'item_id'], 'uk_warehouse_item');
        });
    }
};
