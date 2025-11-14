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
        Schema::connection('sakemaru')->table('wms_route_calculation_logs', function (Blueprint $table) {
            // Change total_distance from signed integer to unsigned big integer
            // This allows values from 0 to 18,446,744,073,709,551,615
            $table->unsignedBigInteger('total_distance')->comment('総移動距離 (px)')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_route_calculation_logs', function (Blueprint $table) {
            // Revert back to signed integer
            $table->integer('total_distance')->comment('総移動距離 (px)')->change();
        });
    }
};
