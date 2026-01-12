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
        Schema::connection($this->connection)->create('real_stock_lot_earnings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('real_stock_lot_id')->index();
            $table->unsignedBigInteger('earning_id')->index();
            $table->unsignedBigInteger('trade_item_id')->index();
            $table->integer('quantity')->default(0);
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->decimal('purchase_amount', 14, 2)->nullable();
            $table->decimal('selling_price', 12, 2)->nullable();
            $table->decimal('selling_amount', 14, 2)->nullable();
            $table->enum('status', ['RESERVED', 'DELIVERED', 'CANCELLED'])->default('RESERVED');
            $table->dateTime('reserved_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['real_stock_lot_id', 'status'], 'idx_lot_earnings_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('real_stock_lot_earnings');
    }
};
