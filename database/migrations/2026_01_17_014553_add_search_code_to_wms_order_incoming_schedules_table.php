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
        Schema::connection($this->connection)->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->string('search_code', 500)->nullable()->after('item_id')->comment('検索コード（JAN等カンマ区切り）');
            $table->index('search_code', 'idx_search_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->dropIndex('idx_search_code');
            $table->dropColumn('search_code');
        });
    }
};
