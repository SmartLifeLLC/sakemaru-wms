<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_distribution_batches', function (Blueprint $table) {
            $table->string('responsible_department', 20)->nullable()->comment('warehouse:倉庫担当 / headquarters:本部担当');
            $table->index(['client_id', 'warehouse_id', 'responsible_department', 'id'], 'distribution_batch_department');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('担当部署の登録内容を保全するため、自動での削除は行いません。');
    }
};
