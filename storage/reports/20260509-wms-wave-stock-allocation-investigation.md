# real_stocks / real_stock_lots 整合性調査報告

作成日: 2026-05-09
検証日: 2026-05-09（DB再々検証 + 全コード経路検証）
対象DB: LOCAL `hana_local`
対象プロジェクト:

- `/Users/jungsinyu/Projects/sakemaru-wms`
- `/Users/jungsinyu/Projects/sakemaru-trade`
- `/Users/jungsinyu/Projects/sakemaru-ai-core`

## 結論

現在の在庫不整合は、波動取消だけの問題ではない。`real_stocks` を親集計、`real_stock_lots` をロット別実在庫として使う設計になっているが、複数の業務経路が `real_stocks.current_quantity` / `reserved_quantity` だけを直接更新しており、`real_stock_lots` と同期していない。

WMSの波動引当は `real_stock_lots` を基準に候補在庫を探すため、`real_stocks.available_quantity` が残っていても、有効ロットがなければ欠品になる。商品CD `156643` のケースはこの状態に該当する。

本番運用中の暫定判断としては、業務在庫の正を `real_stocks` に置く。そのため、最小パッチで `real_stocks` を `real_stock_lots` 合計へ再集計してはいけない。短期対応は「波動引当前に、親在庫の引当可能数に対して不足しているロットだけを補完する」ことに限定する。

## 精査結果サマリー

| 論点 | 判定 | 本番影響 | 最小パッチ方針 |
| --- | --- | --- | --- |
| WMS引当失敗 | **確定リスク** | 親在庫があっても波動欠品になる。ピッキングリスト、出荷作業、欠品処理に直撃 | WMS波動生成前に不足ロットを補完。ただし親在庫は更新しない |
| 在庫が勝手に狂う経路 | **確定リスク** | AI Core / Trade の親在庫直接更新により、親とロットが継続的に乖離 | 直ちに全改修せず、監査ログと対象経路の段階修正 |
| 出荷完了キュー | **高リスク** | WMS出荷後に基幹在庫が減らない、またはセット品が更新されない可能性 | AI Core側キューをWMSキュー形式に合わせる |
| デフォルトロケーション | **確定リスク** | Z-0-0上のロットは引当対象外。補完ロットをここに作ると効果なし | `item_incoming_default_locations` または flags対応ロケーションを使う |
| 伝票明細フィルタ | **在庫修正ではない** | 明細と金額の不一致を起こす可能性。stock同期の解決にはならない | `shortage_allocated_qty` を含めた判定が必須。別件として扱う |

## LOCAL DB確認結果

### 商品CD 156643

- `items.code = 156643`（シャプティエ コートデュローヌ ブラン ペルルーシュ 750ml）
- `items.uses_expiration_date = false`
- `real_stocks.id = 29561`
- `warehouse_id = 91`
- `real_stocks.current_quantity = 24`
- `real_stocks.reserved_quantity = 0`
- `real_stocks.available_quantity = 24`
- 対応する `real_stock_lots` の有効ロット数量合計 = **0**（ロットが1件も存在しない）
- 他倉庫(wh=1,2,3,4,7,8,10,11,21,22)にはロットが存在する（計10件）
- 波動 `W091-C910203-20260508-198` では `wms_reservations` が `SHORTAGE`、`qty_each = 0`、`shortage_qty = 4`

このため、画面上または親在庫上は24個あるように見えるが、WMSの引当対象ロットが存在せず、波動生成では欠品になった。

### LOCAL DB全体の不整合件数

読み取りSELECTで確認した範囲（2026-05-09 再々検証）。LOCAL DBは作業中に変動するため、件数は時点値である。

| 指標 | 初回 | 再々検証 | 差分 |
| --- | ---: | ---: | ---: |
| `real_stocks.current_quantity > 0` だが有効ロットなし | 420 | **422** | +2 |
| 親現在庫と有効ロット現在庫の合計不一致 | 6,212 | **6,212** | 0 |
| 親引当数と有効ロット引当数の合計不一致 | 34 | **34** | 0 |
| 親で引当可能だがWMSピック可能ロットなし | 426 | **567** | +141 |
| 有効ロットがロケーション未設定 | 1 | **1** | 0 |
| 親在庫に負数あり | 803 | **803** | 0 |
| ロット在庫に負数あり | 782 | **782** | 0 |

差分はレポート作成後のデータ変動と、今回から「有効ロット有無」だけでなく「WMSピック可能ロケーション上のロット有無」を分けて数えたために発生している。波動欠品に直結するのは後者である。

### 不一致パターンの詳細分類

| パターン | 件数 | 数量 | 備考 |
| --- | ---: | ---: | --- |
| A. 親current>0, 有効ロットなし | 422 | 時点変動あり | WMS引当不能。最も深刻 |
| B. 親current<=0, ロットcurrent>0 | 292 | 3,097個 | 逆方向の不整合 |
| C. 親>ロット（両方正, ロットあり） | 263 | - | 部分的欠落 |
| D. ロット>親 | 5,426 | - | 最多。ロット側が超過 |
| E. current一致, reserved不一致のみ | 33 | - | |
| F. 倉庫91のみのcurrent不一致 | 719 | - | WMS引当に直接影響 |
| G. 倉庫91: 親available>0 & WMSピック可能ロットなし | **457** | - | **波動欠品の直接原因** |

上位の不一致例（再検証済み・全件一致）:

| real_stock_id | warehouse_id | item_id | 親current | lot current | gap |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 35754 | 91 | 722001 | 18,298 | 426 | +17,872 |
| 35779 | 91 | 920000 | 17,536 | 0 | +17,536 |
| 50457 | 91 | 184067 | 8,654 | 16,032 | -7,378 |
| 37402 | 91 | 143024 | 36,983 | 41,303 | -4,320 |
| 29561 | 91 | 156643 | 24 | 0 | +24 |

## 現在の仕様上の前提

### 親在庫

`real_stocks.available_quantity` は **STORED GENERATED column**（`current_quantity - reserved_quantity` の自動計算）。親在庫は倉庫・商品・在庫区分単位の集計値として扱われている。

```sql
`available_quantity` int GENERATED ALWAYS AS ((`current_quantity` - `reserved_quantity`)) STORED
```

