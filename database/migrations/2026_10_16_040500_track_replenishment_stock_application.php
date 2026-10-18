<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_replenishment_effect_sources', function (Blueprint $table) {
            $table->unsignedBigInteger('receipt_queue_id')->nullable();
            $table->unsignedBigInteger('stock_applied_pieces')->default(0);
            $table->timestamp('stock_applied_at')->nullable();
        });
        Schema::connection('sakemaru')->table('wms_replenishment_effects', function (Blueprint $table) {
            $table->index(['client_id', 'state', 'updated_at', 'id'], 'wms_supply_receipt_pending');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Stock application evidence must be retained. Disable automatic ordering instead.');
    }
};
