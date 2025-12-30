<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_item_supply_settings');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->create('wms_item_supply_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->string('supply_type', 20)->default('EXTERNAL');
            $table->unsignedBigInteger('source_warehouse_id')->nullable();
            $table->unsignedBigInteger('item_contractor_id')->nullable();
            $table->unsignedInteger('lead_time_days')->default(3);
            $table->unsignedInteger('daily_consumption_qty')->default(0);
            $table->unsignedTinyInteger('hierarchy_level')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['warehouse_id', 'item_id']);
            $table->index(['hierarchy_level', 'is_enabled']);
        });
    }
};
