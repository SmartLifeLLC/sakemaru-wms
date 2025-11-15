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
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->integer('distance_from_previous')->nullable()->after('walking_order')
                ->comment('A*経路で計算した前のピッキングポイントからの距離（ピクセル単位）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropColumn('distance_from_previous');
        });
    }
};
