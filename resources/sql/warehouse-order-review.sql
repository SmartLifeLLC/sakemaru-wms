-- Production data query 3, verified 2026-10-29. Used only by the WMS order review export.
WITH raw_params AS (
    SELECT
    NULLIF(?, '') AS target_date_value,
    COALESCE(NULLIF(?, ''), '91') AS warehouse_code
),
params AS (
    SELECT
    COALESCE(
    CASE WHEN target_date_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN STR_TO_DATE(target_date_value, '%Y-%m-%d') END,
    CASE WHEN target_date_value REGEXP '^[0-9]{8}$' THEN STR_TO_DATE(target_date_value, '%Y%m%d') END,
    DATE(target_date_value),
    (SELECT DATE(cs.system_date)
FROM client_settings cs
WHERE cs.client_id = __CLIENT_ID__ LIMIT 1)
    ) AS target_date,
    warehouse_code
FROM raw_params
),
target_warehouse AS (
    SELECT w.id, w.code
FROM warehouses w
CROSS JOIN params p
WHERE w.client_id = __CLIENT_ID__
    AND w.code = p.warehouse_code
    LIMIT 1 ),
earning_line_pieces AS (
    SELECT
    e.account_date,
    ti.item_id,
    COALESCE(
    ti.total_piece_quantity,
    CASE
    WHEN ti.quantity_type = 'CASE' THEN ti.quantity * COALESCE(NULLIF(ti.capacity_case, 0), NULLIF(i.capacity_case, 0), 1)
    WHEN ti.quantity_type = 'CARTON' THEN ti.quantity * COALESCE(NULLIF(ti.capacity_carton, 0), NULLIF(i.capacity_carton, 0), 1)
    ELSE ti.quantity
    END
    ) AS piece_qty,
    COALESCE(NULLIF(ti.capacity_case, 0), NULLIF(i.capacity_case, 0), 1) AS capacity_case
FROM earnings e
INNER JOIN trades t ON t.id = e.trade_id
INNER JOIN trade_items ti ON ti.trade_id = t.id
INNER JOIN items i ON i.id = ti.item_id
INNER JOIN target_warehouse tw ON tw.id = e.warehouse_id
CROSS JOIN params p
WHERE e.client_id = __CLIENT_ID__
    AND t.client_id = e.client_id
    AND ti.client_id = e.client_id
    AND i.client_id = e.client_id
    AND e.is_active = 1
    AND t.is_active = 1
    AND t.is_latest = 1
    AND ti.is_active = 1
    AND t.trade_category = 'EARNING'
    AND e.account_date >= DATE_SUB(DATE_FORMAT(p.target_date, '%Y-%m-01'), INTERVAL 1 MONTH)
    AND e.account_date <= LAST_DAY(p.target_date) ),
earning_lines AS (
    SELECT
    account_date,
    item_id,
    piece_qty,
    piece_qty / capacity_case AS case_qty
FROM earning_line_pieces ),
transfer_line_pieces AS (
    SELECT
    st.delivered_date,
    ti.item_id,
    COALESCE(
    ti.total_piece_quantity,
    CASE
    WHEN ti.quantity_type = 'CASE' THEN ti.quantity * COALESCE(NULLIF(ti.capacity_case, 0), NULLIF(i.capacity_case, 0), 1)
    WHEN ti.quantity_type = 'CARTON' THEN ti.quantity * COALESCE(NULLIF(ti.capacity_carton, 0), NULLIF(i.capacity_carton, 0), 1)
    ELSE ti.quantity
    END
    ) AS piece_qty,
    COALESCE(NULLIF(ti.capacity_case, 0), NULLIF(i.capacity_case, 0), 1) AS capacity_case
FROM stock_transfers st
INNER JOIN trades t ON t.id = st.trade_id
INNER JOIN trade_items ti ON ti.trade_id = t.id
INNER JOIN items i ON i.id = ti.item_id
INNER JOIN target_warehouse tw ON tw.id = st.from_warehouse_id
CROSS JOIN params p
WHERE st.client_id = __CLIENT_ID__
    AND t.client_id = st.client_id
    AND ti.client_id = st.client_id
    AND i.client_id = st.client_id
    AND st.is_active = 1
    AND t.is_active = 1
    AND t.is_latest = 1
    AND ti.is_active = 1
    AND t.trade_category = 'STOCK_TRANSFER'
    AND st.delivered_date = p.target_date ),
