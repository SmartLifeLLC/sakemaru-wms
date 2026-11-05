<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_replenishment_effects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('item_id');
            $t->string('source_type', 20);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('source_warehouse_id')->nullable();
            $t->unsignedBigInteger('queue_id')->nullable()->index();
            $t->unsignedBigInteger('stock_transfer_id')->nullable()->index();
            $t->string('state', 20)->default('OPEN');
            $t->unsignedBigInteger('ordered_pieces');
            $t->unsignedBigInteger('received_pieces')->default(0);
            $t->unsignedInteger('capacity_case');
            $t->unsignedInteger('capacity_carton');
            $t->boolean('reconciliation_required')->default(false);
            $t->timestamp('received_completed_at')->nullable();
            $t->timestamps();
            $t->unique(['source_type', 'source_id'], 'wms_supply_origin_unique');
            $t->index(['client_id', 'warehouse_id', 'item_id'], 'wms_supply_scope');
        });
        Schema::connection($this->connection)->create('wms_replenishment_effect_sources', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('effect_id')->index();
            $t->unsignedBigInteger('incoming_schedule_id')->unique();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Supply evidence must be retained. Disable automatic ordering instead.');
    }
};
