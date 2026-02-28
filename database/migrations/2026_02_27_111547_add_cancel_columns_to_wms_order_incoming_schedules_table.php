<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ステータスENUMに PARTIAL_CANCELLED を追加
        DB::statement("
            ALTER TABLE wms_order_incoming_schedules
            MODIFY COLUMN status ENUM('PENDING','PARTIAL','CONFIRMED','TRANSMITTED','CANCELLED','PARTIAL_CANCELLED')
                NOT NULL DEFAULT 'PENDING'
        ");

        // キャンセル関連カラム追加
        Schema::table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->dateTime('cancelled_at')->nullable()->after('status')->comment('キャンセル日時');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at')->comment('キャンセル者ID');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by')->comment('キャンセル理由');

            $table->index(['status', 'cancelled_at'], 'idx_ois_status_cancelled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->dropIndex('idx_ois_status_cancelled');
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });

        DB::statement("
            ALTER TABLE wms_order_incoming_schedules
            MODIFY COLUMN status ENUM('PENDING','PARTIAL','CONFIRMED','TRANSMITTED','CANCELLED')
                NOT NULL DEFAULT 'PENDING'
        ");
    }
};
