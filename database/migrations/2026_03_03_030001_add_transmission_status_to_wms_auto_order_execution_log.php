<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_auto_order_execution_log', function (Blueprint $table) {
            $table->string('transmission_status', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_auto_order_execution_log', function (Blueprint $table) {
            $table->dropColumn('transmission_status');
        });
    }
};