### ロット在庫

`real_stock_lots` はロケーション、期限、仕入由来を持つ実在庫単位。WMSのピッキングでは、ロットとロケーションが引当可能性の実体になる。`real_stock_lots` には `available_quantity` カラムは存在せず、Eloquentアクセサで `current_quantity - reserved_quantity` を算出する。

### 守るべき不変条件

通常商品については、少なくとも以下が成り立つ必要がある。

```text
real_stocks.current_quantity = SUM(real_stock_lots.current_quantity)
real_stocks.reserved_quantity = SUM(real_stock_lots.reserved_quantity)
real_stock_lots.current_quantity >= real_stock_lots.reserved_quantity >= 0
real_stocks.current_quantity >= real_stocks.reserved_quantity >= 0
正の有効ロットはWMS引当可能なlocation_idを持つ
  → location.available_quantity_flags がCASE(1)/PIECE(2)/CARTON(4)のいずれかを含むこと
  → flags=8(UNKNOWN)のロケーションは引当対象にならない
```

## WMS環境の前提（検証で判明）

### WMS波動対象

`wms_wave_settings` の全レコードが倉庫91を参照。**WMS波動引当の対象は倉庫91のみ**。

### 倉庫91のロケーション構成

| 条件 | 件数 | 備考 |
| --- | ---: | --- |
| 全ロケーション | 2,719 | |
| flags=3 (CASE+PIECE) | 2,718 | **引当可能** |
| flags=8 (UNKNOWN) | 1 | Z-0-0デフォルト。**引当不可** |
| ピッキングエリア定義 | 0 | 未使用 |
| item_incoming_default_locations | 3,493 | 全てflags=3。**引当可能** |

### デフォルトロケーション Z-0-0 の問題

| 属性 | 値 | 問題 |
| --- | --- | --- |
| id | 327 | |
| available_quantity_flags | **8 (UNKNOWN)** | CASE(1)/PIECE(2)/CARTON(4)のいずれにもマッチしない |
| floor_id | **null** | |
| wms_picking_area_id | **null** | |

StockAllocationService のビットマスクフィルタ `(l.available_quantity_flags & {quantityFlag}) != 0` により:
- `(8 & 1) = 0` → CASE不合格
- `(8 & 2) = 0` → PIECE不合格
- `(8 & 4) = 0` → CARTON不合格

**デフォルトロケーション上に配置されたロットは引当対象に一切ならない。**

現在、デフォルトロケーション(id=327)に179件の有効ロット（合計18,287個）が存在するが、wms_reservationsは0件。**実質的に死蔵在庫**。

### 倉庫91の有効ロット分布

| ロケーションflags | ロット数 | 数量合計 | 引当可否 |
| --- | ---: | ---: | --- |
| flags=3 (CASE+PIECE) | 3,418 | 590,033 | **可能** |
| flags=8 (UNKNOWN) | 179 | 18,287 | **不可能** |

## コード経路検証結果

全ファイルのコードを実際に読み、SQL/Eloquent操作を行番号レベルで確認した。

### 検証結果サマリー

#### sakemaru-wms（4ファイル）

| ファイル | 判定 | 要点 |
| --- | --- | --- |
| `StockAllocationService.php` | **CONFIRMED** | `real_stock_lots` + `locations` JOIN で候補取得。`wms_reservations` のみ INSERT。`real_stocks` への UPDATE なし（L180 コメントで明示） |
| `EarningDeliveryQueueService.php` | **CONFIRMED** | `earning_delivery_queue` の作成のみ。在庫操作なし。Sakemaru側 `ProcessEarningDeliveryQueue` が `confirmDelivery()` を実行する前提（L11-18 docblock） |
| `ProcessEarningDeliveryQueue.php` | **CONFIRMED** | ジョブは存在するが **WMS側では未dispatch**。scheduler, routes, config いずれにも登録なし。PickingTaskController L1095 コメントで「Sakemaru側」と明記 |
| `WavesTable.php` 波動取消 | **CONFIRMED** | 取消ガード: CLOSED/SHIPPED/キュー作成済みで不可。取消時は `wms_picking_item_results`, `wms_shortages`, `wms_reservations`, `wms_picking_tasks` を DELETE。`real_stocks` / `real_stock_lots` 変更なし |

WMS内で `real_stocks.current_quantity` / `reserved_quantity` を直接更新するプロダクションコードは `LotAllocationService` のみ。テストデータ生成コマンド以外では安全。

#### sakemaru-ai-core（14ファイル）

