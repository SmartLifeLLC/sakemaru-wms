<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_inventory_count_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_count_id');
            $table->unsignedBigInteger('real_stock_id')->nullable();
            $table->unsignedBigInteger('item_id');
            $table->string('item_code', 20)->default('');
            $table->string('item_name', 200)->default('');
            $table->string('barcode', 50)->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('floor_id')->nullable();
            $table->string('floor_name', 50)->nullable();
            $table->string('location_code1', 10)->nullable();
            $table->string('location_code2', 10)->nullable();
            $table->string('location_code3', 10)->nullable();
            $table->string('location_no', 30)->nullable();
            $table->unsignedBigInteger('lot_id')->nullable();
            $table->string('lot_no', 30)->nullable();
            $table->date('expiration_date')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->decimal('system_quantity', 15, 3)->default(0);
            $table->decimal('first_count_quantity', 15, 3)->nullable();
            $table->decimal('second_count_quantity', 15, 3)->nullable();
            $table->decimal('final_count_quantity', 15, 3)->nullable();
            $table->decimal('difference_quantity', 15, 3)->nullable();
            $table->decimal('cost_price', 15, 4)->default(0);
            $table->decimal('difference_amount', 15, 2)->nullable();
            $table->tinyInteger('input_count')->default(0);
            $table->timestamp('last_counted_at')->nullable();
            $table->timestamps();

            $table->index(['inventory_count_id', 'item_id']);
            $table->index(['inventory_count_id', 'floor_id', 'location_code1', 'location_code2', 'location_code3'], 'idx_ic_items_floor_location');
            $table->index(['inventory_count_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_inventory_count_items');
    }
};