transfer_lines AS (
    SELECT
    delivered_date,
    item_id,
    piece_qty,
    piece_qty / capacity_case AS case_qty
FROM transfer_line_pieces ),
arrival_wms_line_pieces AS (
    SELECT
    wms.expected_arrival_date AS planned_delivery_date,
    wms.item_id,
    CASE
    WHEN wms.quantity_type = 'CASE' THEN wms.expected_quantity * COALESCE(NULLIF(i.capacity_case, 0), 1)
    WHEN wms.quantity_type = 'CARTON' THEN wms.expected_quantity * COALESCE(NULLIF(i.capacity_carton, 0), NULLIF(i.capacity_case, 0), 1)
    ELSE wms.expected_quantity
    END AS piece_qty,
    COALESCE(NULLIF(i.capacity_case, 0), 1) AS capacity_case
FROM wms_order_incoming_schedules wms
INNER JOIN items i
    ON i.id = wms.item_id
INNER JOIN target_warehouse tw ON tw.id = wms.warehouse_id
CROSS JOIN params p
WHERE i.client_id = __CLIENT_ID__
    AND wms.expected_quantity > 0
    AND wms.expected_arrival_date >= p.target_date
    AND wms.cancelled_at IS NULL
    AND wms.status NOT IN ('CANCELLED', 'PARTIAL_CANCELLED')
),
arrival_order_line_pieces AS (
    SELECT
    o.planned_delivery_date,
    ti.item_id,
    COALESCE(
    ti.total_piece_quantity,
    CASE
    WHEN ti.quantity_type = 'CASE' THEN ti.quantity * COALESCE(NULLIF(ti.capacity_case, 0), NULLIF(i.capacity_case, 0), 1)
    WHEN ti.quantity_type = 'CARTON' THEN ti.quantity * COALESCE(NULLIF(ti.capacity_carton, 0), NULLIF(i.capacity_carton, 0), 1)
    ELSE ti.quantity
    END
    ) AS piece_qty,
    COALESCE(NULLIF(ti.capacity_case, 0), NULLIF(i.capacity_case, 0), 1) AS capacity_case
FROM orders o
INNER JOIN trades t ON t.id = o.trade_id
INNER JOIN trade_items ti ON ti.trade_id = t.id
INNER JOIN items i ON i.id = ti.item_id
INNER JOIN target_warehouse tw ON tw.id = o.warehouse_id
CROSS JOIN params p
WHERE o.client_id = __CLIENT_ID__
    AND t.client_id = o.client_id
    AND ti.client_id = o.client_id
    AND i.client_id = o.client_id
    AND o.is_active = 1
    AND t.is_active = 1
    AND t.is_latest = 1
    AND ti.is_active = 1
    AND t.trade_category = 'ORDER'
    AND o.planned_delivery_date >= p.target_date
),
arrival_suggestion_line_pieces AS (
    SELECT
    osi.planned_delivery_date,
    osi.item_id,
    osi.arrival_plan_quantity AS piece_qty,
    COALESCE(NULLIF(i.capacity_case, 0), 1) AS capacity_case
FROM order_suggestions os
INNER JOIN order_suggestion_items osi
    ON osi.order_suggestion_id = os.id
    AND osi.client_id = os.client_id
INNER JOIN items i
    ON i.id = osi.item_id
    AND i.client_id = os.client_id
INNER JOIN target_warehouse tw ON tw.id = os.warehouse_id
CROSS JOIN params p
WHERE os.client_id = __CLIENT_ID__
    AND os.is_active = 1
    AND osi.is_active = 1
    AND osi.arrival_plan_quantity > 0
    AND os.ordering_date = p.target_date
    AND osi.planned_delivery_date >= p.target_date
),
arrival_line_pieces AS (
    SELECT planned_delivery_date, item_id, piece_qty, capacity_case
FROM arrival_wms_line_pieces
    UNION ALL
    SELECT aol.planned_delivery_date, aol.item_id, aol.piece_qty, aol.capacity_case
FROM arrival_order_line_pieces aol
WHERE NOT EXISTS (
    SELECT 1
FROM arrival_wms_line_pieces awl
WHERE awl.item_id = aol.item_id
    AND awl.planned_delivery_date = aol.planned_delivery_date
    )
    UNION ALL
    SELECT asl.planned_delivery_date, asl.item_id, asl.piece_qty, asl.capacity_case
FROM arrival_suggestion_line_pieces asl
WHERE NOT EXISTS (
    SELECT 1
FROM arrival_order_line_pieces aol
WHERE aol.item_id = asl.item_id
    AND aol.planned_delivery_date = asl.planned_delivery_date
    )
    AND NOT EXISTS (
    SELECT 1
FROM arrival_wms_line_pieces awl
WHERE awl.item_id = asl.item_id
    AND awl.planned_delivery_date = asl.planned_delivery_date
    )
),
arrival_schedule AS (
    SELECT
    planned_delivery_date AS `入荷予定日`,
    item_id,
    NULLIF(ROUND(SUM(piece_qty / capacity_case), 1), 0) AS `入荷予定CS`
FROM arrival_line_pieces
GROUP BY planned_delivery_date, item_id ),
order_suggestion_candidate_lines AS (
    SELECT
    os.ordering_date,
    osi.item_id,
    CASE
    WHEN osi.quantity_type = 'CASE' THEN osi.quantity
    WHEN osi.quantity_type = 'PIECE' THEN osi.quantity / COALESCE(NULLIF(i.capacity_case, 0), 1)
    ELSE osi.quantity
    END AS candidate_case_qty
FROM order_suggestions os
INNER JOIN order_suggestion_items osi
    ON osi.order_suggestion_id = os.id
    AND osi.client_id = os.client_id
INNER JOIN items i
    ON i.id = osi.item_id
    AND i.client_id = os.client_id
INNER JOIN target_warehouse tw ON tw.id = os.warehouse_id
CROSS JOIN params p
WHERE os.client_id = __CLIENT_ID__
    AND os.is_active = 1
    AND osi.is_active = 1
    AND os.ordering_date >= DATE_SUB(p.target_date, INTERVAL 29 DAY)
    AND os.ordering_date <= p.target_date ),
