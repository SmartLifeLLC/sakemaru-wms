<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 楽観ロック用のバージョンカラムを追加
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1)->after('modified_at');
        });

        // 移動候補テーブルにも追加
        Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1)->after('modified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });

        Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
