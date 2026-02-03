<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. CANCELLED ステータスのレコードを削除（物理削除）
        DB::connection('sakemaru')
            ->table('wms_shortage_allocations')
            ->where('status', 'CANCELLED')
            ->delete();

        // 2. assign_qty_each カラムが存在する場合のみリネーム
        $hasAssignQtyEach = DB::connection('sakemaru')
            ->select("SHOW COLUMNS FROM wms_shortage_allocations LIKE 'assign_qty_each'");

        if (! empty($hasAssignQtyEach)) {
            Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
                $table->renameColumn('assign_qty_each', 'assign_qty');
            });
        }

        // 3. status enum から CANCELLED を削除
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_shortage_allocations
            MODIFY COLUMN status ENUM('PENDING', 'RESERVED', 'PICKING', 'FULFILLED', 'SHORTAGE')
            DEFAULT 'PENDING'
            COMMENT '代理出荷ステータス'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. status enum に CANCELLED を戻す
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_shortage_allocations
            MODIFY COLUMN status ENUM('PENDING', 'RESERVED', 'PICKING', 'FULFILLED', 'SHORTAGE', 'CANCELLED')
            DEFAULT 'PENDING'
            COMMENT '代理出荷ステータス'
        ");

        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            // 2. assign_qty を assign_qty_each に戻す
            $table->renameColumn('assign_qty', 'assign_qty_each');
        });
    }
};
