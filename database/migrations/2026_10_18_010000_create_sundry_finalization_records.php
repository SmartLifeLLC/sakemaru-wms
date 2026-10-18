<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('wms_sundry_finalizations')) {
            Schema::connection('sakemaru')->create('wms_sundry_finalizations', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('client_id');
                $t->unsignedBigInteger('warehouse_id');
                $t->unsignedBigInteger('sundry_inventory_count_id')->index();
                $t->unsignedBigInteger('active_count_id')->nullable()->unique();
                $t->date('count_date');
                $t->date('process_date');
                $t->string('status', 30)->default('queued');
                $t->char('token', 64);
                $t->json('snapshot');
                $t->unsignedBigInteger('inventory_adjustment_id')->nullable();
                $t->unsignedBigInteger('record_queue_id')->nullable();
                $t->unsignedBigInteger('apply_queue_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('applied_at')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->unsignedBigInteger('cancelled_by')->nullable();
                $t->text('error_message')->nullable();
                $t->timestamps();
                $t->index(['client_id', 'warehouse_id', 'process_date'], 'sundry_finalization_period_idx');
            });
        }
        if (! Schema::connection('sakemaru')->hasTable('inventory_adjustment_sundry_lines')) {
            Schema::connection('sakemaru')->create('inventory_adjustment_sundry_lines', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('inventory_adjustment_id')->index();
                $t->unsignedBigInteger('finalization_id');
                $t->unsignedInteger('line_no');
                $t->string('kind', 20);
                $t->unsignedBigInteger('item_id')->nullable();
                $t->unsignedBigInteger('category2_id')->nullable();
                $t->decimal('quantity_before', 18, 3)->nullable();
                $t->decimal('quantity_after', 18, 3)->nullable();
                $t->decimal('difference_quantity', 18, 3)->nullable();
                $t->decimal('unit_price', 18, 6)->nullable();
                $t->decimal('amount', 18, 2);
                $t->json('source_snapshot');
                $t->timestamps();
                $t->unique(['finalization_id', 'line_no'], 'sundry_voucher_line_uq');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('棚卸しの確定・伝票履歴は削除できません。コードのみ戻してください。');
    }
};
