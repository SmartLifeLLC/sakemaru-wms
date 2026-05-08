# 2026-05-09 本番WMS引当欠品 backfill 控え

- 対象DB: prd_hana
- 実行日時: 2026-05-09 07:51 JST
- 対象: wms_waves.shipping_date = 2026-05-09 の本日波動
- INSERT対象: wms_picking_item_results の引当欠品で、対応する wms_shortages が未作成のもの
- INSERT範囲: wms_shortages.id 237-326
- INSERT件数: 90
- allocation_shortage_qty合計: 354
- 更新/削除: なし。wms_shortages の INSERT のみ

## 整合性確認

- 数量不整合: 0件
- 参照欠落: 0件
- picking_item_results との不一致: 0件
- 重複キー wave_id + warehouse_id + item_id + trade_item_id: 0件
- 重要NULL: 0件
- 再dry-run未作成候補: 0件
- 欠品リスト表示条件（shortage_qty > 0 / status = BEFORE / 本日波動）: 90件すべて該当
- source_pick_result_id の重複: 0件
- 数量種別不正（CASE/PIECE/CARTON以外）: 0件
- タスク状態: PICKING_READY 89件 / PICKING 1件
- location_id NULL: 45件（planned_qty = 0 の完全引当欠品。棚未設定として表示される想定）
- location_id 参照欠落: 0件
- location_id 倉庫不一致: 0件
- wms_shortage_allocations 作成済み: 0件
- sync/confirmed状態: 90件すべて is_confirmed = 0 / is_synced = 0 / status = BEFORE

## 波動別

| wave_id | wave_no | 件数 | 引当欠品数量 |
|---:|---|---:|---:|
| 207 | W091-C910358-20260509-207 | 4 | 35 |
| 208 | W091-C910252-20260509-208 | 4 | 8 |
| 209 | W091-C910288-20260509-209 | 3 | 16 |
| 210 | W091-C910244-20260509-210 | 2 | 6 |
| 211 | W091-C910017-20260509-211 | 19 | 52 |
| 212 | W091-C910146-20260509-212 | 7 | 8 |
| 213 | W091-C910296-20260509-213 | 5 | 11 |
| 214 | W091-C910162-20260509-214 | 12 | 105 |
| 215 | W091-C911662-20260509-215 | 13 | 16 |
| 216 | W091-C910301-20260509-216 | 2 | 5 |
| 217 | W091-C910153-20260509-217 | 2 | 2 |
| 218 | W091-C910299-20260509-218 | 7 | 7 |
| 219 | W091-C910261-20260509-219 | 3 | 33 |
| 220 | W091-C910331-20260509-220 | 2 | 13 |
| 221 | W091-C910156-20260509-221 | 1 | 4 |
| 222 | W091-C910203-20260509-222 | 3 | 32 |
| 223 | W091-C912005-20260509-223 | 1 | 1 |

## 明細控え

- CSV: `/Users/jungsinyu/Projects/sakemaru-wms/storage/reports/20260509/prod-wms-shortages-backfill-20260509-ids-237-326.csv`
