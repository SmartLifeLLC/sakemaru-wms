<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_real_stocks');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->create('wms_real_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('real_stock_id')->unique();
            $table->integer('reserved_quantity')->default(0)->comment('WMS reserved quantity');
            $table->integer('picking_quantity')->default(0)->comment('Currently being picked quantity');
            $table->integer('lock_version')->default(0)->comment('Optimistic lock version');
            $table->timestamps();

            $table->index('real_stock_id');
        });
    }
};
