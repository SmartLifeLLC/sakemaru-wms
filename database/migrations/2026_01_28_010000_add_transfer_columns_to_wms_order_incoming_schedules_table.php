<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_candidate_id')->nullable()->after('order_candidate_id')
                ->comment('移動候補ID (order_source=TRANSFERの場合)');
            $table->unsignedBigInteger('source_warehouse_id')->nullable()->after('transfer_candidate_id')
                ->comment('移動元倉庫ID (order_source=TRANSFERの場合)');
            $table->unsignedBigInteger('stock_transfer_id')->nullable()->after('source_warehouse_id')
                ->comment('stock_transfers.id (sakemaru-ai-core)');

            $table->index('transfer_candidate_id');
            $table->index('source_warehouse_id');
            $table->index('stock_transfer_id');
        });

        // order_source enum に TRANSFER を追加
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_order_incoming_schedules
            MODIFY COLUMN order_source ENUM('AUTO', 'MANUAL', 'TRANSFER') DEFAULT 'MANUAL'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->dropIndex(['transfer_candidate_id']);
            $table->dropIndex(['source_warehouse_id']);
            $table->dropIndex(['stock_transfer_id']);

            $table->dropColumn([
                'transfer_candidate_id',
                'source_warehouse_id',
                'stock_transfer_id',
            ]);
        });

        // order_source enum を元に戻す
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_order_incoming_schedules
            MODIFY COLUMN order_source ENUM('AUTO', 'MANUAL') DEFAULT 'MANUAL'
        ");
    }
};
