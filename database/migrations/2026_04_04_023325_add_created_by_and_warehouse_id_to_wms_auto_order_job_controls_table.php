<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_auto_order_job_controls', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('settlement_status');
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_auto_order_job_controls', function (Blueprint $table) {
            $table->dropColumn(['created_by', 'warehouse_id']);
        });
    }
};
