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
        Schema::connection($this->connection)->create('wms_partner_nearest_warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_id')->comment('得意先ID');
            $table->unsignedBigInteger('nearest_warehouse_id')->comment('最寄倉庫ID');
            $table->decimal('min_distance_km', 10, 2)->comment('最短距離（km）');
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->unsignedBigInteger('last_updater_id')->nullable();
            $table->timestamps();

            // FK は作成しない
            $table->unique('partner_id');
            $table->index('nearest_warehouse_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_partner_nearest_warehouses');
    }
};
