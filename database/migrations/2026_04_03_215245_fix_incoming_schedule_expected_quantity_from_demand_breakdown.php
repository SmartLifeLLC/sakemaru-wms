<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * demand_breakdownルートで作成された入荷予定の expected_quantity を
     * order_candidate.order_quantity（単位調整後）に修正する。
     *
     * 原因: demand_breakdown.quantity は単位調整前の不足数が入っており、
     *       OrderExecutionService がそれをそのまま expected_quantity に使用していた。
     */
    public function up(): void
    {
        // PENDING/PARTIAL のみ修正（COMPLETED/CANCELLED は実績に影響するため触らない）
        DB::connection('sakemaru')->statement("
            UPDATE wms_order_incoming_schedules s
            INNER JOIN wms_order_candidates c ON s.order_candidate_id = c.id
            SET s.expected_quantity = c.order_quantity
            WHERE s.status IN ('PENDING', 'PARTIAL')
              AND c.demand_breakdown IS NOT NULL
              AND s.expected_quantity != c.order_quantity
        ");
    }

    public function down(): void
    {
        // データ修正のため、down は不要
    }
};
