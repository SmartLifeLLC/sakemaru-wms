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
        // Check if column already exists
        if (!Schema::connection('sakemaru')->hasColumn('wms_picking_areas', 'color')) {
            Schema::connection('sakemaru')->table('wms_picking_areas', function (Blueprint $table) {
                $table->string('color')->nullable()->default('#8B5CF6')->after('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_areas', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