| # | ファイル | 判定 | 要点 |
| --- | --- | --- | --- |
| 1 | `PostRealStocks.php` | **CONFIRMED** | L115-116: `current_quantity` / `available_quantity` を `$base['quantity']` で上書き。L124-125: updateCols に両カラム。temp table JOIN UPDATE で一括更新。`real_stock_lots` 操作なし。**GENERATED column書き込み問題あり（後述）** |
| 2 | `UpdateStockTransfer.php` | **CONFIRMED** | L218-251: `updateWarehouseQuantity()` で `$current_quantity += $quantity * ($is_plus ? 1 : -1)` → save。L238-239コメント「倉庫移動は直接現在庫更新」。ロット操作なし |
| 3 | `ConfirmStockTransfer.php` | **CONFIRMED** | L68-71: `$current_quantity += $trade_item->quantity` → fill → save。L63-65: 親stock新規作成。ロット作成なし |
| 4 | `ProcessStockTransfer.php` | **CONFIRMED** | CREATE: L111 で `UpdateStockTransfer` に委譲。UPDATE: L242-247 `DB::table('real_stocks')->increment('current_quantity', $diffUnits)` 生SQL。DELIVER: L416-421 同様に `increment`。3パターン全てロット操作なし |
| 5 | `DeleteCashRealStock.php` | **CONFIRMED** | L86-87: 配送済み非セット品 `$real_stock->increment('current_quantity', ...)` → `LotAllocationService::undeliver()` **未呼出**。L77-81: 自社セット `restoreOwnSetComponentStock()` → L145-149: 親の `current_quantity` / `reserved_quantity` 直接操作。ロットなし |
| 6 | `UpdateTradeItems.php` | **CONFIRMED** | L361-365: 自社セット → `reduceOwnSetComponentStock()`。L487: `$component_stock->decrement('current_quantity', ...)`, L490: `increment('reserved_quantity', ...)`。非セットは L370-393 で `LotAllocationService` 正しく使用 |
| 7 | `EarningMarkAsDeliveredStatus.php` | **CONFIRMED** | L63: 非セット → `$lotAllocationService->deliver()` 正常。L67-75: 自社セット → `deliverOwnSetComponentStock()`。L118-119: `decrement('reserved_quantity', ...)` + `decrement('current_quantity', ...)`。ロットなし |
| 8 | `ProcessPrintRequest.php` | **CONFIRMED** | L147: 非セット → `deliver()` 正常。L153-160: 自社セット → `deliverOwnSetComponentStock()`。L229-230: L118-119 と同一パターン。ロットなし |
| 9 | `DisassembleItemSet.php` | **CONFIRMED** | L108-109: セット品 `$set_real_stock->current_quantity -= $quantity`。L127-128: 構成品 `$real_stock->current_quantity += $add_quantity`。ロット操作なし |
| 10 | `CalculateSetItemStockJob.php` | **CONFIRMED** | L86-104: 構成品 `RealStock` に `current_quantity` / `available_quantity` 直接セット。L246-249: セット品親在庫を計算・更新。ロットなし。**GENERATED column書き込みあり** |
| 11 | `RealStock::syncWithWMS()` | **CONFIRMED** | L94-97: 旧WMS `r_stocks` / `t_stock_reserves` から読み取り → `$real_stock->current_quantity = $total_stocks`, `$real_stock->reserved_quantity = $reserved_qty` → save。ロットなし |
| 12 | `SyncZeroStockCommand.php` | **CONFIRMED** | L41: `RealStock::whereIn(...)->update(['available_quantity' => 0, 'current_quantity' => 0])`。ロットなし。**GENERATED column書き込みあり** |
| 13 | `LotAllocationService.php` | **CONFIRMED** | 全メソッドで親+ロット同期: `allocate()` L88, `deallocate()` L119, `deliver()` L157-158, `createLot()` L209-210, `updateLot()` L302-304, `deleteLot()` L348-349, `undeliver()` L221-246。**これが正しい経路** |
| 14 | `ProcessEarningDeliveryQueue.php` (AI Core側) | **PARTIALLY CONFIRMED** | L66-71: `$lotAllocationService->deliver($earningId)` は正しい。**ただし自社セット構成品の処理が欠落**。`EarningMarkAsDeliveredStatus` / `ProcessPrintRequest` にはある自社セット処理がこのジョブにはない |

#### sakemaru-trade（2ファイル）

| # | ファイル | 判定 | 要点 |
| --- | --- | --- | --- |
| 1 | `RetailTradeCreationService.php` | **CONFIRMED** | L267-274: `DB::table('real_stocks')->update(['current_quantity' => DB::raw("current_quantity - {$quantity}")])` 生SQL。プロジェクト内に `real_stock_lots` / `real_stock_lot_earnings` / `reserved_quantity` への参照は**一切なし** |
| 2 | `RealStock::syncWithWMS()` | **CONFIRMED** | L86-88: `$real_stock->current_quantity = $total_stocks`, `$real_stock->available_quantity = ...` → save。ロットなし。**ただしプロジェクト内で呼び出し元なし（デッドコードの可能性）** |

## 初回レポートに記載のない新規発見

### 発見1: `available_quantity` STORED GENERATED column への直接書き込み

`real_stocks.available_quantity` は `GENERATED ALWAYS AS (current_quantity - reserved_quantity) STORED` である。以下のコードが直接書き込みを行っている:

- `PostRealStocks.php`: L115-116 + updateCols
- `CalculateSetItemStockJob.php`: L246-249
- `SyncZeroStockCommand.php`: L41
- `RealStock::syncWithWMS()` (AI Core / Trade 両方): save() 時に `available_quantity` を含む

MySQL 8.0 では GENERATED column への INSERT/UPDATE は error 3105 で拒否される。**本番でこれらのコードが正常動作しているか要確認**。動作しているなら、本番DB側で `available_quantity` が通常カラムのまま残っている可能性がある。

### 発見2: デフォルトロケーション Z-0-0 は引当対象にならない

flags=8(UNKNOWN) のため、StockAllocationService のビットマスクフィルタで全 quantity type が不合格。**調整ロットをデフォルトロケーションに置いても引当不可能**。

方針Aの「調整ロットはデフォルトロケーションへ作成する」は **修正が必要**。`item_incoming_default_locations` を第一候補とし、見つからない場合は倉庫内の flags 対応ロケーション（flags=3 が2,718件ある）にフォールバックすべき。

### 発見3: デフォルトロケーション上の死蔵ロット

倉庫91のデフォルトロケーション(id=327, flags=8)に179件の有効ロット（合計18,287個）が存在。flags=8 のため引当対象にならず、reservation は0件。**実質的に死蔵在庫**。

これらは `MigrateRealStocksToLots` コマンド（初期ロット移行）で location_id 未設定のまま作成され、後からデフォルトロケーションに配置されたものと推定される。

### 発見4: AI Core ProcessEarningDeliveryQueue の自社セット未対応

AI Core側のキュー処理ジョブは `LotAllocationService->deliver()` のみ呼ぶ。自社セット構成品の `reserved_quantity` 減算 + `current_quantity` 減算は行わない。

`EarningMarkAsDeliveredStatus` と `ProcessPrintRequest` には自社セット処理（`deliverOwnSetComponentStock()`）が存在するが、キュー処理経路には欠落。自社セット品がキュー経由で処理された場合、構成品在庫が不整合になる。

### 発見5: DeleteCashRealStock の undeliver 未呼出

配送済み非セット品の売上取消時（L86-87）、親 `current_quantity` を `increment()` するが、`LotAllocationService::undeliver()` を呼んでいない。`undeliver()` メソッドは存在する（L221-246）がこの経路で未使用。ロット側の `current_quantity` が復元されないため、不整合を作る。

## プロジェクト別分析

## sakemaru-wms

### 波動引当

