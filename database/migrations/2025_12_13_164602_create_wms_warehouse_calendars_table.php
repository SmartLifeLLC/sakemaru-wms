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
        Schema::connection($this->connection)->create('wms_warehouse_calendars', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->date('target_date')->comment('対象日付');
            $table->boolean('is_holiday')->default(false)->comment('休日フラグ (0:営業日, 1:休日)');
            $table->string('holiday_reason', 255)->nullable()->comment('休日理由');
            $table->boolean('is_manual_override')->default(false)->comment('手動変更フラグ');
            $table->timestamps();

            $table->unique(['warehouse_id', 'target_date'], 'uk_warehouse_date');
            $table->index(['warehouse_id', 'target_date', 'is_holiday'], 'idx_calc_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_warehouse_calendars');
    }
};
