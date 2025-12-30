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
        Schema::connection($this->connection)->create('wms_warehouse_item_total_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->unsignedBigInteger('item_id')->comment('商品ID');
            $table->dateTime('snapshot_at')->comment('集計日時');
            $table->integer('total_effective_piece')->default(0)->comment('有効在庫合計バラ数');
            $table->integer('total_non_effective_piece')->default(0)->comment('無効在庫合計バラ数');
            $table->integer('total_incoming_piece')->default(0)->comment('入荷予定合計バラ数');
            $table->timestamp('last_updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamps();

            $table->unique(['warehouse_id', 'item_id'], 'uk_warehouse_item');
            $table->index('snapshot_at', 'idx_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_warehouse_item_total_stocks');
    }
};
