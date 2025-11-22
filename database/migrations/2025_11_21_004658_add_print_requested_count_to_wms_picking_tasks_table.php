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
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            $table->integer('print_requested_count')->default(0)->after('completed_at')
                ->comment('伝票印刷依頼回数');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            $table->dropColumn('print_requested_count');
        });
    }
};
