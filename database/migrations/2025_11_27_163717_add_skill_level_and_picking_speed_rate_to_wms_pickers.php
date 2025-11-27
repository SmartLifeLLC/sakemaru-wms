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
        Schema::connection('sakemaru')->table('wms_pickers', function (Blueprint $table) {
            if (!Schema::connection('sakemaru')->hasColumn('wms_pickers', 'skill_level')) {
                $table->unsignedTinyInteger('skill_level')->default(3)->after('is_active')
                    ->comment('スキルレベル (1:研修中, 2:一般, 3:熟練, 4:スペシャリスト, 5:達人)');
            }
            if (!Schema::connection('sakemaru')->hasColumn('wms_pickers', 'picking_speed_rate')) {
                $table->decimal('picking_speed_rate', 3, 2)->default(1.00)->after('skill_level')
                    ->comment('作業速度係数 (0.50~2.00)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_pickers', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('wms_pickers', 'skill_level')) {
                $table->dropColumn('skill_level');
            }
            if (Schema::connection('sakemaru')->hasColumn('wms_pickers', 'picking_speed_rate')) {
                $table->dropColumn('picking_speed_rate');
            }
        });
    }
};
