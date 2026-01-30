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
        Schema::connection($this->connection)->create('wms_incoming_work_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('incoming_schedule_id')->comment('入庫予定ID');
            $table->unsignedBigInteger('picker_id')->comment('作業者ID');
            $table->unsignedBigInteger('warehouse_id')->comment('作業倉庫ID');
            $table->integer('work_quantity')->default(0)->comment('作業数量');
            $table->date('work_arrival_date')->nullable()->comment('入荷日');
            $table->date('work_expiration_date')->nullable()->comment('賞味期限');
            $table->enum('status', ['WORKING', 'COMPLETED', 'CANCELLED'])->default('WORKING')->comment('ステータス');
            $table->timestamp('started_at')->nullable()->comment('作業開始日時');
            $table->timestamp('completed_at')->nullable()->comment('作業完了日時');
            $table->timestamps();

            $table->index('incoming_schedule_id', 'idx_incoming_schedule_id');
            $table->index('picker_id', 'idx_picker_id');
            $table->index('warehouse_id', 'idx_warehouse_id');
            $table->index('status', 'idx_status');
            $table->unique(['incoming_schedule_id', 'picker_id', 'status'], 'unique_schedule_picker_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_incoming_work_items');
    }
};