order_suggestion_candidates AS (
    SELECT
    oscl.item_id,
    NULLIF(ROUND(SUM(CASE WHEN oscl.ordering_date = p.target_date THEN oscl.candidate_case_qty ELSE 0 END), 1), 0) AS candidate_today_case_qty,
    NULLIF(ROUND(SUM(CASE WHEN oscl.ordering_date = DATE_SUB(p.target_date, INTERVAL 1 DAY) THEN oscl.candidate_case_qty ELSE 0 END), 1), 0) AS candidate_previous_day_case_qty,
    NULLIF(ROUND(SUM(CASE WHEN oscl.ordering_date = DATE_SUB(p.target_date, INTERVAL 2 DAY) THEN oscl.candidate_case_qty ELSE 0 END), 1), 0) AS candidate_two_days_ago_case_qty,
    NULLIF(ROUND(SUM(CASE WHEN oscl.ordering_date BETWEEN DATE_SUB(p.target_date, INTERVAL 2 DAY) AND p.target_date THEN oscl.candidate_case_qty ELSE 0 END), 1), 0) AS candidate_three_day_case_qty,
    NULLIF(ROUND(SUM(CASE WHEN oscl.ordering_date BETWEEN DATE_SUB(p.target_date, INTERVAL 4 DAY) AND p.target_date THEN oscl.candidate_case_qty ELSE 0 END), 1), 0) AS candidate_five_day_case_qty,
    NULLIF(ROUND(SUM(CASE WHEN oscl.ordering_date BETWEEN DATE_SUB(p.target_date, INTERVAL 6 DAY) AND p.target_date THEN oscl.candidate_case_qty ELSE 0 END), 1), 0) AS candidate_seven_day_case_qty,
    NULLIF(ROUND(SUM(CASE WHEN oscl.ordering_date BETWEEN DATE_SUB(p.target_date, INTERVAL 29 DAY) AND p.target_date THEN oscl.candidate_case_qty ELSE 0 END), 1), 0) AS candidate_thirty_day_case_qty
FROM order_suggestion_candidate_lines oscl
CROSS JOIN params p
GROUP BY oscl.item_id ),
base_item_ids AS (
    SELECT DISTINCT item_id
FROM (
    SELECT rs.item_id
FROM real_stocks rs
INNER JOIN target_warehouse tw ON tw.id = rs.warehouse_id
WHERE rs.client_id = __CLIENT_ID__
    UNION
    SELECT item_id
FROM earning_lines
    UNION
    SELECT item_id
FROM transfer_lines
    UNION
    SELECT item_id
FROM arrival_line_pieces
    UNION
    SELECT item_id
FROM order_suggestion_candidate_lines
    ) source_items ),
