<?php

namespace App\Services\AutoOrder;

/** 発注検討専用。元の統計表・受払の定義は変更しない。 */
final class OrderReviewSalesSql
{
    public const VIEW = 'wms_v_order_review_sales_lines';

    /** 訂正元IDで作成経路を継承し、相殺明細も符号付きで集計する。伝票番号の一致だけでは継承しない。 */
    public static function select(): string
    {
        return <<<'SQL'
WITH RECURSIVE transfer_lineage AS (
    SELECT id AS trade_id, id AS ancestor_trade_id, origin_trade_id AS parent_trade_id, client_id
    FROM trades WHERE trade_category = 'STOCK_TRANSFER'
    UNION DISTINCT
    SELECT lineage.trade_id, parent.id, parent.origin_trade_id, lineage.client_id
    FROM transfer_lineage lineage
    JOIN trades parent ON parent.id = lineage.parent_trade_id
      AND parent.client_id = lineage.client_id AND parent.trade_category = 'STOCK_TRANSFER'
)
SELECT e.client_id, e.delivered_date AS business_date, e.warehouse_id, ti.item_id,
       CAST(COALESCE(ti.total_piece_quantity, 0) AS SIGNED) AS shipped_piece_qty
FROM earnings e
JOIN trade_items ti ON ti.trade_id = e.trade_id AND ti.is_active = 1
JOIN warehouses w ON w.id = e.warehouse_id
LEFT JOIN delivery_courses dc ON dc.id = e.delivery_course_id
LEFT JOIN warehouses cw ON cw.id = dc.warehouse_id
LEFT JOIN warehouses sw ON sw.id = cw.stock_warehouse_id AND cw.is_virtual = 1
WHERE e.is_active = 1 AND ti.item_id IS NOT NULL
  AND NOT (w.code = 91 AND (COALESCE(cw.code, 0) IN (10,22) OR COALESCE(sw.code, 0) IN (10,22)))
UNION ALL
SELECT r.client_id, r.shipped_date, r.warehouse_id, ti.item_id,
       CAST(COALESCE(ti.total_piece_quantity, 0) AS SIGNED)
FROM retails r
JOIN trades t ON t.id = r.trade_id AND t.is_active = 1 AND t.trade_category = 'RETAIL'
JOIN trade_items ti ON ti.trade_id = r.trade_id AND ti.is_active = 1
WHERE ti.item_id IS NOT NULL
UNION ALL
SELECT st.client_id, COALESCE(st.picking_date, st.delivered_date), st.from_warehouse_id, ti.item_id,
       CAST(COALESCE(ti.total_piece_quantity, 0) AS SIGNED)
FROM stock_transfers st
JOIN trades t ON t.id = st.trade_id AND t.is_active = 1
  AND t.client_id = st.client_id AND t.trade_category = 'STOCK_TRANSFER'
JOIN trade_items ti ON ti.trade_id = st.trade_id AND ti.is_active = 1
WHERE st.is_active = 1 AND ti.item_id IS NOT NULL
AND EXISTS (
    SELECT 1 FROM transfer_lineage lineage
    JOIN stock_transfers source_st ON source_st.trade_id = lineage.ancestor_trade_id
      AND source_st.client_id = lineage.client_id
    JOIN stock_transfer_queue q ON q.stock_transfer_id = source_st.id AND q.client_id = lineage.client_id
    WHERE lineage.trade_id = t.id AND lineage.client_id = st.client_id
      AND COALESCE(q.action_type, 'CREATE') = 'CREATE' AND q.is_success = 1
      AND (
        (q.request_id LIKE 'transfer-create-%' AND EXISTS (
            SELECT 1 FROM transfer_lineage waved_lineage
            JOIN stock_transfers waved_st ON waved_st.trade_id = waved_lineage.trade_id
              AND waved_st.client_id = waved_lineage.client_id
            JOIN wms_picking_item_results pir ON pir.stock_transfer_id = waved_st.id
            JOIN wms_picking_tasks pt ON pt.id = pir.picking_task_id
            JOIN wms_waves wave ON wave.id = pt.wave_id AND wave.status <> 'CANCELLED'
            WHERE waved_lineage.ancestor_trade_id = lineage.ancestor_trade_id
              AND waved_lineage.client_id = lineage.client_id AND pir.source_type = 'STOCK_TRANSFER'
        ))
        OR (q.request_id LIKE 'proxy-shipment-%' AND EXISTS (
            SELECT 1 FROM wms_shortage_allocations a
            JOIN wms_shortages s ON s.id = a.shortage_id
            JOIN wms_waves wave ON wave.id = s.wave_id AND wave.status <> 'CANCELLED'
            WHERE a.id = CAST(SUBSTRING(q.request_id, CHAR_LENGTH('proxy-shipment-') + 1) AS UNSIGNED)
              AND q.request_id = CONCAT('proxy-shipment-', a.id)
        ))
        OR (q.request_id LIKE 'wh-mismatch-%' AND EXISTS (
            SELECT 1 FROM wms_picking_item_results pir
            JOIN wms_picking_tasks pt ON pt.id = pir.picking_task_id
            JOIN wms_waves wave ON wave.id = pt.wave_id AND wave.status <> 'CANCELLED'
            WHERE pir.earning_id = CAST(SUBSTRING(q.request_id, CHAR_LENGTH('wh-mismatch-') + 1) AS UNSIGNED)
              AND q.request_id = CONCAT('wh-mismatch-', pir.earning_id)
        ))
      )
)
SQL;
    }

    public static function updateDataQuery(string $query): string
    {
        if (str_contains($query, self::VIEW)) {
            return $query;
        }
        // Only replace the weekly performance source, not the on-day transfer columns.
        $updated = preg_replace('/\bFROM\s+stats_item_warehouse_daily_sales\s+d\b/i', 'FROM '.self::VIEW.' d', $query, -1, $count);
        if ($count !== 1) {
            throw new \RuntimeException('倉庫別発注検討データの実績集計箇所を一意に特定できません。SQLを確認してください。');
        }

        return $updated;
    }
}
