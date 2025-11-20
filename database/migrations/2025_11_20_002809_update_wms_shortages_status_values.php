<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ステータス値の変更:
     * OPEN → BEFORE (未対応)
     * REALLOCATING → REALLOCATING (移動出荷)
     * FULFILLED → (削除)
     * CONFIRMED → SHORTAGE (欠品確定)
     * CANCELLED → (削除)
     * 新規 → PARTIAL_SHORTAGE (部分欠品)
     */
    public function up(): void
    {
        $connection = config('database.connections.sakemaru.driver') === 'mysql' ? 'sakemaru' : 'sqlite';

        // ENUMカラムを先に拡張（MySQLの場合）
        if ($connection === 'sakemaru') {
            DB::connection('sakemaru')->statement("
                ALTER TABLE wms_shortages
                MODIFY COLUMN status ENUM('OPEN', 'REALLOCATING', 'FULFILLED', 'CONFIRMED', 'CANCELLED', 'BEFORE', 'SHORTAGE', 'PARTIAL_SHORTAGE')
                NOT NULL DEFAULT 'OPEN'
            ");
        }

        // 既存データのステータスを新しい値に更新
        DB::connection('sakemaru')->table('wms_shortages')
            ->where('status', 'OPEN')
            ->update(['status' => 'BEFORE']);

        DB::connection('sakemaru')->table('wms_shortages')
            ->where('status', 'CONFIRMED')
            ->update(['status' => 'SHORTAGE']);

        // FULFILLEDとCANCELLEDはSHORTAGEに変換（データが存在する場合）
        DB::connection('sakemaru')->table('wms_shortages')
            ->whereIn('status', ['FULFILLED', 'CANCELLED'])
            ->update(['status' => 'SHORTAGE']);

        // ENUMカラムを新しい値のみに制限（MySQLの場合）
        if ($connection === 'sakemaru') {
            DB::connection('sakemaru')->statement("
                ALTER TABLE wms_shortages
                MODIFY COLUMN status ENUM('BEFORE', 'REALLOCATING', 'SHORTAGE', 'PARTIAL_SHORTAGE')
                NOT NULL DEFAULT 'BEFORE'
                COMMENT 'ステータス (BEFORE: 未対応, REALLOCATING: 移動出荷中, SHORTAGE: 欠品確定, PARTIAL_SHORTAGE: 部分欠品)'
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = config('database.connections.sakemaru.driver') === 'mysql' ? 'sakemaru' : 'sqlite';

        // ステータスを元に戻す
        DB::connection('sakemaru')->table('wms_shortages')
            ->where('status', 'BEFORE')
            ->update(['status' => 'OPEN']);

        DB::connection('sakemaru')->table('wms_shortages')
            ->where('status', 'SHORTAGE')
            ->update(['status' => 'CONFIRMED']);

        DB::connection('sakemaru')->table('wms_shortages')
            ->where('status', 'PARTIAL_SHORTAGE')
            ->update(['status' => 'OPEN']);

        // ENUMカラムを元に戻す（MySQLの場合）
        if ($connection === 'sakemaru') {
            DB::connection('sakemaru')->statement("
                ALTER TABLE wms_shortages
                MODIFY COLUMN status ENUM('OPEN', 'REALLOCATING', 'FULFILLED', 'CONFIRMED', 'CANCELLED')
                NOT NULL DEFAULT 'OPEN'
                COMMENT 'ステータス'
            ");
        }
    }
};
