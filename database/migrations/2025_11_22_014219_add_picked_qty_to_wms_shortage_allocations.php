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
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->integer('picked_qty')->default(0)->after('assign_qty')->comment('ピッキング済み数量');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->dropColumn('picked_qty');
        });
    }
};
