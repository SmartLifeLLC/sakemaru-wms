<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_warehouse_contractor_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->unsignedBigInteger('contractor_id')->comment('発注先ID');
            $table->enum('transmission_type', ['JX_FINET', 'MANUAL_CSV', 'FTP'])->default('MANUAL_CSV')->comment('送信方式');
            $table->unsignedBigInteger('wms_order_jx_setting_id')->nullable()->comment('JX設定ID');
            $table->unsignedBigInteger('wms_order_ftp_setting_id')->nullable()->comment('FTP設定ID');
            $table->string('format_strategy_class', 255)->nullable()->comment('データ生成クラス名');
            $table->boolean('is_active')->default(true)->comment('有効フラグ');
            $table->timestamps();

            $table->unique(['warehouse_id', 'contractor_id'], 'uk_warehouse_contractor');
            $table->index('contractor_id', 'idx_contractor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_warehouse_contractor_settings');
    }
};
