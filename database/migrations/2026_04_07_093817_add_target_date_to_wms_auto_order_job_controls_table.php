<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_auto_order_job_controls', function (Blueprint $table) {
            $table->date('target_date')->nullable()->after('warehouse_id')->comment('対象営業日（システム日付）');
            $table->index('target_date');
        });

        // 既存データ: started_at の日付部分で埋める
        DB::connection('sakemaru')->table('wms_auto_order_job_controls')
            ->whereNull('target_date')
            ->whereNotNull('started_at')
            ->update(['target_date' => DB::raw('DATE(started_at)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_auto_order_job_controls', function (Blueprint $table) {
            $table->dropColumn('target_date');
        });
    }
};
