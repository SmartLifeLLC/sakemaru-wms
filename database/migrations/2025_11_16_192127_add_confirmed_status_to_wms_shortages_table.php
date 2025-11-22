<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add CONFIRMED status to wms_shortages.status ENUM
     * CONFIRMED: 欠品処理確定済み（横持ち出荷指示がピッキング結果に反映済み）
     */
    public function up(): void
    {
        // Temporarily disable strict mode to allow ENUM modification
        DB::connection('sakemaru')->statement("SET SESSION sql_mode = ''");

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

        // Restore strict mode
        DB::connection('sakemaru')->statement("
            SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Temporarily disable strict mode
        DB::connection('sakemaru')->statement("SET SESSION sql_mode = ''");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_shortages
            MODIFY COLUMN status ENUM(
                'OPEN',
                'REALLOCATING',
                'FULFILLED',
                'CANCELLED'
            ) DEFAULT 'OPEN'
        ");

        // Restore strict mode
        DB::connection('sakemaru')->statement("
            SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
        ");
    }
};
