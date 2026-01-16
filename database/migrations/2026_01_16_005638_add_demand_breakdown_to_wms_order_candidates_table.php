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
        Schema::connection($this->connection)->table('wms_order_candidates', function (Blueprint $table) {
            $table->json('demand_breakdown')->nullable()->after('satellite_demand_qty')
                ->comment('需要内訳JSON [{warehouse_id, quantity}, ...]');
            $table->string('origin_warehouse_ids', 500)->nullable()->after('demand_breakdown')
                ->comment('需要元倉庫ID（カンマ区切り、検索用）');

            $table->index('origin_warehouse_ids', 'idx_origin_warehouse_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_candidates', function (Blueprint $table) {
            $table->dropIndex('idx_origin_warehouse_ids');
            $table->dropColumn(['demand_breakdown', 'origin_warehouse_ids']);
        });
    }
};
