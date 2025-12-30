<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 発注先-倉庫マッピングテーブル
 *
 * 発注先(contractor)が内部倉庫を表す場合、その倉庫IDをマッピングする。
 * マッピングが存在する発注先 → 内部倉庫（INTERNAL供給）
 * マッピングが存在しない発注先 → 外部発注先（EXTERNAL供給）
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_contractor_warehouse_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contractor_id')->unique()->comment('発注先ID');
            $table->unsignedBigInteger('warehouse_id')->comment('対応する倉庫ID');
            $table->string('memo')->nullable()->comment('メモ');
            $table->timestamps();

            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_contractor_warehouse_mappings');
    }
};
