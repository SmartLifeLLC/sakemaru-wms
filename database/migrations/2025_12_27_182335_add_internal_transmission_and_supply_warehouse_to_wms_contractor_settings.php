<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * transmission_typeにINTERNAL（倉庫間移動）を追加
 * supply_warehouse_idカラムを追加（INTERNAL時の供給倉庫）
 * wms_contractor_warehouse_mappingsテーブルを削除
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        // 1. transmission_type enumにINTERNALを追加
        DB::connection($this->connection)->statement(
            "ALTER TABLE wms_contractor_settings MODIFY COLUMN transmission_type ENUM('JX_FINET', 'FTP', 'MANUAL_CSV', 'INTERNAL') DEFAULT 'MANUAL_CSV' COMMENT '送信方式'"
        );

        // 2. supply_warehouse_idカラムを追加
        Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('supply_warehouse_id')
                ->nullable()
                ->after('wms_order_ftp_setting_id')
                ->comment('供給倉庫ID（INTERNAL時）');
        });

        // 3. wms_contractor_warehouse_mappingsテーブルを削除
        Schema::connection($this->connection)->dropIfExists('wms_contractor_warehouse_mappings');
    }

    public function down(): void
    {
        // 1. wms_contractor_warehouse_mappingsテーブルを復元
        Schema::connection($this->connection)->create('wms_contractor_warehouse_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contractor_id')->unique()->comment('発注先ID');
            $table->unsignedBigInteger('warehouse_id')->comment('マッピング先倉庫ID');
            $table->timestamps();

            $table->index('warehouse_id', 'idx_warehouse');
        });

        // 2. supply_warehouse_idカラムを削除
        Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table) {
            $table->dropColumn('supply_warehouse_id');
        });

        // 3. transmission_typeからINTERNALを削除
        DB::connection($this->connection)->statement(
            "ALTER TABLE wms_contractor_settings MODIFY COLUMN transmission_type ENUM('JX_FINET', 'FTP', 'MANUAL_CSV') DEFAULT 'MANUAL_CSV' COMMENT '送信方式'"
        );
    }
};
