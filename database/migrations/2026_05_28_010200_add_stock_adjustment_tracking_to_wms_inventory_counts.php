<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'stock_adjustment_request_id')) {
                $table->string('stock_adjustment_request_id')->nullable()->after('confirmed_by')->comment('在庫調整リクエストID');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'stock_adjustment_queue_id')) {
                $table->unsignedBigInteger('stock_adjustment_queue_id')->nullable()->after('stock_adjustment_request_id')->comment('在庫調整キューID');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'stock_adjustment_id')) {
                $table->unsignedBigInteger('stock_adjustment_id')->nullable()->after('stock_adjustment_queue_id')->comment('作成された在庫調整ID');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'stock_adjustment_created_at')) {
                $table->timestamp('stock_adjustment_created_at')->nullable()->after('stock_adjustment_id')->comment('在庫調整作成日時');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'stock_adjustment_error_message')) {
                $table->text('stock_adjustment_error_message')->nullable()->after('stock_adjustment_created_at')->comment('在庫調整作成エラー');
            }
        });

        $this->addIndexIfMissing('stock_adjustment_request_id', 'idx_wms_ic_stock_adjustment_request');
        $this->addIndexIfMissing('stock_adjustment_queue_id', 'idx_wms_ic_stock_adjustment_queue');
        $this->addIndexIfMissing('stock_adjustment_id', 'idx_wms_ic_stock_adjustment');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('idx_wms_ic_stock_adjustment_request');
        $this->dropIndexIfExists('idx_wms_ic_stock_adjustment_queue');
        $this->dropIndexIfExists('idx_wms_ic_stock_adjustment');

        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            foreach ([
                'stock_adjustment_error_message',
                'stock_adjustment_created_at',
                'stock_adjustment_id',
                'stock_adjustment_queue_id',
                'stock_adjustment_request_id',
            ] as $column) {
                if (Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addIndexIfMissing(string $column, string $index): void
    {
        if ($this->hasIndex($index)) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) use ($column, $index) {
            $table->index($column, $index);
        });
    }

    private function dropIndexIfExists(string $index): void
    {
        if (! $this->hasIndex($index)) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) use ($index) {
            $table->dropIndex($index);
        });
    }

    private function hasIndex(string $index): bool
    {
        return ! empty(DB::connection('sakemaru')->select(
            'SHOW INDEX FROM wms_inventory_counts WHERE Key_name = ?',
            [$index]
        ));
    }
};