default_stock_location AS (
    SELECT
    idl.warehouse_id,
    idl.item_id,
    TRIM(CONCAT_WS(' ', NULLIF(l.code1, ''), NULLIF(l.code2, ''), NULLIF(l.code3, ''))) AS shelf_number
FROM item_incoming_default_locations idl
LEFT JOIN locations l
    ON l.id = idl.location_id
    AND l.client_id = __CLIENT_ID__
),
stock_summary AS (
    SELECT
    rs.item_id,
    SUM(rs.current_quantity) AS current_piece_qty,
    MIN(NULLIF(dsl.shelf_number, '')) AS shelf_number
FROM real_stocks rs
INNER JOIN target_warehouse tw ON tw.id = rs.warehouse_id
LEFT JOIN default_stock_location dsl
    ON dsl.warehouse_id = rs.warehouse_id
    AND dsl.item_id = rs.item_id
WHERE rs.client_id = __CLIENT_ID__
GROUP BY rs.item_id
),
item_contractor_one AS (
    SELECT *
FROM (
    SELECT
    ic.*,
    ROW_NUMBER() OVER (
    PARTITION BY ic.client_id, ic.item_id, ic.warehouse_id
ORDER BY ic.id
    ) AS rn
FROM item_contractors ic
INNER JOIN target_warehouse tw ON tw.id = ic.warehouse_id
WHERE ic.client_id = __CLIENT_ID__
    ) ranked_item_contractors
WHERE rn = 1 ),
piece_jan AS (
    SELECT item_id, search_string
FROM (
    SELECT
    isi.item_id,
    isi.search_string,
    ROW_NUMBER() OVER (
    PARTITION BY isi.item_id
ORDER BY COALESCE(isi.priority, 999999), isi.id
    ) AS rn
FROM item_search_information isi
WHERE isi.client_id = __CLIENT_ID__
    AND isi.is_active = 1
    AND isi.is_in_use = 1
    AND isi.code_type = 'JAN'
    AND isi.quantity_type = 'PIECE'
    ) ranked_piece_jan
WHERE rn = 1 ),
today_sales AS (
    SELECT
    el.item_id,
    NULLIF(ROUND(SUM(el.case_qty), 1), 0) AS order_case_qty,
    NULLIF(ROUND(SUM(el.piece_qty), 0), 0) AS order_piece_qty
FROM earning_lines el
CROSS JOIN params p
WHERE el.account_date = p.target_date
GROUP BY el.item_id ),
today_transfers AS (
    SELECT
    tl.item_id,
    NULLIF(ROUND(SUM(tl.case_qty), 1), 0) AS transfer_case_qty,
    NULLIF(ROUND(SUM(tl.piece_qty), 0), 0) AS transfer_piece_qty
FROM transfer_lines tl
GROUP BY tl.item_id ),
piece_sales AS (
    SELECT
    d.item_id,
    COALESCE(SUM(CASE WHEN d.business_date BETWEEN DATE_SUB(p.target_date, INTERVAL 6 DAY) AND p.target_date THEN d.shipped_piece_qty ELSE 0 END), 0) AS week1_piece_qty,
    COALESCE(SUM(CASE WHEN d.business_date BETWEEN DATE_SUB(p.target_date, INTERVAL 13 DAY) AND DATE_SUB(p.target_date, INTERVAL 7 DAY) THEN d.shipped_piece_qty ELSE 0 END), 0) AS week2_piece_qty,
    COALESCE(SUM(CASE WHEN d.business_date BETWEEN DATE_SUB(p.target_date, INTERVAL 20 DAY) AND DATE_SUB(p.target_date, INTERVAL 14 DAY) THEN d.shipped_piece_qty ELSE 0 END), 0) AS week3_piece_qty,
    COALESCE(SUM(CASE WHEN d.business_date >= DATE_SUB(DATE_FORMAT(p.target_date, '%Y-%m-01'), INTERVAL 1 MONTH) AND d.business_date < DATE_FORMAT(p.target_date, '%Y-%m-01') THEN d.shipped_piece_qty ELSE 0 END), 0) AS previous_month_piece_qty
FROM wms_v_order_review_sales_lines d
INNER JOIN target_warehouse tw ON tw.id = d.warehouse_id
CROSS JOIN params p
WHERE d.business_date BETWEEN DATE_SUB(DATE_FORMAT(p.target_date, '%Y-%m-01'), INTERVAL 1 MONTH) AND p.target_date
GROUP BY d.item_id
)
SELECT
    p.target_date AS `日付`,
    '' AS `連番`,
    COALESCE(c.code, '') AS `発注先CD`,
    COALESCE(NULLIF(contractor_partner.abbreviation, ''), '') AS `発注先名`,
    COALESCE(sub_partner.code, item_supplier_partner.code, '') AS `仕入先CD`,
    CASE
    WHEN sub_partner.id IS NOT NULL THEN COALESCE(NULLIF(sub_partner.abbreviation, ''), '')
    ELSE COALESCE(NULLIF(item_supplier_partner.abbreviation, ''), '')
    END AS `仕入先名`,
    COALESCE(piece_jan.search_string, '') AS `JANCD`,
    i.code AS `単品CD`,
    i.name AS `商品名`,
    i.capacity_case AS `入数`,
    COALESCE(
    ss.shelf_number,
    CASE
    WHEN NULLIF(i.main_shelf_number, '') IS NULL THEN NULL
    WHEN CHAR_LENGTH(i.main_shelf_number) < 6 THEN RPAD(i.main_shelf_number, 6, '0')
    ELSE i.main_shelf_number
    END,
    ''
    ) AS `棚番`, COALESCE(ic.safety_stock, 0) AS `発注点`,
    ROUND(COALESCE(ss.current_piece_qty, 0) / COALESCE(NULLIF(i.capacity_case, 0), 1), 1) AS `在庫CS`,
    COALESCE(ss.current_piece_qty, 0) AS `在庫バラ`,
    ts.order_case_qty AS `受注CS`,
    ts.order_piece_qty AS `受注バラ`,
    tt.transfer_case_qty AS `移動CS`,
    tt.transfer_piece_qty AS `移動バラ`,
    osc.candidate_today_case_qty AS `発注候補当日`,
    osc.candidate_previous_day_case_qty AS `発注候補前日`,
    osc.candidate_two_days_ago_case_qty AS `発注候補前々日`,
    osc.candidate_three_day_case_qty AS `発注候補3日累計`,
    osc.candidate_five_day_case_qty AS `発注候補5日累計`,
    osc.candidate_seven_day_case_qty AS `発注候補7日累計`,
    osc.candidate_thirty_day_case_qty AS `発注候補30日累計`,
    COALESCE(ps.week1_piece_qty, 0) AS `1週`,
    COALESCE(ps.week2_piece_qty, 0) AS `2週`,
    COALESCE(ps.week3_piece_qty, 0) AS `3週`,
    COALESCE(ps.previous_month_piece_qty, 0) AS `前月`,
    arr.`入荷予定日`,
    arr.`入荷予定CS`, COALESCE(ic.note, '') AS `備考`,
    i.id AS `__item_id`,
    tw.code AS `__warehouse_code`,
    p.target_date AS `__target_date`,
    COALESCE(m.code, 0) AS `__sort_manufacturer_code`,
    i.code AS `__sort_item_code`
