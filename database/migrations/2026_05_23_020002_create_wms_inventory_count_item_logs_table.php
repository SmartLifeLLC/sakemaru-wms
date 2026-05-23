<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_inventory_count_item_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_count_item_id');
            $table->string('device_id', 50)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->tinyInteger('count_round');
            $table->decimal('old_quantity', 15, 3)->nullable();
            $table->decimal('new_quantity', 15, 3);
            $table->string('request_uuid', 36)->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['inventory_count_item_id', 'created_at'], 'idx_ic_item_logs_item_created');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_inventory_count_item_logs');
    }
};
