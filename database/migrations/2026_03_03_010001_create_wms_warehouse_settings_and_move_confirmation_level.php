<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 確定レベルを倉庫レベルに移動
 *
 * wms_warehouse_settings テーブルを新設し、
 * wms_contractor_warehouse_settings から confirmation_level を削除する。
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        // 1. 倉庫設定テーブルを新設
        Schema::connection($this->connection)->create('wms_warehouse_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->unique()->comment('倉庫ID');
            $table->timestamps();
        });

        // ENUMカラムはBlueprintで追加できないためraw SQL
        DB::statement("
            ALTER TABLE wms_warehouse_settings
            ADD COLUMN confirmation_level ENUM('STATUS1', 'STATUS2', 'STATUS3')
                NOT NULL DEFAULT 'STATUS2'
                COMMENT '確定レベル: STATUS1=候補表示, STATUS2=承認まで, STATUS3=確定まで'
                AFTER warehouse_id
        ");

        // 2. wms_contractor_warehouse_settings から confirmation_level を削除
        DB::statement('DROP INDEX idx_wcws_confirmation_level ON wms_contractor_warehouse_settings');
        DB::statement('ALTER TABLE wms_contractor_warehouse_settings DROP COLUMN confirmation_level');
    }

    public function down(): void
    {
        // confirmation_level を戻す
        DB::statement("
            ALTER TABLE wms_contractor_warehouse_settings
            ADD COLUMN confirmation_level ENUM('STATUS1', 'STATUS2', 'STATUS3')
                NOT NULL DEFAULT 'STATUS1'
                COMMENT '確定レベル: STATUS1=候補表示, STATUS2=承認まで, STATUS3=確定まで'
                AFTER designated_code
        ");
        DB::statement('CREATE INDEX idx_wcws_confirmation_level ON wms_contractor_warehouse_settings (confirmation_level)');

        Schema::connection($this->connection)->dropIfExists('wms_warehouse_settings');
    }
};