`app/Services/StockAllocationService.php`

- `real_stock_lots` と `locations` を基準に候補を取得する（L297-351）。
- `real_stocks` の数量は更新しない（L180 コメントで明示）。
- 作成するのは `wms_reservations` のみ（L229-257）。
- ロケーションのビットマスクフィルタ `(l.available_quantity_flags & {quantityFlag}) != 0` により、flags=8(UNKNOWN) のロケーションは除外される（L307）。

つまり、WMS波動生成時の「引当」はWMS内の作業予約であり、基幹側の `real_stocks` / `real_stock_lots` の予約数を増やしていない。

この設計自体は、基幹側ですでに `real_stock_lot_earnings` による引当が済んでいる前提なら成立する。しかし、WMS側だけで作った波動や、基幹側にロット引当がない売上では、出荷完了時の在庫減算と結びつかない。

### 出荷完了キュー

`app/Services/EarningDeliveryQueueService.php`

- ピッキング完了時に `earning_delivery_queue` を作る（L27-106）。
- クラスdocblock（L11-18）にフロー全体を明記:
  1. WMS: ピッキング完了 → このサービス呼出
  2. WMS: `earning_delivery_queue` レコード作成（status=PENDING）
  3. Sakemaru: `ProcessEarningDeliveryQueue` Job がポーリング
  4. Sakemaru: `LotAllocationService.confirmDelivery()` でロット在庫更新

`app/Jobs/ProcessEarningDeliveryQueue.php`

- WMS側には `items` を読んで `confirmDelivery(earning_id, trade_item_id, quantity)` を呼ぶジョブが存在する（L46-79）。
- **ただしWMS側では未dispatch**: scheduler, routes, config いずれにも登録なし。
- `PickingTaskController` L1095 のコメントで「Sakemaru側の ProcessEarningDeliveryQueue Job が実行する」と明記。

### 波動取消

`app/Filament/Resources/Waves/Tables/WavesTable.php`

- 出荷済み、または `earning_delivery_queue` / `stock_transfer_queue` / `quantity_update_queue` 作成済みの波動は取消不可（L636-795）。
- 取消時はWMS作業テーブルを戻すが、`real_stocks` / `real_stock_lots` は変更しない（L542-634）。

WMS波動生成が基幹在庫を更新しないため、波動取消で在庫を戻さないこと自体は整合している。ただし、WMS出荷完了後のキュー処理が不完全だと、在庫は減らない。

## sakemaru-ai-core

### 正常系のロット同期サービス

`app/Services/LotAllocationService.php`

全メソッドで `real_stocks` と `real_stock_lots` の同期を維持している:

- `allocate()`: ロット `reserved_quantity` + 親 `reserved_quantity` を増やす（L88）
- `deallocate()`: ロット + 親の `reserved_quantity` を戻す（L119）
- `deliver()`: ロット + 親の `current_quantity` / `reserved_quantity` を減らす（L157-158）
- `createLot()`: ロット作成 + 親 `current_quantity` 増加（L209-210）
- `updateLot()`: ロット更新 + 親 `current_quantity` 差分更新（L302-304）
- `deleteLot()`: 親 `current_quantity` 減少 + ロット削除（L348-349）
- `undeliver()`: ロット + 親の `current_quantity` 復元（L221-246）。ただし `reserved_quantity` は復元せず、earningをCANCELLEDにする

このサービスを通る経路は、基本的に親とロットの同期を意識している。

### WMS出荷キュー処理の前提違い

`app/Jobs/ProcessEarningDeliveryQueue.php`

- AI Core側のジョブは `earning_delivery_queue.items` を見ず、`earning_ids` ごとに `LotAllocationService->deliver($earningId)` を呼ぶ（L66-71）。
- `deliver()` は `real_stock_lot_earnings.status = RESERVED` の既存引当を探して出荷確定する。

一方、WMS側の波動引当は `wms_reservations` だけを作り、`real_stock_lot_earnings` を作らない。このため、WMSから作られたキューをAI Core側ジョブが処理すると、既存ロット引当がない売上では在庫が減らないままキューだけ完了する可能性がある。

**追加発見: 自社セット構成品の処理が欠落**。`EarningMarkAsDeliveredStatus` と `ProcessPrintRequest` にはある `deliverOwnSetComponentStock()` がこのジョブにはない。

これは今回の整合性問題の中でも優先度が高い。

### 親在庫だけを直接更新する経路

以下は `real_stocks` を直接更新し、`real_stock_lots` を同時更新していない。全件コード検証済み。

- `app/Actions/API/PostRealStocks.php`
  - 外部APIで `real_stocks.current_quantity` / `available_quantity` を一括上書き（L115-116, temp table JOIN UPDATE L64-76）。
  - `real_stock_lots` は作成・更新されない。
  - **注意: `available_quantity` はGENERATED column。本番での挙動要確認。**
- `app/Actions/Trades/UpdateStockTransfer.php`
  - 倉庫移動の出庫側で親 `current_quantity` を直接増減（L218-251 `updateWarehouseQuantity()`）。
  - L238-239コメント「倉庫移動は直接現在庫更新」。
  - ロット移動・移動先ロット作成がない。
- `app/Actions/Trades/ConfirmStockTransfer.php`
  - 倉庫移動の入庫側で親 `current_quantity` を直接増加（L68-71）。
  - ロット作成がない。
- `app/Jobs/Polling/ProcessStockTransfer.php`
  - ポーリング経由の倉庫移動で、CREATE（L111: `UpdateStockTransfer` 委譲）、UPDATE（L242-247: `increment` 生SQL）、DELIVER（L416-421: `increment` 生SQL）の3パターン全てで親 `current_quantity` のみ増減。
- `app/Actions/Trades/DeleteCashRealStock.php`
  - 配送済み非セット品の売上取消で親 `current_quantity` のみ戻す（L86-87: `increment`）。**`LotAllocationService::undeliver()` 未呼出**。
  - 自社セット構成品は `restoreOwnSetComponentStock()` で親 `current_quantity` / `reserved_quantity` を直接操作（L145-149）。
