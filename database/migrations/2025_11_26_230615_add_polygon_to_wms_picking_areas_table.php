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
        Schema::connection('sakemaru')->table('wms_picking_areas', function (Blueprint $table) {
            $table->json('polygon')->nullable()->after('name')->comment('Polygon coordinates for the area');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_areas', function (Blueprint $table) {
            $table->dropColumn('polygon');
        });
    }
};
