<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CUTOFF_DATE = '2026-05-15';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::connection('sakemaru')->transaction(function (): void {
            DB::connection('sakemaru')->statement(<<<'SQL'
                UPDATE wms_shortages ws
                LEFT JOIN wms_picking_item_results pir
                    ON pir.id = ws.source_pick_result_id
                LEFT JOIN wms_picking_tasks pt
                    ON pt.id = pir.picking_task_id
                LEFT JOIN earnings e
                    ON e.id = ws.earning_id
                SET
                    ws.status = 'SHORTAGE',
                    ws.is_confirmed = 1,
                    ws.confirmed_at = COALESCE(ws.confirmed_at, NOW()),
                    ws.confirmed_by = COALESCE(ws.confirmed_by, 0),
                    ws.confirmed_user_id = COALESCE(ws.confirmed_user_id, 0),
                    ws.is_synced = 1,
                    ws.is_synced_at = COALESCE(ws.is_synced_at, NOW()),
                    ws.updated_at = NOW()
                WHERE COALESCE(ws.shipment_date, pt.shipment_date, e.delivered_date, DATE(ws.created_at)) < ?
                  AND (
                      ws.is_confirmed = 0
                      OR ws.is_synced = 0
                      OR ws.status IN ('BEFORE', 'REALLOCATING', 'PARTIAL_SHORTAGE')
                  )
            SQL, [self::CUTOFF_DATE]);

            DB::connection('sakemaru')->statement(<<<'SQL'
                UPDATE wms_shortage_allocations a
                INNER JOIN wms_shortages ws
                    ON ws.id = a.shortage_id
                LEFT JOIN wms_picking_item_results pir
                    ON pir.id = ws.source_pick_result_id
                LEFT JOIN wms_picking_tasks pt
                    ON pt.id = pir.picking_task_id
                LEFT JOIN earnings e
                    ON e.id = ws.earning_id
                SET
                    a.status = CASE
                        WHEN a.status IN ('PENDING', 'RESERVED', 'PICKING') THEN 'SHORTAGE'
                        ELSE a.status
                    END,
                    a.is_confirmed = 1,
                    a.confirmed_at = COALESCE(a.confirmed_at, NOW()),
                    a.confirmed_user_id = COALESCE(a.confirmed_user_id, 0),
                    a.is_finished = 1,
                    a.finished_at = COALESCE(a.finished_at, NOW()),
                    a.finished_user_id = COALESCE(a.finished_user_id, 0),
                    a.is_synced = 1,
                    a.is_synced_at = COALESCE(a.is_synced_at, NOW()),
                    a.updated_at = NOW()
                WHERE COALESCE(ws.shipment_date, pt.shipment_date, e.delivered_date, DATE(ws.created_at)) < ?
                  AND (
                      a.is_finished = 0
                      OR a.is_confirmed = 0
                      OR a.is_synced = 0
                      OR a.status IN ('PENDING', 'RESERVED', 'PICKING')
                  )
            SQL, [self::CUTOFF_DATE]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-time data patch only. Do not reopen closed shortage records on rollback.
    }
};
