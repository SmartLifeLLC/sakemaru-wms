<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        // 1. Check if column exists
        if (! Schema::connection('sakemaru')->hasColumn('wms_wave_settings', 'warehouse_id')) {
            return;
        }

        // 2. Drop existing UNIQUE index that includes warehouse_id
        $indexes = collect(DB::connection('sakemaru')->select(
            'SHOW INDEX FROM wms_wave_settings WHERE Column_name = ?',
            ['warehouse_id']
        ));

        foreach ($indexes->pluck('Key_name')->unique() as $indexName) {
            Schema::connection('sakemaru')->table('wms_wave_settings', function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }

        // 3. Drop the warehouse_id column
        Schema::connection('sakemaru')->table('wms_wave_settings', function (Blueprint $table) {
            $table->dropColumn('warehouse_id');
        });

        // 4. Add new UNIQUE index (delivery_course_id, picking_start_time)
        Schema::connection('sakemaru')->table('wms_wave_settings', function (Blueprint $table) {
            $table->unique(['delivery_course_id', 'picking_start_time'], 'wms_wave_settings_course_start_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_wave_settings', function (Blueprint $table) {
            $table->dropUnique('wms_wave_settings_course_start_unique');
        });

        Schema::connection('sakemaru')->table('wms_wave_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('name');
        });
    }
};
