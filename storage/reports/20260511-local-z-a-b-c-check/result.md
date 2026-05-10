# Z-0-0 / A-B-C local check result

## 実施日
- 2026-05-11

## 対象
- LOCAL DB: `hana_local`
- 倉庫: 91
- Oracle: SSH tunnel経由 `127.0.0.1:11521`, `ORCL1.world`

## 1. Z-0-0 を正式引当ロケにする
確認結果:

| location_id | warehouse_id | floor_id | code | name | available_quantity_flags |
|---:|---:|---:|---|---|---:|
| 327 | 91 | 10 | Z-0-0 | デフォルト | 7 |

`Z-0-0` は LOCAL ではすでに 1F、CASE/PIECE/CARTON 引当可能に変更済み。

## 2. B1 が消えるか確認
現時点の分類:

| category | 件数 | 親在庫数量 | 現在pickable | Z許可後pickable | 不足数量 |
|---|---:|---:|---:|---:|---:|
| A_missing_lot_required | 30 | 337 | 0 | 0 | 337 |
| C_partial_lot_missing_required | 64 | 55,640 | 20,451 | 20,451 | 35,189 |
| D_ok_after_z_allowed | 3,252 | 508,734 | 618,916 | 618,916 | 0 |

B1/B2 は 0 件。Z-0-0 更新だけで救える対象は消えている。

## 3. A は lot 作成候補
Aをさらに分解:

| A詳細 | 件数 | 数量 |
|---|---:|---:|
| A1_no_active_available | 30 | 337 |

現在のA上位:

| item_code | current | reserved | available | active_available | pickable_available |
|---:|---:|---:|---:|---:|---:|
| 119687 | 19 | -38 | 57 | 0 | 0 |
| 211902 | 22 | -22 | 44 | 0 | 0 |
| 185195 | 34 | 0 | 34 | 0 | 0 |
| 171417 | 10 | -20 | 30 | 0 | 0 |
| 211449 | 27 | 0 | 27 | 0 | 0 |

`reserved_quantity < 0` が混在しているため、自動lot作成は危険。`reserved_quantity=0` で残っているものもあるが、Oracle照合では親在庫とOracle現在庫が一致しないものが含まれるため、単純な追加は保留。

## 4. B2 は差分 lot 作成候補
B2 は現時点で 0 件。

## 5. C は上位商品から履歴確認
C上位は空容器系が中心。

| item_code | current | reserved | available | pickable | missing | Oracle current | LOCAL - Oracle |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 722001 | 19,452 | 5 | 19,447 | 1,590 | 17,857 | 17,872 | 1,580 |
| 723002 | 11,416 | 14 | 11,402 | 937 | 10,465 | 10,465 | 951 |
| 722024 | 1,355 | 0 | 1,355 | 157 | 1,198 | 1,201 | 154 |
| 722010 | 1,115 | 5 | 1,110 | 165 | 945 | 945 | 170 |
| 722021 | 1,144 | 6 | 1,138 | 230 | 908 | 908 | 236 |

C上位10件は全て以下の関係:

`LOCAL real_stocks.current_quantity = Oracle current + ACTIVE lot current`

例:

| item_code | LOCAL current | Oracle current | ACTIVE lot current | 関係 |
|---:|---:|---:|---:|---|
| 722001 | 19,452 | 17,872 | 1,580 | local=oracle+lot |
| 723002 | 11,416 | 10,465 | 951 | local=oracle+lot |
| 722021 | 1,144 | 908 | 236 | local=oracle+lot |
| 722010 | 1,115 | 945 | 170 | local=oracle+lot |
| 722023 | 847 | 684 | 163 | local=oracle+lot |

このため、Cを単純に差分lot作成すると「Oracle current分のlotを追加する」動きになる。LOCAL内部の親/lot整合は取れるが、Oracleとの差分は残る。

### C上位lot作成履歴
上位空容器系のACTIVE lotは `purchase_id` / `trade_item_id` が全件あり、2026-05-06から2026-05-09に作成されている。

| item_code | active lot数 | active current | active reserved | 初回lot作成 | 最終lot作成 |
|---:|---:|---:|---:|---|---|
| 722001 | 196 | 1,580 | 5 | 2026-05-07 08:41:01 | 2026-05-09 20:23:09 |
| 723002 | 98 | 951 | 14 | 2026-05-07 08:41:01 | 2026-05-09 20:32:37 |
| 722024 | 80 | 154 | 0 | 2026-05-07 08:35:15 | 2026-05-09 20:23:09 |
| 722010 | 99 | 170 | 5 | 2026-05-06 09:07:54 | 2026-05-09 20:30:32 |
| 722021 | 130 | 236 | 6 | 2026-05-06 09:07:54 | 2026-05-09 20:30:32 |

`real_stock_lot_histories` にアーカイブ履歴はなし。`real_stock_lot_earnings` は一部予約のみ存在。

## 判断
1. Z-0-0 正式引当化は LOCAL で完了。
2. B1/B2 は現時点で消えている。
3. Aは `reserved_quantity < 0` とOracle差分が混ざるため、追加パッチは保留。
4. Cは上位を見る限り、Oracle在庫 + 既存lot = LOCAL親在庫になっており、単純な差分lot追加は危険。
5. 次のパッチは、C全体ではなく「Oracle current と LOCAL current の関係」「lot作成元」「出荷/入荷処理」を商品群別に確認してから決めるべき。

