<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_auto_order_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('policy_max_id')->default(0);
        });
        Schema::connection('sakemaru')->table('wms_auto_order_run_lines', function (Blueprint $table) {
            $table->index(['client_id', 'updated_at', 'id'], 'wms_auto_line_reconciliation');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_auto_order_runs', function (Blueprint $table) {
            $table->dropColumn('policy_max_id');
        });
        Schema::connection('sakemaru')->table('wms_auto_order_run_lines', function (Blueprint $table) {
            $table->dropIndex('wms_auto_line_reconciliation');
        });
    }
};
