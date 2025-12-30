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
            $table->unsignedInteger('available_quantity_flags')->nullable()->after('polygon')
                ->comment('引当可能単位: 1=ケース, 2=バラ, 3=ケース+バラ, 4=ボール');
            $table->enum('temperature_type', ['NORMAL', 'CHILLED', 'FROZEN'])->nullable()->after('available_quantity_flags')
                ->comment('温度帯');
            $table->boolean('is_restricted_area')->default(false)->after('temperature_type')
                ->comment('制限エリアフラグ');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_areas', function (Blueprint $table) {
            $table->dropColumn(['available_quantity_flags', 'temperature_type', 'is_restricted_area']);
        });
    }
};
