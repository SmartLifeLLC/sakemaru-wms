<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            $table->enum('origin_type', ['AUTO', 'USER', 'DIST'])
                ->default('AUTO')
                ->after('is_manually_modified')
                ->comment('生成元: AUTO=自動計算, USER=ユーザ手動, DIST=分配システム');
        });

        Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->enum('origin_type', ['AUTO', 'USER', 'DIST'])
                ->default('AUTO')
                ->after('is_manually_modified')
                ->comment('生成元: AUTO=自動計算, USER=ユーザ手動, DIST=分配システム');
        });

        // 既存データの補正: is_manually_modified = true のレコードは USER とみなす
        DB::connection('sakemaru')->table('wms_order_candidates')
            ->where('is_manually_modified', true)
            ->update(['origin_type' => 'USER']);

        DB::connection('sakemaru')->table('wms_stock_transfer_candidates')
            ->where('is_manually_modified', true)
            ->update(['origin_type' => 'USER']);
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            $table->dropColumn('origin_type');
        });

        Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->dropColumn('origin_type');
        });
    }
};
