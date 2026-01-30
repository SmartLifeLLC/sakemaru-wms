<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * wms_order_jx_settings に contractor_id を追加
 *
 * JX接続設定と発注先を紐付けるためのカラム。
 * 発注データ送信時に、発注先からJX設定を特定するために使用。
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('contractor_id')->nullable()->after('name')->comment('発注先ID');
            $table->index('contractor_id', 'idx_contractor_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->dropIndex('idx_contractor_id');
            $table->dropColumn('contractor_id');
        });
    }
};
