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
        Schema::connection('sakemaru')->create('wms_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->integer('warehouse_id');
            $table->date('target_date');

            // 基本統計
            $table->integer('picking_slip_count')->default(0)->comment('ピッキング伝票数');
            $table->integer('picking_item_count')->default(0)->comment('ピッキング商品数');
            $table->integer('unique_item_count')->default(0)->comment('ユニーク商品数');

            // 欠品統計
            $table->integer('stockout_unique_count')->default(0)->comment('欠品商品数(ユニーク)');
            $table->integer('stockout_total_count')->default(0)->comment('合計欠品数');

            // 配送統計
            $table->integer('delivery_course_count')->default(0)->comment('配送コース数');

            // 数量・金額統計
            $table->integer('total_ship_qty')->default(0)->comment('合計出荷数量(総バラ数)');
            $table->decimal('total_amount_ex', 15, 2)->default(0)->comment('税抜合計出荷金額');
            $table->decimal('total_amount_in', 15, 2)->default(0)->comment('税込合計出荷金額');
            $table->decimal('total_container_deposit', 15, 2)->default(0)->comment('合計容器保証金額');
            $table->decimal('total_opportunity_loss', 15, 2)->default(0)->comment('欠品損失額合計');

            // 詳細内訳（JSON）
            $table->json('category_breakdown')->nullable()->comment('カテゴリ別等の詳細内訳データ');

            // 集計管理
            $table->timestamp('last_calculated_at')->nullable()->comment('最終集計日時(30分更新判定用)');

            $table->timestamps();

            // ユニーク制約
            $table->unique(['warehouse_id', 'target_date'], 'wms_daily_stats_warehouse_date_unique');

            // インデックス
            $table->index('warehouse_id');
            $table->index('target_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_daily_stats');
    }
};
