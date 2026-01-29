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
        Schema::table('wms_auto_order_job_controls', function (Blueprint $table) {
            // 確定状態カラム追加（PENDING=確定待ち, CONFIRMED=確定済み, CANCELLED=キャンセル）
            $table->string('settlement_status', 20)->default('PENDING')
                ->after('status')
                ->comment('確定状態');

            // ORDER_CALCが参照した在庫スナップショットのjob_id
            $table->unsignedBigInteger('snapshot_job_id')->nullable()
                ->after('settlement_status')
                ->comment('参照した在庫スナップショットのjob_id');

            // インデックス追加
            $table->index('settlement_status', 'idx_settlement_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wms_auto_order_job_controls', function (Blueprint $table) {
            $table->dropIndex('idx_settlement_status');
            $table->dropColumn(['settlement_status', 'snapshot_job_id']);
        });
    }
};