- `app/Actions/Trades/UpdateTradeItems.php`
  - 自社セット構成品は `reduceOwnSetComponentStock()` で親を直接操作（L487: `decrement('current_quantity')`, L490: `increment('reserved_quantity')`)。
  - 非セット品は `LotAllocationService` を正しく使用（L370-393）。
- `app/Actions/Trades/EarningMarkAsDeliveredStatus.php`
  - 非セット品は `$lotAllocationService->deliver()` で正常処理（L63）。
  - 自社セット構成品は `deliverOwnSetComponentStock()` で親在庫のみ減算（L118-119）。
- `app/Jobs/ProcessPrintRequest.php`
  - 自社セット構成品は `deliverOwnSetComponentStock()` で親在庫のみ減算（L229-230）。EarningMarkAsDeliveredStatus と同一パターン。
- `app/Actions/Item/DisassembleItemSet.php`
  - セット品: `$set_real_stock->current_quantity -= $quantity`（L108-109）。
  - 構成品: `$real_stock->current_quantity += $add_quantity`（L127-128）。
  - ロット操作なし。
- `app/Jobs/Web/CalculateSetItemStockJob.php`
  - 構成品: `RealStock` に `current_quantity` / `available_quantity` 直接セット（L86-104）。
  - セット品: 計算結果で `update(['available_quantity' => ..., 'current_quantity' => ...])`（L246-249）。
  - ロットなし。**GENERATED column書き込みあり。**
- `app/Models/RealStock.php::syncWithWMS()`
  - 旧WMSテーブルから `$real_stock->current_quantity = $total_stocks`, `$real_stock->reserved_quantity = $reserved_qty` → save（L94-97）。
  - ロットは同期しない。
- `app/Console/Commands/TempWork/SyncZeroStockCommand.php`
  - 一時コマンドだが `RealStock::whereIn(...)->update(['available_quantity' => 0, 'current_quantity' => 0])`（L41）。
  - ロットなし。**GENERATED column書き込みあり。**

特に `PostRealStocks` は外部在庫同期として大量に親在庫を上書きできるため、親にだけ在庫がありロットがない状態を作る主因になり得る。

## sakemaru-trade

### 親在庫だけを直接更新する経路

`app/Services/Retail/RetailTradeCreationService.php`

- POS/小売取込時に `real_stocks.current_quantity = current_quantity - quantity` を直接実行（L267-274: `DB::raw("current_quantity - {$quantity}")`）。
- `real_stock_lots`、`real_stock_lot_earnings`、`reserved_quantity` は更新しない。
- **Trade プロジェクト内に `real_stock_lots` への参照は一切存在しない。**

`app/Models/BZCore/RealStock.php::syncWithWMS()`

- 旧WMS系テーブルから親在庫だけを同期する（L86-88）。
- ロットは同期しない。
- **ただしプロジェクト内で呼び出し元なし（デッドコードの可能性）。**

Trade側はWMSロット管理を前提とした同期経路がほぼなく、実行されると親とロットの乖離を作る。

## 原因整理

### 1. 在庫の真実が二重化している

親の `real_stocks` とロットの `real_stock_lots` がどちらも数量を持つが、更新責務が一元化されていない。

### 2. WMSはロット基準、画面・一部処理は親基準

WMS波動引当はロットを見ている。ところが在庫一覧、欠品関連画面、外部API、倉庫移動、POS取込は親在庫を見る・更新する経路が残っている。

そのため「在庫ありに見えるが波動では欠品」または「ロットはあるが親集計が違う」状態が発生する。

### 3. WMS出荷完了キューの処理契約が一致していない

WMS側コメントは `confirmDelivery(earning_id, trade_item_id, quantity)` 前提だが、AI Core側ジョブは `deliver(earning_id)` 前提であり、`real_stock_lot_earnings` がない場合に在庫を動かせない。

さらに、AI Core側キュー処理は自社セット構成品の在庫操作が欠落している。

### 4. 倉庫移動・POS・外部在庫同期がロット非対応

倉庫間で在庫が移動してもロットの所在地が移動しない。外部同期で親在庫だけ増えても、WMSでピックできるロットは作られない。

### 5. デフォルトロケーションが引当不可

倉庫91のデフォルトロケーション(Z-0-0)は `available_quantity_flags=8(UNKNOWN)` であり、StockAllocationService のビットマスクフィルタを通過しない。初期ロット移行等でデフォルトロケーションに配置されたロットは引当対象にならず、死蔵在庫になっている（179件, 18,287個）。

### 6. GENERATED column への直接書き込み

`available_quantity` は STORED GENERATED column だが、AI Core の複数箇所がこのカラムに直接書き込みを試みている。MySQL 8.0 ではエラー 3105 で拒否されるため、本番のカラム定義が LOCAL と異なる可能性がある。

## 影響範囲

### 業務影響

| 業務 | 影響 | 内容 |
| --- | --- | --- |
| 波動生成 | **高** | 親在庫があっても、引当可能ロットがなければ欠品として処理される |
| ピッキングリスト | **高** | WMSはロット・ロケーション基準で出力するため、親在庫だけではリストに載らない |
| 出荷完了 | **高** | WMS出荷キューとAI Core処理契約がずれると、出荷済みでも基幹在庫が減らない可能性がある |
| 波動取消 | 中 | 取消自体はWMS作業データだけを戻す設計。外部キュー作成後は取消不可のため、在庫を戻す責務はキュー側に残る |
| 倉庫移動 | **高** | 親在庫だけが移動し、ロットの所在地が追随しないため、移動先でWMS引当不能になる |
| POS/小売取込 | 中〜高 | Trade側は親在庫のみ減算。ロット在庫が残り、親<ロットの乖離を作る |
| 外部在庫同期 | **高** | `PostRealStocks` が親在庫を一括上書きするため、親のみ在庫・ロット不足を大量生成し得る |
| 自社セット | 中〜高 | 構成品在庫が親のみ操作され、ロットと同期しない。キュー経由では構成品処理も欠落 |
| 売上取消 | 中 | 配送済み非セット品の取消で親だけ復元され、ロットが復元されない |
| 伝票出力 | 中 | 明細フィルタは在庫同期を直さない。条件を誤ると明細と金額が不一致になる |

### システム影響

