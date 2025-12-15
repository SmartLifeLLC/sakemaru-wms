<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_auto_order_settings', function (Blueprint $table) {
            $table->id();
            $table->string('calc_logic_type', 50)->default('STANDARD')->comment('計算ロジックタイプ');
            $table->time('satellite_calc_time')->default('10:00:00')->comment('非拠点計算開始時刻');
            $table->time('hub_calc_time')->default('10:30:00')->comment('拠点計算開始時刻');
            $table->time('execution_time')->default('12:00:00')->comment('発注実行時刻');
            $table->boolean('is_auto_execution_enabled')->default(false)->comment('自動実行有効フラグ');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_auto_order_settings');
    }
};
