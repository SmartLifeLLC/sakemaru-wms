<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * HUB倉庫の有効在庫を候補レコードに保存するためのカラム追加
     */
    public function up(): void
    {
        Schema::table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->integer('hub_effective_stock')->nullable()->after('safety_stock');
        });
    }

    public function down(): void
    {
        Schema::table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->dropColumn('hub_effective_stock');
        });
    }
};
