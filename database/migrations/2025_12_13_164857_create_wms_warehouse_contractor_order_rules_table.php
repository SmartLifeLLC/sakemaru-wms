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
        Schema::connection($this->connection)->create('wms_warehouse_contractor_order_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->unsignedBigInteger('contractor_id')->comment('発注先ID');

            // 発注単位許可
            $table->boolean('allows_case')->default(true)->comment('ケース発注可否');
            $table->boolean('allows_piece')->default(false)->comment('バラ発注可否');
            $table->enum('piece_to_case_rounding', ['CEIL', 'FLOOR', 'ROUND'])->default('CEIL')->comment('バラ→ケース変換時の丸め');

            // 混載設定
            $table->boolean('allows_mixed')->default(false)->comment('混載許可');
            $table->enum('mixed_unit', ['CASE', 'PIECE', 'NONE'])->default('NONE')->comment('混載時の単位');
            $table->integer('mixed_limit_qty')->nullable()->comment('混載時の最低合計数');

            // ケース発注ルール
            $table->integer('min_case_qty')->default(1)->comment('最小ケース数');
            $table->integer('case_multiple_qty')->default(1)->comment('ケース倍数');

            // バラ発注ルール
            $table->integer('min_piece_qty')->nullable()->comment('最小バラ数');
            $table->integer('piece_multiple_qty')->default(1)->comment('バラ倍数');

            // ロット未達時のアクション
            $table->enum('below_lot_action', ['ALLOW', 'BLOCK', 'ADD_FEE', 'ADD_SHIPPING', 'NEED_APPROVAL'])->default('ALLOW')->comment('ロット未達時アクション');
            $table->decimal('handling_fee', 10, 2)->nullable()->comment('手数料');
            $table->decimal('shipping_fee', 10, 2)->nullable()->comment('送料');

            $table->timestamps();

            $table->unique(['warehouse_id', 'contractor_id'], 'uk_warehouse_contractor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_warehouse_contractor_order_rules');
    }
};
