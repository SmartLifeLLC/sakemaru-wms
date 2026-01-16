<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * stock_transfers をピッキング対象として統合するためのカラム追加
     */
    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_picking_item_results', function (Blueprint $table) {
            // source_type: 'EARNING' or 'STOCK_TRANSFER'
            $table->enum('source_type', ['EARNING', 'STOCK_TRANSFER'])
                ->default('EARNING')
                ->after('earning_id')
                ->comment('伝票種別: EARNING=出荷伝票, STOCK_TRANSFER=倉庫間移動');

            // stock_transfer_id: source_type='STOCK_TRANSFER' の場合に使用
            $table->unsignedBigInteger('stock_transfer_id')
                ->nullable()
                ->after('source_type')
                ->comment('倉庫間移動ID（source_type=STOCK_TRANSFERの場合）');

            // インデックス
            $table->index('source_type');
            $table->index('stock_transfer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropIndex(['source_type']);
            $table->dropIndex(['stock_transfer_id']);
            $table->dropColumn(['source_type', 'stock_transfer_id']);
        });
    }
};
