<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 発注先×倉庫ごとの設定テーブル
 *
 * 発注先が倉庫ごとに指定する納入先指定コードを保持する。
 * FK は作成しない（プロジェクトルール）
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_contractor_warehouse_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->unsignedBigInteger('contractor_id')->comment('発注先ID');
            $table->string('designated_code')->nullable()->comment('納入先指定コード');
            $table->timestamps();

            $table->unique(['warehouse_id', 'contractor_id'], 'uniq_wcws_warehouse_contractor');
            $table->index('warehouse_id', 'idx_wcws_warehouse');
            $table->index('contractor_id', 'idx_wcws_contractor');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_contractor_warehouse_settings');
    }
};
