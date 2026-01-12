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
        Schema::connection($this->connection)->create('real_stock_lots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('real_stock_id')->index();
            $table->unsignedBigInteger('purchase_id')->nullable()->index();
            $table->unsignedBigInteger('trade_item_id')->nullable()->index();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('content_amount', 12, 4)->default(0);
            $table->decimal('container_amount', 12, 4)->default(0);
            $table->date('expiration_date')->nullable();
            $table->integer('initial_quantity')->default(0);
            $table->integer('current_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->enum('status', ['ACTIVE', 'DEPLETED', 'EXPIRED'])->default('ACTIVE');
            $table->timestamps();

            $table->index(['real_stock_id', 'status'], 'idx_real_stock_lots_status');
            $table->index(['expiration_date', 'status'], 'idx_real_stock_lots_expiration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('real_stock_lots');
    }
};