FROM base_item_ids bii
INNER JOIN items i ON i.id = bii.item_id
CROSS JOIN target_warehouse tw
CROSS JOIN params p
LEFT JOIN manufacturers m
    ON m.id = i.manufacturer_id
    AND m.client_id = __CLIENT_ID__
LEFT JOIN stock_summary ss ON ss.item_id = i.id
LEFT JOIN item_contractor_one ic ON ic.item_id = i.id
LEFT JOIN piece_jan ON piece_jan.item_id = i.id
LEFT JOIN contractors c
    ON c.id = ic.contractor_id
    AND c.client_id = __CLIENT_ID__
LEFT JOIN suppliers contractor_supplier
    ON contractor_supplier.id = c.supplier_id
    AND contractor_supplier.client_id = __CLIENT_ID__
LEFT JOIN partners contractor_partner
    ON contractor_partner.id = contractor_supplier.partner_id
    AND contractor_partner.client_id = __CLIENT_ID__
LEFT JOIN suppliers sub_supplier
    ON sub_supplier.id = ic.supplier_id
    AND sub_supplier.client_id = __CLIENT_ID__
LEFT JOIN partners sub_partner
    ON sub_partner.id = sub_supplier.partner_id
    AND sub_partner.client_id = __CLIENT_ID__
LEFT JOIN suppliers item_supplier
    ON item_supplier.id = i.supplier_id
    AND item_supplier.client_id = __CLIENT_ID__
LEFT JOIN partners item_supplier_partner
    ON item_supplier_partner.id = item_supplier.partner_id
    AND item_supplier_partner.client_id = __CLIENT_ID__
LEFT JOIN piece_sales ps ON ps.item_id = i.id
LEFT JOIN today_sales ts ON ts.item_id = i.id
LEFT JOIN today_transfers tt ON tt.item_id = i.id
LEFT JOIN order_suggestion_candidates osc ON osc.item_id = i.id
LEFT JOIN arrival_schedule arr ON arr.item_id = i.id
WHERE i.client_id = __CLIENT_ID__
    AND i.is_active = 1
    AND i.type IN ('ALCOHOL', 'NOT_ALCOHOL')
    AND i.is_managed_stock = 1
ORDER BY COALESCE(c.code, 0), COALESCE(sub_partner.code, item_supplier_partner.code, 0), CASE WHEN TRIM(COALESCE(ic.note, '')) = '' THEN 1 ELSE 0 END, COALESCE(ic.note, ''), i.code, arr.`入荷予定日`
