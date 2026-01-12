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
        Schema::connection($this->connection)->create('real_stock_lot_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_lot_id')->index();
            $table->unsignedBigInteger('real_stock_id')->index();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('trade_item_id')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('content_amount', 12, 4)->default(0);
            $table->decimal('container_amount', 12, 4)->default(0);
            $table->date('expiration_date')->nullable();
            $table->integer('initial_quantity')->default(0);
            $table->integer('final_quantity')->default(0);
            $table->enum('status', ['DEPLETED', 'EXPIRED'])->default('DEPLETED');
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('real_stock_lot_histories');
    }
};
