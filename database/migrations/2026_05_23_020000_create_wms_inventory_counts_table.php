<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->string('count_no', 30)->unique();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('warehouse_code', 10)->default('');
            $table->string('warehouse_name', 100)->default('');
            $table->date('count_date');
            $table->string('status', 20)->default('draft');
            $table->boolean('lock_mode')->default(false);
            $table->timestamp('snapshot_taken_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->text('memo')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'count_date']);
            $table->index('status');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_inventory_counts');
    }
};
