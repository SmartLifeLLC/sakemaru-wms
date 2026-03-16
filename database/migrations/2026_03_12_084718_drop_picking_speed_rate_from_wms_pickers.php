<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::table('wms_pickers', function (Blueprint $table) {
            $table->dropColumn('picking_speed_rate');
        });
    }

    public function down(): void
    {
        Schema::table('wms_pickers', function (Blueprint $table) {
            $table->decimal('picking_speed_rate', 3, 2)->default(1.00)->comment('作業速度係数 (0.50~2.00)');
        });
    }
};
