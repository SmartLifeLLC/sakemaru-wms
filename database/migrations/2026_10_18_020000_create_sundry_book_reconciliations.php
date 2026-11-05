<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_sundry_book_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('finalization_id');
            $table->string('approval_key', 100);
            $table->string('category1_code', 20);
            $table->date('process_date');
            $table->decimal('adjustment_amount', 18, 2);
            $table->json('source_snapshot');
            $table->timestamps();
            $table->unique(['client_id', 'warehouse_id', 'approval_key'], 'sundry_book_approval_unique');
            $table->index(['client_id', 'warehouse_id', 'process_date'], 'sundry_book_report_index');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('棚卸し差異の適用履歴を保護するため削除できません。');
    }
};
