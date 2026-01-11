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
     * wms_shortagesテーブルの修正:
     * ステータスENUMの変更: OPEN → BEFORE, FULFILLED → SHORTAGE, CONFIRMED → PARTIAL_SHORTAGE
     *
     * Note: カラム名の変更 (xxx_each → xxx) は既に完了済み
     */
    public function up(): void
    {
        DB::connection('sakemaru')->statement("SET SESSION sql_mode = ''");

        // 1. ステータスENUMを変更 (新旧両方の値を含む)
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_shortages
            MODIFY COLUMN status ENUM(
                'OPEN',
                'BEFORE',
                'REALLOCATING',
                'FULFILLED',
                'SHORTAGE',
                'CONFIRMED',
                'PARTIAL_SHORTAGE',
                'CANCELLED'
            ) DEFAULT 'BEFORE'
        ");

        // 2. ステータスの値を変換 (OPEN → BEFORE, etc.)
        DB::connection('sakemaru')->statement("
            UPDATE wms_shortages
            SET status = CASE status
                WHEN 'OPEN' THEN 'BEFORE'
                WHEN 'FULFILLED' THEN 'SHORTAGE'
                WHEN 'CONFIRMED' THEN 'PARTIAL_SHORTAGE'
                ELSE status
            END
        ");

        // 3. ステータスENUMを最終形に変更
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_shortages
            MODIFY COLUMN status ENUM(
                'BEFORE',
                'REALLOCATING',
                'SHORTAGE',
                'PARTIAL_SHORTAGE',
                'CANCELLED'
            ) DEFAULT 'BEFORE'
            COMMENT 'ステータス（BEFORE: 処理前, REALLOCATING: 再引当中, SHORTAGE: 欠品確定, PARTIAL_SHORTAGE: 部分欠品, CANCELLED: キャンセル）'
        ");

        DB::connection('sakemaru')->statement("
            SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection('sakemaru')->statement("SET SESSION sql_mode = ''");

        // 1. ステータスENUMを変更 (新旧両方の値を含む)
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_shortages
            MODIFY COLUMN status ENUM(
                'OPEN',
                'BEFORE',
                'REALLOCATING',
                'FULFILLED',
                'SHORTAGE',
                'CONFIRMED',
                'PARTIAL_SHORTAGE',
                'CANCELLED'
            ) DEFAULT 'OPEN'
        ");

        // 2. データを変換
        DB::connection('sakemaru')->statement("
            UPDATE wms_shortages
            SET status = CASE status
                WHEN 'BEFORE' THEN 'OPEN'
                WHEN 'SHORTAGE' THEN 'FULFILLED'
                WHEN 'PARTIAL_SHORTAGE' THEN 'CONFIRMED'
                ELSE status
            END
        ");

        // 3. ステータスENUMを元に戻す
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_shortages
            MODIFY COLUMN status ENUM(
                'OPEN',
                'REALLOCATING',
                'FULFILLED',
                'CONFIRMED',
                'CANCELLED'
            ) DEFAULT 'OPEN'
            COMMENT 'ステータス（OPEN: 未対応, REALLOCATING: 横持ち出荷中, FULFILLED: 充足, CONFIRMED: 処理確定済み, CANCELLED: キャンセル）'
        ");

        DB::connection('sakemaru')->statement("
            SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
        ");
    }
};
