<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_item_supply_settings', function (Blueprint $table) {
            $table->id();

            // 発注元（需要側）
            $table->unsignedBigInteger('warehouse_id')->comment('発注元倉庫');
            $table->unsignedBigInteger('item_id')->comment('対象商品');

            // 供給元タイプ
            $table->string('supply_type', 20)->default('EXTERNAL')->comment('INTERNAL=内部移動, EXTERNAL=外部発注');

            // 内部移動 (INTERNAL) の場合
            $table->unsignedBigInteger('source_warehouse_id')->nullable()->comment('供給元倉庫ID (INTERNAL時必須)');

            // 外部発注 (EXTERNAL) の場合
            $table->unsignedBigInteger('item_contractor_id')->nullable()->comment('仕入れ契約ID (EXTERNAL時必須)');

            // 調達パラメータ
            $table->integer('lead_time_days')->default(1)->comment('調達リードタイム（日）');
            $table->integer('safety_stock_qty')->default(0)->comment('安全在庫数');
            $table->integer('daily_consumption_qty')->default(0)->comment('1日あたり消費予測数');

            // 計算順序制御
            $table->integer('hierarchy_level')->default(0)->comment('供給階層レベル (0=最下流)');

            // 有効フラグ
            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            // ユニーク制約: 1倉庫1商品につき1設定
            $table->unique(['warehouse_id', 'item_id'], 'uk_wh_item');

            // インデックス
            $table->index('hierarchy_level', 'idx_hierarchy');
            $table->index(['supply_type', 'source_warehouse_id'], 'idx_internal_supply');
            $table->index(['supply_type', 'item_contractor_id'], 'idx_external_supply');
        });

        // 旧テーブルのカラム削除
        Schema::connection($this->connection)->table('wms_warehouse_auto_order_settings', function (Blueprint $table) {
            $table->dropColumn(['warehouse_type', 'hub_warehouse_id']);
        });
    }

    public function down(): void
    {
        // 旧カラム復元
        Schema::connection($this->connection)->table('wms_warehouse_auto_order_settings', function (Blueprint $table) {
            $table->string('warehouse_type', 20)->default('SATELLITE')->after('warehouse_id');
            $table->unsignedBigInteger('hub_warehouse_id')->nullable()->after('warehouse_type');
        });

        Schema::connection($this->connection)->dropIfExists('wms_item_supply_settings');
    }
};
