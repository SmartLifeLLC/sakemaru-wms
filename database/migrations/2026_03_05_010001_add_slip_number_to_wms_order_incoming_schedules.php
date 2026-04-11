<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->string('slip_number', 20)->nullable()->after('order_source');
            $table->unique('slip_number');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->dropUnique(['slip_number']);
            $table->dropColumn('slip_number');
        });
    }
};
