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
        Schema::connection($this->connection)->create('wms_order_rule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wms_warehouse_contractor_order_rule_id')->comment('基本ルールID');
            $table->enum('target_type', ['ITEM', 'CATEGORY', 'TEMPERATURE', 'BRAND'])->comment('対象タイプ');
            $table->unsignedBigInteger('target_id')->comment('対象ID');
            $table->integer('priority')->default(0)->comment('優先順位');

            // オーバーライド項目（NULLは基本ルールを継承）
            $table->boolean('allows_case')->nullable();
            $table->boolean('allows_piece')->nullable();
            $table->integer('min_case_qty')->nullable();
            $table->integer('case_multiple_qty')->nullable();
            $table->integer('min_piece_qty')->nullable();
            $table->integer('piece_multiple_qty')->nullable();
            $table->enum('below_lot_action', ['ALLOW', 'BLOCK', 'ADD_FEE', 'ADD_SHIPPING', 'NEED_APPROVAL'])->nullable();

            $table->timestamps();

            $table->index(['wms_warehouse_contractor_order_rule_id', 'target_type', 'target_id'], 'idx_rule_target');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_order_rule_exceptions');
    }
};
