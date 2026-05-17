<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (Schema::connection('sakemaru')->hasColumn('wms_wave_settings', 'is_wave_generation_enabled')) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_wave_settings', function (Blueprint $table) {
            $table->boolean('is_wave_generation_enabled')
                ->default(true)
                ->after('picking_deadline_time')
                ->comment('手動波動生成の初期選択対象フラグ');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_wave_settings', 'is_wave_generation_enabled')) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_wave_settings', function (Blueprint $table) {
            $table->dropColumn('is_wave_generation_enabled');
        });
    }
};
