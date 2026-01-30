<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contractor_id を nullable に変更
 *
 * TRANSFER タイプ（倉庫間移動）の入荷予定には発注先（contractor）がないため、
 * contractor_id を nullable にする必要がある。
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_order_incoming_schedules', function (Blueprint $table) {
            // contractor_id を nullable に変更（TRANSFER タイプは発注先がない）
            $table->unsignedBigInteger('contractor_id')->nullable()->comment('発注先ID（TRANSFERタイプはnull）')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_incoming_schedules', function (Blueprint $table) {
            // 元に戻す（NOT NULL）
            // 注意: 既存データにnullがある場合は失敗する
            $table->unsignedBigInteger('contractor_id')->nullable(false)->comment('発注先ID')->change();
        });
    }
};
