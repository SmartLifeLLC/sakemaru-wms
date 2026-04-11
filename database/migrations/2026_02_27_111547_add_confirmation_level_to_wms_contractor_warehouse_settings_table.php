<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE wms_contractor_warehouse_settings
            ADD COLUMN confirmation_level ENUM('STATUS1', 'STATUS2', 'STATUS3')
                NOT NULL DEFAULT 'STATUS1'
                COMMENT '確定レベル: STATUS1=候補表示, STATUS2=承認まで, STATUS3=確定まで'
                AFTER designated_code
        ");

        DB::statement('
            CREATE INDEX idx_wcws_confirmation_level
                ON wms_contractor_warehouse_settings (confirmation_level)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX idx_wcws_confirmation_level ON wms_contractor_warehouse_settings');
        DB::statement('ALTER TABLE wms_contractor_warehouse_settings DROP COLUMN confirmation_level');
    }
};
