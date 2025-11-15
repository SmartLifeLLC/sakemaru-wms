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
        Schema::connection('sakemaru')->table('wms_warehouse_layouts', function (Blueprint $table) {
            $table->integer('picking_start_x')->default(0)->comment('Picking start point X coordinate')->after('fixed_areas');
            $table->integer('picking_start_y')->default(0)->comment('Picking start point Y coordinate')->after('picking_start_x');
            $table->integer('picking_end_x')->default(0)->comment('Picking end point X coordinate')->after('picking_start_y');
            $table->integer('picking_end_y')->default(0)->comment('Picking end point Y coordinate')->after('picking_end_x');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_warehouse_layouts', function (Blueprint $table) {
            $table->dropColumn(['picking_start_x', 'picking_start_y', 'picking_end_x', 'picking_end_y']);
        });
    }
};
