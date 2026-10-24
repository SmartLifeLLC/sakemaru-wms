<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive only: existing quantities, prices and category amounts are not migrated.
        if (! Schema::connection('sakemaru')->hasTable('wms_sundry_valuation_items')) {
            Schema::connection('sakemaru')->create('wms_sundry_valuation_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('sundry_inventory_count_id');
                $table->unsignedBigInteger('amount_id');
                $table->unsignedBigInteger('item_id');
                $table->string('item_code', 50);
                $table->string('item_name', 255);
                $table->unsignedBigInteger('price_id')->nullable();
                $table->date('price_date');
                $table->date('price_start_date')->nullable();
                $table->string('price_tax_type', 30)->nullable();
                $table->decimal('sale_price', 18, 4)->nullable();
                $table->decimal('net_sale_price', 18, 6)->nullable();
                $table->decimal('cost_rate', 8, 4)->nullable();
                $table->unsignedBigInteger('rate_category_id')->nullable();
                $table->decimal('valuation_unit_price', 18, 6)->nullable();
                $table->decimal('quantity', 15, 3)->nullable();
                $table->decimal('amount', 18, 2)->nullable();
                $table->unsignedInteger('revision')->default(0);
                $table->string('counted_by_name', 100)->nullable();
                $table->timestamp('counted_at')->nullable();
                $table->timestamps();
                $table->unique(['sundry_inventory_count_id', 'item_id'], 'sundry_valuation_item_uq');
                $table->index(['amount_id', 'item_code'], 'sundry_valuation_amount_idx');
            });
        }

        if (! Schema::connection('sakemaru')->hasTable('wms_sundry_amount_adjustments')) {
            Schema::connection('sakemaru')->create('wms_sundry_amount_adjustments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('client_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('sundry_inventory_count_id');
                $table->unsignedBigInteger('amount_id')->unique('sundry_amount_adjustment_source_uq');
                $table->unsignedBigInteger('category1_id');
                $table->unsignedBigInteger('category2_id');
                $table->string('category1_code', 20);
                $table->string('category2_code', 20);
                $table->date('count_date');
                $table->date('process_date');
                $table->decimal('book_amount', 18, 2);
                $table->decimal('counted_amount', 18, 2);
                $table->decimal('adjustment_amount', 18, 2);
                $table->text('book_reference');
                $table->json('source_snapshot');
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['client_id', 'warehouse_id', 'process_date'], 'sundry_amount_adjustment_period_idx');
                $table->index('sundry_inventory_count_id', 'sundry_amount_adjustment_count_idx');
                $table->unique(['client_id', 'warehouse_id', 'category2_id', 'process_date'], 'sundry_amount_adjustment_period_uq');
            });
        }
    }

    public function down(): void
    {
        // Financial and entered data must be preserved, including during code rollback.
        throw new RuntimeException('雑貨棚卸しの入力・調整履歴は削除できません。コードのみ戻してください。');
    }
};
