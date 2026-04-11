<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * confirmation_level を wms_warehouse_settings から wms_warehouse_auto_order_settings に移動し、
 * wms_warehouse_settings テーブルを削除する。
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        // 1. wms_warehouse_auto_order_settings に confirmation_level を追加
        DB::statement("
            ALTER TABLE wms_warehouse_auto_order_settings
            ADD COLUMN confirmation_level ENUM('STATUS1', 'STATUS2', 'STATUS3')
                NOT NULL DEFAULT 'STATUS2'
                COMMENT '確定レベル: STATUS1=候補表示, STATUS2=承認まで, STATUS3=確定まで'
                AFTER exclude_holiday_arrival
        ");

        // 2. wms_warehouse_settings から既存データを移行
        DB::statement('
            UPDATE wms_warehouse_auto_order_settings ao
            INNER JOIN wms_warehouse_settings ws ON ws.warehouse_id = ao.warehouse_id
            SET ao.confirmation_level = ws.confirmation_level
        ');

        // 3. wms_warehouse_settings テーブルを削除
        Schema::connection($this->connection)->drop('wms_warehouse_settings');
    }

    public function down(): void
    {
        // 1. wms_warehouse_settings テーブルを復元
        Schema::connection($this->connection)->create('wms_warehouse_settings', function ($table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->unique()->comment('倉庫ID');
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE wms_warehouse_settings
            ADD COLUMN confirmation_level ENUM('STATUS1', 'STATUS2', 'STATUS3')
                NOT NULL DEFAULT 'STATUS2'
                COMMENT '確定レベル: STATUS1=候補表示, STATUS2=承認まで, STATUS3=確定まで'
                AFTER warehouse_id
        ");

        // 2. データを復元
        DB::statement('
            INSERT INTO wms_warehouse_settings (warehouse_id, confirmation_level, created_at, updated_at)
            SELECT warehouse_id, confirmation_level, NOW(), NOW()
            FROM wms_warehouse_auto_order_settings
        ');

        // 3. wms_warehouse_auto_order_settings から confirmation_level を削除
        DB::statement('ALTER TABLE wms_warehouse_auto_order_settings DROP COLUMN confirmation_level');
    }
};
