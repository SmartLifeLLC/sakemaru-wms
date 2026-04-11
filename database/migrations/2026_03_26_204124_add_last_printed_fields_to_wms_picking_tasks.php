<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            $table->timestamp('last_printed_at')->nullable()->after('print_requested_count');
            $table->unsignedBigInteger('last_printed_by')->nullable()->after('last_printed_at');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            $table->dropColumn(['last_printed_at', 'last_printed_by']);
        });
    }
};
