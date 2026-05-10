# LOCAL DB stock lot patch result

## 実施日時
- 2026-05-10

## 対象DB
- `hana_local`

## 実施内容
1. 倉庫91の `Z-0-0` ロケーションをWMS引当可能ロケーションへ変更した。
   - `locations.id = 327`
   - `floor_id: NULL -> 10`
   - `available_quantity_flags: 8 -> 7`

2. `real_stocks.available_quantity > 0` かつ ACTIVE `real_stock_lots` が存在しないA分類のうち、`reserved_quantity = 0` の安全対象だけ `real_stock_lots` を作成した。
   - 作成件数: 383
   - 作成数量合計: 26,780
   - 作成lot id範囲: 54122 - 54504

## 作成先ロケーション内訳
| location_id | 件数 | 数量 |
|---:|---:|---:|
| 10 | 277 | 21,937 |
| 327 | 98 | 4,706 |
| 2495 | 1 | 72 |
| 1810 | 1 | 26 |
| 2671 | 2 | 26 |
| 839 | 1 | 8 |
| 472 | 2 | 4 |
| 2669 | 1 | 1 |

## 事前対象
- `storage/reports/20260510-local-stock-lot-patch/a_safe_patch_targets_before.tsv`

## パッチ後確認
### A分類
| 状態 | 件数 | 数量 |
|---|---:|---:|
| パッチ前: 親在庫あり / ACTIVE lotなし | 402 | 26,990 |
| パッチ実施対象: reserved_quantity = 0 | 383 | 26,780 |
| パッチ後残: reserved_quantity < 0 | 19 | 210 |

`reserved_quantity < 0` の19件は、予約数量自体が不正なため自動lot作成対象外とした。

### 現在の引当可能差分
| 分類 | 件数 | 親在庫数量 | 引当可能lot数量 | 不足数量 |
|---|---:|---:|---:|---:|
| A_no_pickable_lot | 30 | 314 | 0 | 314 |
| C_partial_missing | 62 | 54,979 | 20,395 | 34,584 |
| D_ok | 3,254 | 512,223 | 618,972 | 0 |

A_no_pickable_lot 30件には、今回除外した `reserved_quantity < 0` の19件に加え、親在庫の `available_quantity` とlot側の有効数量評価が一致しない少量データが含まれる。Cは空容器系の大口差分が多く、今回の「lotなし補完」とは別原因として扱う。

## Oracle比較
Oracle元DBへの直接接続は実施を試みたが、SSHトンネル起動に失敗したため未完了。

失敗理由:
- `/Users/jungsinyu/PycharmProjects/HanaDBTransfer/scripts/oracle_tunnel.sh start`
- 秘密鍵 `/var/www/hana/certs/id_stg` がローカル環境に存在しない
- `Permission denied (publickey)`

前回の直接接続でも `ORA-12170` でタイムアウトしているため、Oracleとの差分確認にはトンネル接続環境の復旧が必要。

## 判断
今回の最小パッチで、移行時に `real_stocks` だけ作成され `real_stock_lots` が無かった安全対象は解消した。

ただし、以下は未解決として残す。
- `reserved_quantity < 0` のA分類
- 既にlotはあるが親在庫より引当可能lotが少ないC分類
- Oracle元DBとの直接差分確認