| テーブル / 機能 | 影響 | 備考 |
| --- | --- | --- |
| `real_stocks` | 現時点の業務在庫の正 | 本番最小パッチで更新禁止 |
| `real_stock_lots` | WMS引当の実体 | 親在庫に追随する補完が必要 |
| `wms_reservations` | WMS内作業予約 | 基幹在庫の予約数は増やさない |
| `real_stock_lot_earnings` | AI Core側の売上ロット引当 | WMS波動引当では作成されないため、AI Core `deliver()` の前提とずれる |
| `earning_delivery_queue` | WMS出荷完了後の在庫減算起点 | 処理契約の統一が必要 |
| `locations.available_quantity_flags` | WMS引当可否 | flags=8は引当不可。補完ロット配置先に使ってはいけない |

### 伝票明細フィルタの扱い

前回確認した「WMSで出荷準備済み、かつ `picked_qty` 合計が0以下の `trade_item` を伝票明細から除外する」案は、在庫同期問題の修正ではない。適用する場合も、以下を満たす必要がある。

- `picked_qty` だけで完全欠品と判断しない。
- 欠品対応・横持ち出荷で `shortage_allocated_qty > 0` のケースを出荷対象として扱う。
- 判定は `picked_qty + shortage_allocated_qty <= 0` を基準にする。
- 全明細が除外された場合、明細空・金額ありの伝票を許容するかを業務決定する。
- このフィルタを入れても、`real_stocks` / `real_stock_lots` の不整合は解消しない。

したがって、伝票フィルタは本報告の在庫最小パッチとは分離して扱う。先に在庫引当と出荷完了キューの整合を直すべきである。

## 本番運用中の前提

2026-05-09時点の本番運用では、業務在庫としては `real_stocks` が正である。

したがって、本番最小パッチでは `real_stock_lots` を正として `real_stocks` を上書きしてはいけない。短期対応は、`real_stocks` の引当可能数に対して `real_stock_lots` が不足している場合だけ、WMSが引当できるロットを補完する方針にする。

この方針は恒久対応ではなく、稼働中の出荷業務を止めないための暫定策である。

### 本番最小パッチの安全条件

最小パッチは次の条件を満たさない限り、本番適用しない。

| 条件 | 理由 |
| --- | --- |
| `real_stocks` を更新しない | 現時点の業務在庫の正を壊さないため |
| 補完対象は波動生成対象の `warehouse_id` / `item_id` のみに限定 | 全件補正で在庫を別方向に壊さないため |
| 補完数量は `parent_available - pickable_lot_available` の正の差分のみ | 親在庫を超えるロットを作らないため |
| 補完ロットは引当可能ロケーションに作る | Z-0-0に作ると引当されず効果がないため |
| 同一 `real_stock_id` / ロケーション / 補完理由で冪等にする | 波動再生成・リトライでロットが増殖しないようにするため |
| 補完ログを残す | 後で本補正・監査・取り消し判断ができるようにするため |
| 負数在庫・ロット過多は自動補正しない | 原因伝票を追わずに補正すると在庫をさらに壊すため |

このパッチで解決するのは「親在庫はあるがWMSが引当できるロットがないため欠品になる」問題である。AI Core / Trade 側の親在庫直接更新や、出荷完了キューの減算漏れは別途修正が必要。

## 修正方向性

### 方針A: 本番最小パッチ: `real_stocks` 正で不足ロットを補完する

現時点の推奨。WMSピッキングはロット・ロケーション・期限を必要とするが、業務在庫の正は `real_stocks` であるため、波動生成前に不足ロットだけを補完する。

必要な変更:

- WMS波動生成の引当前に、対象 `warehouse_id` / `item_id` の親在庫とロット在庫を比較する。
- `real_stocks.available_quantity` がロット引当可能数より多い場合だけ、差分の調整ロットを作る。
- `real_stocks` は変更しない。
- **調整ロットのロケーション選定（重要）**:
  1. `item_incoming_default_locations` から対象 warehouse_id + item_id のロケーションを取得（flags=3, 引当可能）
  2. 見つからない場合、同一倉庫内で `available_quantity_flags` が対象 quantity_type に対応するロケーションを使用
  3. **デフォルトロケーション Z-0-0 (flags=8) は使用しない**（引当不可のため）
- 調整ロットには、後で追跡できるように `purchase_id = null`、`trade_item_id = null`、作成理由ログを残す。
- 一括全件補正ではなく、波動生成対象に限定する。

判定式:

```text
parent_available = real_stocks.current_quantity - real_stocks.reserved_quantity
lot_available = SUM(real_stock_lots.current_quantity - real_stock_lots.reserved_quantity)
  WHERE status = 'ACTIVE'
  AND location.available_quantity_flags & quantityFlag != 0  -- 引当可能ロケーションのみ
missing_lot_quantity = parent_available - lot_available

missing_lot_quantity > 0 の場合だけ、調整ロットを作る
```

対象外:

- `parent_available <= 0`
- `missing_lot_quantity <= 0`
- `real_stock_lots` 側のほうが多いケース
- 親在庫またはロット在庫が負数のケース
- 出荷済み・外部キュー作成済み波動の取消や再構築

### 方針B: 恒久対応: `real_stock_lots` を実在庫の正とし、`real_stocks` は集計キャッシュにする

中長期の推奨。WMSピッキングはロット・ロケーション・期限が必須なので、最終的には実在庫の正を `real_stock_lots` に寄せる。

ただし本番運用中にこの方針を即時適用すると、現在 `real_stocks` を正として動いている外部連携・倉庫移動・POS取込と衝突する。そのため、まず本番最小パッチで出荷不能を止血し、その後に更新経路を段階的にロット同期へ移行する。

### 推奨する実装順

1. WMS波動生成前の不足ロット補完を追加する
   - 対象は波動生成対象の倉庫・商品だけ。
   - 親在庫が正なので、親在庫は更新しない。
   - ロケーションは `item_incoming_default_locations` を第一候補。**Z-0-0 は使用しない。**
   - 補完したロット数、対象 `real_stock_id`、商品、倉庫、波動IDをログに残す。
