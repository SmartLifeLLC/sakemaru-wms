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
        Schema::connection($this->connection)->create('wms_warehouse_holiday_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->unique()->comment('倉庫ID');
            $table->json('regular_holiday_days')->nullable()->comment('定休日の曜日配列 (例: [0, 6])');
            $table->boolean('is_national_holiday_closed')->default(true)->comment('祝日を休業とするか');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_warehouse_holiday_settings');
    }
};
