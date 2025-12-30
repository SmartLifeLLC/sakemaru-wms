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
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            // 元倉庫ID（欠品発生倉庫）
            $table->unsignedBigInteger('source_warehouse_id')->nullable()->after('from_warehouse_id')->comment('元倉庫ID（欠品発生倉庫）');

            // 単価情報
            $table->decimal('purchase_price', 10, 2)->default(0)->after('assign_qty_type')->comment('仕入単価（倉庫単価）');
            $table->decimal('tax_exempt_price', 10, 2)->default(0)->after('purchase_price')->comment('容器単価（税抜単価）');
            $table->decimal('price', 10, 2)->nullable()->after('tax_exempt_price')->comment('販売単価（trade_itemsから取得）');

            // 承認情報
            $table->boolean('is_confirmed')->default(false)->after('status')->comment('承認済みフラグ');
            $table->timestamp('confirmed_at')->nullable()->after('is_confirmed')->comment('承認日時');
            $table->unsignedBigInteger('confirmed_user_id')->nullable()->after('confirmed_at')->comment('承認ユーザーID');

            // インデックス
            $table->index('source_warehouse_id', 'idx_alloc_source_warehouse');
            $table->index('is_confirmed', 'idx_alloc_confirmed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->dropIndex('idx_alloc_confirmed');
            $table->dropIndex('idx_alloc_source_warehouse');
            $table->dropColumn([
                'source_warehouse_id',
                'purchase_price',
                'tax_exempt_price',
                'price',
                'is_confirmed',
                'confirmed_at',
                'confirmed_user_id',
            ]);
        });
    }
};