2. デフォルトロケーション上の死蔵ロットを引当可能ロケーションに移動する
   - 倉庫91のデフォルトロケーション(id=327, flags=8)上の179件(18,287個)。
   - `item_incoming_default_locations` があればそちらへ、なければ flags=3 のロケーションへ。
   - 移動ログを残す。
3. 監査コマンドを追加する
   - 親合計とロット合計の不一致を出す。
   - 負数、ロケーションなし有効ロット、親のみ在庫を検出する。
   - flags=8 ロケーション上の有効ロットを検出する。
   - 本番ではまず dry-run / report-only とする。
4. WMS出荷キューの契約を修正する
   - AI Core側 `ProcessEarningDeliveryQueue` は `items` を読み、WMS予約または調整ロットに対応して在庫を減算する。
   - **自社セット構成品の処理を追加する。**
   - 親 `real_stocks` が正である間は、出荷確定時に親在庫とロットを同じ数量だけ減らす。
   - どちらか一方に統一し、WMS側とAI Core側で別々の前提を持たない。
5. WMS在庫表示を「親在庫」と「WMS引当可能ロット」に分けて表示する
   - 本番最小パッチ段階では、親在庫表示を消さない。
   - 欠品原因が「親在庫不足」か「ロット不足」か分かるようにする。
6. `PostRealStocks` をロット調整方式に変更する
   - 親在庫の上書きではなく、現在のロット合計との差分を調整ロットで表現する。
   - 差分理由、外部連携元、実行日時を履歴に残す。
   - GENERATED column への直接書き込みを修正する。
7. 倉庫移動をロット移動に変更する
   - 出庫元ロットをFEFO/FIFOで減らす。
   - 入庫先に同一属性のロットを作る、または移動中ロット状態を経由する。
8. POS/小売取込をロット消費に変更する
   - `RetailTradeCreationService::deductStock()` は親直接減算をやめる。
   - 店舗/倉庫のロットからFEFO/FIFOで消費する。
9. 自社セット・セット分解のロット対応を決める
   - 構成品ロットを消費・復元する。
   - セット品自体の親在庫計算と構成品実在庫の関係を明確に分離する。
10. `DeleteCashRealStock` の undeliver 呼出を追加する
    - 配送済み非セット品の売上取消時に `LotAllocationService::undeliver()` を呼ぶ。

## データ補正方針

無条件で `real_stocks` から `real_stock_lots` を作り直すのは危険。まず不一致を分類する。

分類（検証済み件数付き）:

- 親あり・ロットなし: **422件**（数量は時点変動あり）
- 親なし・ロットあり: **292件** (3,097個)
- 親とロットの差分が正（親>ロット）: **263件**
- 親とロットの差分が負（ロット>親）: **5,426件**
- 予約数だけ不一致: **33件**
- 負数在庫: 親**803件**、ロット**782件**
- ロケーションなしロット: **1件**
- flags=8ロケーション上の有効ロット: **179件** (18,287個) — 引当不可能

補正ルール案:

- 商品CD `156643` のように親在庫が業務上正で、ロットが欠落しているものは、監査承認後に調整ロットを作成する。
- 本番最小パッチ中は、ロット側が正に見えるものでも親在庫をロット合計へ再集計しない。
- 負数は自動補正せず、原因伝票を追跡して個別処理する。
- `reserved_quantity` は既存の `real_stock_lot_earnings`、WMS出荷キュー、未完了波動を見てから補正する。
- flags=8 ロケーション上のロットは引当可能ロケーションへ移動する（新規作成ではなく移動）。

## 直近の判断

商品CD `156643` の欠品は、在庫そのものがないのではなく、WMSが引当可能な有効ロットがないために発生している。現在は `real_stocks` が正であるため、短期対応としては、この `real_stock_id=29561` に対して **flags=3 以上の引当可能ロケーション**付きの調整ロットを作れば波動引当は可能になる。

ただし同様の不整合がLOCAL DBで数千件（倉庫91だけで457件がWMSピック可能ロットなし）あるため、個別補正だけでは再発する。本番最小パッチでは波動対象だけを補完し、恒久対応では親在庫直接更新経路をロット同期サービスに寄せる。

## HanaDBTransfer / Oracle による Z-0-0 ロット移動検証

ユーザー前提:

- 2026-05-09 朝の本番データをローカルへ取り込んだため、在庫・ロケーション関連は本番と同一。
- 受注登録が開始されており、すでに在庫引当が実行されている。
- 旧DBは Oracle。SQL Server 参照ではなく `/Users/jungsinyu/PycharmProjects/HanaDBTransfer` を利用する。

確認したプロジェクト:

- `/Users/jungsinyu/PycharmProjects/HanaDBTransfer`
- Oracle 接続: `lib/oracle_utils.py`。Oracle は読み取り専用。
- 在庫全量移行: `stock_transfer_main.py`
  - `Oracle Ｔ３在庫 -> MySQL real_stocks + real_stock_lots`
  - 注意: このスクリプトは `real_stocks` / `real_stock_lots` を truncate して再投入する全量移行であり、現在運用中データへの Z-0-0 限定補正にはそのまま使えない。
- 既存補正系:
  - `patches/retarget_wms_locations_to_preferred_oracle_shelf.py`
  - `patches/retarget_wms_lot_locations_to_full_shelf.py`
  - いずれも対象が広く、今回の「default のもののみ」には限定不足。

追加したローカル検証用バッチ:

- `/Users/jungsinyu/PycharmProjects/HanaDBTransfer/patches/retarget_z00_wms_lots_from_oracle_shelf.py`
- 対象は `real_stock_lots` の ACTIVE かつ現在ロケーションが `Z-0-0` のロットのみ。
- Oracle は `Ｔ３在庫` の最新 `管理年月` と `Ｍ２店舗取扱.棚番` を参照する。
- MySQL 更新は `--apply --yes` がない限り実行しない。デフォルトは dry-run。
- ロット移動時は数量を一切変更せず、`floor_id` / `location_id` のみ更新する。
- 同じ旧 `Z-0-0` を参照する `wms_reservations` / `wms_picking_item_results` がある場合は、整合維持のため同じ移動先へ更新候補に含める。

ローカル dry-run コマンド:

