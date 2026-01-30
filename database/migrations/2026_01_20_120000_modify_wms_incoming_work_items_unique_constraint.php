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
     * 既存の unique_schedule_picker_status 制約を削除し、
     * WORKING ステータスのみに適用される制約に変更する。
     * これにより、同一スケジュール/ピッカーで複数の COMPLETED 作業データを持てるようになる。
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_incoming_work_items', function (Blueprint $table) {
            // 既存のユニーク制約を削除
            $table->dropUnique('unique_schedule_picker_status');
        });

        // WORKING ステータスのみユニーク制約を適用（部分インデックス）
        // MySQL は部分インデックスをサポートしないため、トリガーまたはアプリケーションレベルでチェック
        // 代わりに incoming_schedule_id + status で WORKING のみの制約として、
        // アプリケーション側でチェックを行う

        Schema::connection('sakemaru')->table('wms_incoming_work_items', function (Blueprint $table) {
            // WORKING 状態の作業データは同一スケジュールに1つのみ許可するため、
            // incoming_schedule_id + status でインデックスを作成
            $table->index(['incoming_schedule_id', 'status'], 'idx_schedule_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_incoming_work_items', function (Blueprint $table) {
            $table->dropIndex('idx_schedule_status');
        });

        Schema::connection('sakemaru')->table('wms_incoming_work_items', function (Blueprint $table) {
            $table->unique(['incoming_schedule_id', 'picker_id', 'status'], 'unique_schedule_picker_status');
        });
    }
};
