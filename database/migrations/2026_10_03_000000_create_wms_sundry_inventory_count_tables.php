<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 棚卸し（雑貨）: 既存の棚卸し（wms_inventory_counts）とは独立した金額ベースの棚卸し。
 * 既存テーブルには一切手を入れない。FK は張らない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_sundry_inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->string('count_no', 30)->unique('wms_sundry_counts_count_no_uq');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('warehouse_code', 10)->default('');
            $table->string('warehouse_name', 100)->default('');
            $table->date('count_date');
            $table->string('status', 20)->default('counting');
            $table->json('category_ids')->nullable()->comment('対象中分類 item_categories.id');
            $table->json('amount_category_ids')->nullable()->comment('金額で棚卸しする大分類 item_categories.id');
            $table->json('amount_report_references')->nullable()->comment('在庫金額報告書ベースの非管理品残高（大分類別・突合用）');
            $table->date('theory_end_date')->nullable()->comment('理論在庫の受払終了日');
            $table->timestamp('theory_updated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->text('memo')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'count_date'], 'wms_sundry_counts_wh_date_idx');
            $table->index('status', 'wms_sundry_counts_status_idx');
        });

        // 数量明細: 在庫管理ありの商品（理論数 × 原価 で金額評価）
        Schema::connection('sakemaru')->create('wms_sundry_inventory_count_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sundry_inventory_count_id');
            $table->unsignedBigInteger('item_id');
            $table->string('item_code', 50)->default('');
            $table->string('item_name', 255)->default('');
            $table->unsignedBigInteger('category2_id')->nullable();
            $table->string('category2_code', 20)->default('');
            $table->string('category2_name', 100)->default('');
            $table->boolean('is_additional')->default(false)->comment('対象中分類外から追加した商品');
            $table->decimal('cost_price', 15, 4)->default(0);
            $table->decimal('system_quantity', 15, 3)->default(0)->comment('理論数');
            $table->decimal('counted_quantity', 15, 3)->nullable()->comment('実棚数');
            $table->decimal('difference_quantity', 15, 3)->nullable();
            $table->decimal('system_amount', 15, 2)->default(0)->comment('理論金額');
            $table->decimal('counted_amount', 15, 2)->nullable()->comment('実棚金額');
            $table->decimal('difference_amount', 15, 2)->nullable();
            $table->string('counted_by_name', 100)->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['sundry_inventory_count_id', 'item_id'], 'wms_sundry_count_items_count_item_uq');
            $table->index(['sundry_inventory_count_id', 'category2_code', 'item_code'], 'wms_sundry_count_items_sort_idx');
        });

        // 金額明細: 在庫管理なしの商品（旧Accessと同じ中分類単位の金額受払で評価）
        Schema::connection('sakemaru')->create('wms_sundry_inventory_count_amounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sundry_inventory_count_id');
            $table->unsignedBigInteger('category1_id')->nullable();
            $table->string('category1_code', 20)->default('');
            $table->string('category1_name', 100)->default('');
            $table->unsignedBigInteger('category2_id');
            $table->string('category2_code', 20)->default('');
            $table->string('category2_name', 100)->default('');
            $table->boolean('is_additional')->default(false)->comment('商品追加で増えた中分類');
            $table->date('opening_date')->nullable()->comment('前残金額の基準日');
            $table->decimal('opening_amount', 15, 2)->default(0)->comment('前残金額');
            $table->string('opening_source', 20)->default('default')->comment('legacy / previous_count / manual / default');
            $table->decimal('purchase_amount', 15, 2)->default(0);
            $table->decimal('transfer_in_amount', 15, 2)->default(0);
            $table->decimal('transfer_out_amount', 15, 2)->default(0);
            $table->decimal('sales_cost_amount', 15, 2)->default(0)->comment('売価 × 分類原価率');
            $table->decimal('adjustment_amount', 15, 2)->default(0)->comment('調整プラグ');
            $table->decimal('system_amount', 15, 2)->default(0)->comment('理論金額');
            $table->decimal('counted_amount', 15, 2)->nullable()->comment('実棚金額');
            $table->decimal('difference_amount', 15, 2)->nullable();
            $table->string('counted_by_name', 100)->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['sundry_inventory_count_id', 'category2_id'], 'wms_sundry_count_amounts_count_cat_uq');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_sundry_inventory_count_amounts');
        Schema::connection('sakemaru')->dropIfExists('wms_sundry_inventory_count_items');
        Schema::connection('sakemaru')->dropIfExists('wms_sundry_inventory_counts');
    }
};