```bash
MYSQL_DATABASE=hana_local python3 patches/retarget_z00_wms_lots_from_oracle_shelf.py \
  --warehouse-code 91 \
  --output-dir patches/output/retarget_z00_wms_lots_local_check
```

dry-run 結果:

- Oracle 最新在庫年月: `202605`
- 倉庫91 `Z-0-0` ACTIVE ロット: 179件 / 18,287個 / 99商品
- うち数量ありロット: 163件 / 18,287個 / 87商品
- `Z-0-0` ロットの予約数量合計: 37個
- 対象商品に対する Oracle 在庫行: 20件
- Oracle 側で非空の棚番が取れた商品: 0件
- 作成予定ロケーション: 0件
- ロット移動予定: 0件
- reservation 更新予定: 0件
- picking_result 更新予定: 0件
- skip: 163件（理由: `no_nonblank_oracle_shelf`）

生成された監査CSV:

- `patches/output/retarget_z00_wms_lots_local_check/summary.csv`
- `patches/output/retarget_z00_wms_lots_local_check/z00_lot_samples.csv`
- `patches/output/retarget_z00_wms_lots_local_check/oracle_shelf_audit.csv`
- `patches/output/retarget_z00_wms_lots_local_check/skipped_targets.csv`

代表サンプル:

| 商品CD | 商品名 | Z-0-0数量 | 予約 | Oracle棚番 |
|---:|---|---:|---:|---|
| 142932 | アサヒ スーパードライ 冷涼辛口350ml | 11,520 | 0 | なし |
| 142933 | アサヒ スーパードライ 冷涼辛口500ml | 2,976 | 0 | なし |
| 142642 | サントリー パーフェクトサントリービール エールビール350ml | 2,208 | 0 | なし |
| 142643 | サントリー パーフェクトサントリービール エールビール500ml | 624 | 0 | なし |
| 141336 | アサヒ スーパードライ 冷涼辛口 中瓶500ml | 280 | 0 | なし |
| 214194 | アサヒワンダ 特製カフェオレ185g缶 | 30 | 30 | なし |
| 156643 | シャプティエ コートデュローヌ ブラン ペルルーシュ750ml | 0（Z-0-0対象外） | 0 | なし |

現時点の判断:

- Oracle `Ｍ２店舗取扱.棚番` を正として Z-0-0 商品を通常ロケーションへ移す、という方針では、今回の対象に移動先棚が存在しない。
- したがって `Z-0-0` ロットの自動移動を本番へ適用してはいけない。適用しても更新 0 件で、問題の解消にならない。
- `156643` は Oracle でも棚番なしであり、今回の Z-0-0 移動バッチでは解決できない。波動欠品対策としては、別途「引当可能ロケーション付き調整ロット」を作るか、ロケーションマスタ/棚番マスタの正を業務側で決める必要がある。
- 次に必要なのは「棚番なし商品をどの引当可能ロケーションへ置くか」の業務ルール決定。Oracle に棚番がない以上、機械的な正解は取れない。

## 次に実装すべき最小修正

優先度順:

1. WMS `StockAllocationService` の候補ロット取得前に、対象商品の不足ロット補完を入れる（`LotCompensationService` 新規作成）。
2. 補完処理は `real_stocks` を更新せず、`real_stock_lots` だけに調整ロットを作る。
3. ロケーション選定: `item_incoming_default_locations` → 倉庫内 flags 対応ロケーション。**Z-0-0 は使用しない。**
4. 補完処理は波動生成対象に限定し、ログを必ず残す。
5. デフォルトロケーション上の死蔵ロット（179件, 18,287個）を引当可能ロケーションへ移動する。
6. AI Coreの `ProcessEarningDeliveryQueue` をWMSキュー形式に合わせ、出荷確定時に親在庫とロットを同じ方向へ減らす。自社セット構成品処理を追加する。
7. 監査コマンドを dry-run で追加し、不整合件数を本番で可視化する。

## 参照した主なファイル

### sakemaru-wms

- `app/Services/StockAllocationService.php` — 波動引当ロジック
- `app/Services/EarningDeliveryQueueService.php` — 出荷キュー作成
- `app/Jobs/ProcessEarningDeliveryQueue.php` — 出荷キュー処理（未dispatch）
- `app/Filament/Resources/Waves/Tables/WavesTable.php` — 波動取消
- `app/Models/Sakemaru/Location.php` — ロケーションモデル、デフォルトロケーション
- `app/Models/Sakemaru/RealStockLot.php` — ロットモデル
- `app/Enums/AvailableQuantityFlag.php` — ビットマスク定義

### sakemaru-ai-core

- `app/Services/LotAllocationService.php` — 正常系のロット同期（正しい経路）
- `app/Jobs/ProcessEarningDeliveryQueue.php` — 出荷キュー処理（自社セット欠落）
- `app/Actions/API/PostRealStocks.php` — 外部在庫同期（親のみ）
- `app/Actions/Trades/UpdateStockTransfer.php` — 倉庫移動出庫（親のみ）
- `app/Actions/Trades/ConfirmStockTransfer.php` — 倉庫移動入庫（親のみ）
- `app/Jobs/Polling/ProcessStockTransfer.php` — ポーリング倉庫移動（親のみ）
- `app/Actions/Trades/DeleteCashRealStock.php` — 売上取消（undeliver未呼出）
- `app/Actions/Trades/UpdateTradeItems.php` — 自社セット構成品（親のみ）
- `app/Actions/Trades/EarningMarkAsDeliveredStatus.php` — 出荷確定（自社セットは親のみ）
- `app/Jobs/ProcessPrintRequest.php` — 印刷リクエスト（自社セットは親のみ）
- `app/Actions/Item/DisassembleItemSet.php` — セット分解（親のみ）
- `app/Jobs/Web/CalculateSetItemStockJob.php` — セット品在庫計算（親のみ）
- `app/Models/RealStock.php` — syncWithWMS（親のみ）
- `app/Console/Commands/TempWork/SyncZeroStockCommand.php` — 一時コマンド（親のみ）

### sakemaru-trade

- `app/Services/Retail/RetailTradeCreationService.php` — POS取込（親のみ、ロット参照なし）
- `app/Models/BZCore/RealStock.php` — syncWithWMS（親のみ、デッドコードの可能性）
