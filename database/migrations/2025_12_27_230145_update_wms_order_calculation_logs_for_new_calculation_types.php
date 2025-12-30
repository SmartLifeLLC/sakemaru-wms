<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        // 既存データを削除（計算タイプが変わるため）
        DB::connection($this->connection)->table('wms_order_calculation_logs')->truncate();

        Schema::connection($this->connection)->table('wms_order_calculation_logs', function (Blueprint $table) {
            // calculation_type を INTERNAL/EXTERNAL に変更
            $table->dropColumn('calculation_type');
        });

        Schema::connection($this->connection)->table('wms_order_calculation_logs', function (Blueprint $table) {
            $table->enum('calculation_type', ['INTERNAL', 'EXTERNAL'])->after('item_id');
            $table->unsignedBigInteger('contractor_id')->nullable()->after('calculation_type');
            $table->unsignedBigInteger('source_warehouse_id')->nullable()->after('contractor_id')
                ->comment('移動元倉庫ID（INTERNAL時のみ）');

            $table->index(['batch_code', 'calculation_type']);
            $table->index(['warehouse_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_calculation_logs', function (Blueprint $table) {
            $table->dropIndex(['batch_code', 'calculation_type']);
            $table->dropIndex(['warehouse_id', 'item_id']);
            $table->dropColumn(['contractor_id', 'source_warehouse_id']);
            $table->dropColumn('calculation_type');
        });

        Schema::connection($this->connection)->table('wms_order_calculation_logs', function (Blueprint $table) {
            $table->enum('calculation_type', ['SATELLITE', 'HUB'])->after('item_id');
        });
    }
};
