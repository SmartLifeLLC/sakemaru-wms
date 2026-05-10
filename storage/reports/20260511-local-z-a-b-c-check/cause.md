# real_stocks / real_stock_lots 差分原因調査

## 調査日
- 2026-05-11

## 対象
- LOCAL DB: `hana_local`
- Oracle: SSH tunnel `127.0.0.1:11521`
- 主対象: 倉庫91、C分類上位空容器

## 結論
倉庫91の空容器系で `real_stocks` に在庫があるのに `real_stock_lots` が不足している主因は、初期在庫移行時に親在庫だけ作成され、初期在庫分のlotが作成されなかったこと。

発生条件は次の組み合わせ。

1. Oracle `T3在庫` には倉庫91の現在庫が存在する。
2. その商品はOracle `M2店舗取扱` に行はあるが、`棚番` がNULL。
3. 移行スクリプト `stock_transfer_main.py` は、棚番が取れない場合に倉庫/フロアのデフォルトロケーションへ落とす設計だが、デフォルト判定が `locations.name = 'ZZ1100'` 固定。
4. LOCALの倉庫91では該当デフォルトロケーション名が `ZZ1` で、`ZZ1100` が存在しない。
5. そのため `lot_assignments` が作られず、`real_stocks` は作成されたが `real_stock_lots` は作成されなかった。

その後、通常の仕入/容器回収処理で作られたlotだけが `real_stock_lots` に存在し、同時に `real_stocks.current_quantity` も加算されたため、現在は次の関係になっている。

`LOCAL real_stocks.current_quantity = Oracle current + ACTIVE lot current`

## DB確認結果

### Oracle T3在庫
最新 `管理年月=202605` で、倉庫91の空容器在庫は存在する。

| item_code | Oracle current |
|---:|---:|
| 722001 | 17,872 |
| 723002 | 10,465 |
| 722024 | 1,201 |
| 722010 | 945 |
| 722021 | 908 |
| 722023 | 684 |
| 722022 | 494 |
| 723010 | 473 |
| 722030 | 242 |
| 722020 | 190 |

### Oracle M2店舗取扱
同じ商品は `M2店舗取扱` に行はあるが、全て `棚番=NULL`。

例:
- `722001`: `722001200`, `722001010`, `722001100`, `722001300`, `722001000` が存在するが棚番NULL
- `723002`: `723002100`, `723002000`, `723002200`, `723002300`, `723002400`, `723002500` が存在するが棚番NULL

### LOCAL倉庫91ロケーション
倉庫91には `locations.name = 'ZZ1100'` が存在しない。

存在する近いロケーション:

| location_id | floor_id | code | name | flags |
|---:|---:|---|---|---:|
| 10 | 10 | ZZ-1-NULL | ZZ1 | 3 |
| 11 | 11 | ZZ-1-NULL | ZZ1 | 3 |
| 327 | 10 | Z-0-0 | デフォルト | 7 |

## コード上の根拠

`/Users/jungsinyu/PycharmProjects/HanaDBTransfer/stock_transfer_main.py`

- `T3在庫` から `stock_rows` を作り、`real_stocks` は在庫数量があれば作成する。
- 棚番は `M2店舗取扱 + M2商品` から `棚番 IS NOT NULL` のものだけ `shelf_map` に入れる。
- `real_stock_lots` は `lot_assignments` がある場合のみ作成する。
- デフォルトロケーションは `loc_name == 'ZZ1100'` の場合だけ `default_location_map` に入れる。

そのため、倉庫91のように棚番NULLかつ `ZZ1100` が存在しない場合、親在庫だけ作られlotが抜ける。

## 既存パッチとの関係

`/Users/jungsinyu/PycharmProjects/HanaDBTransfer/patches/patch_wms_pickable_lot_for_item.py` と WMS側 `stock:patch-wms-pickable-lots` は、親在庫を更新せず `real_stock_lots` だけを作る緊急パッチ。

LOCALには `purchase_id=NULL / trade_item_id=NULL` の後付けlotが一部存在するが、C上位の不足量全体は解消していない。Cを単純に差分lot作成すると、実質的にOracle初期在庫分をlot化する動きになる。

## 修正方針案

### 最小データパッチ
倉庫91のC分類は、Oracle current と LOCAL差分の関係が明確な商品から、`missing = real_stocks.available_quantity - pickable_lot_available` 分ではなく、まず `Oracle current` 相当を初期在庫lotとして作るかを商品単位で確定する。

ただし予約済みがある商品は、親の `reserved_quantity` とlot側予約の整合を崩さないよう、`current_quantity` は不足分、`reserved_quantity` は原則0で追加し、既存予約は既存lotに残す。

### 移行/同期ロジック修正
`stock_transfer_main.py` のデフォルトロケーション判定を `ZZ1100` 固定から、次の優先順へ変更する。

1. `Z-0-0` が正式引当ロケになったため、倉庫91では `Z-0-0` をデフォルト候補にする。
2. 既存の `item_incoming_default_locations` があればそれを優先する。
3. `ZZ1100` だけでなく `ZZ1` / `code1='ZZ'` もデフォルト候補として扱う。

### 注意点
`real_stocks` と `real_stock_lots` を毎回同期するより、初期移行・通常入荷・移動の各入口でlot作成漏れを防ぐ方が安全。同期型パッチは簡単だが、親在庫がOracle月次在庫と後続処理の合算になっているため、無条件同期は二重計上を招く可能性がある。
