<?php

use Archilex\AdvancedTables\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add missing columns to wms_filament_filter_sets
//        Schema::connection('sakemaru')->table('wms_filament_filter_sets', function (Blueprint $table) {
//            $table->string('color')->nullable()->after('indicators');
//            $table->string('icon')->nullable()->after('indicators');
//            $table->string('status')->after('is_global_favorite')->default('approved');
//            $table->integer(Config::getTenantColumn())->nullable()->after('user_id');
//        });
//
//        // Add is_visible column to wms_filament_filter_set_user
//        Schema::connection('sakemaru')->table('wms_filament_filter_set_user', function (Blueprint $table) {
//            $table->boolean('is_visible')->default(true);
//        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_filament_filter_sets', function (Blueprint $table) {
            $table->dropColumn(['color', 'icon', 'status', Config::getTenantColumn()]);
        });

        Schema::connection('sakemaru')->table('wms_filament_filter_set_user', function (Blueprint $table) {
            $table->dropColumn('is_visible');
        });
    }
};
