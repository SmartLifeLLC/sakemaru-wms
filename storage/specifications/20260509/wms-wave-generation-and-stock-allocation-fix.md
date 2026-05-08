# WMS 波動生成・在庫引当改善仕様

作成日: 2026-05-09
対象DB: `hana_local`
対象システム: `sakemaru-wms`
関連調査報告: `storage/reports/20260509-wms-wave-stock-allocation-investigation.md`

## 目的

出荷波動の手動運用を前提に、以下の2つの問題を分けて解決する。

1. 同じ出荷日・同じ配送コースで複数回作る波動が、既存波動へ統合される問題を解消する。
2. `real_stocks` には在庫があるが、WMSが引当可能な `real_stock_lots` がないため欠品になる問題を、波動生成前の最小補完で解消する。

本仕様では、在庫全体の再構築や `real_stocks` の再集計は行わない。現時点の業務在庫の正は `real_stocks` とする。

## 前提

- WMS波動は手動生成を基本運用とする。
- 出荷締切をシステム側で厳密管理できていないため、生成時点で対象伝票を明確に固定する。
- 同じ配送コースでも1日に複数回波動を作る。午前・午後・追加出荷があるため。
- ピッキングリストは配送コース別にまとめる必要がある。
- 波動取消は `cancelWave(wave_id)` で、対象波動が明確に分かる形で行う。
- 外部連携キューが開始済みの波動は取消不可とする。

## 現状整理

### 波動生成

古い仕様では、波動単位は `倉庫 × 配送コース × 出荷日 × ピッキング時間帯` とされている。これは業務要件と一致する。

一方で、実装には以下の差分がある。

- `wms_waves` のDB制約は `wms_wave_setting_id + shipping_date` の UNIQUE ではなく通常 INDEX へ変更済み。
- DB上は同じ設定・同じ日付で複数波動を持てる。
- `WaveService::getOrCreateWave()` は同じ `wms_wave_setting_id + shipping_date` の既存波動を返すため、複数波動要件と衝突する。
- Filament手動生成側の `createWaveSafely()` は毎回新規波動を作る方向で、業務要件に近い。

### 在庫引当

現在の WMS 引当は `real_stock_lots + locations + wms_reservations` を基準に行う。

- `StockAllocationService` は `real_stock_lots` の ACTIVE ロットを候補にする。
- `locations.available_quantity_flags` のビットマスクで数量種別別に引当可否を判定する。
- `Z-0-0` は `available_quantity_flags = 8` のため、CASE/PIECE/CARTON のどれにも一致せず引当対象外。
- WMS波動生成時には `real_stocks` / `real_stock_lots` の数量は更新せず、`wms_reservations` と `wms_picking_item_results` を作成する。

調査時点の主な不整合:

| 指標 | 件数 |
| --- | ---: |
| `real_stocks.current_quantity > 0` だが有効ロットなし | 422 |
| 親現在庫と有効ロット現在庫の合計不一致 | 6,212 |
| 親で引当可能だがWMSピック可能ロットなし | 567 |
| 倉庫91: 親available>0 & WMSピック可能ロットなし | 457 |

商品CD `156643` は、倉庫91の `real_stocks.available_quantity = 24` だが引当可能ロットがなく、波動生成で欠品になった。

### Z-0-0 の扱い

HanaDBTransfer / Oracle で確認した結果、倉庫91の `Z-0-0` ACTIVE ロット 179件 / 18,287個 / 99商品は、Oracle `Ｍ２店舗取扱.棚番` が全て空だった。

このため、`Z-0-0` は棚番なし商品を意図的に置いたケースと判断する。全件を通常ロケーションへ自動移動しない。

ただし、WMS引当対象商品に不足ロットを補完する場合、補完先に `Z-0-0` は使わない。

## 業務要件

1. 波動は同じ配送コース・同じ出荷日でも複数回作成できる。
2. 手動生成時は、生成時点で `picking_status = BEFORE` の伝票だけを対象にする。
3. 既に別波動に入った伝票、ピッキング中、完了済み、出荷済みの伝票は新しい波動へ入れない。
4. ピッキングリストは配送コース別にまとめる。
5. 誤生成した場合は、対象波動を明示したうえで取消できる。
6. 出荷完了キュー、倉庫移動キュー、数量更新キューなど外部連携が始まっている波動は取消できない。
7. 親在庫はあるが引当可能ロットがない商品は、波動生成前に対象商品だけ補完する。
8. 補完処理で `real_stocks` は更新しない。

## 波動生成仕様

### 生成単位

波動は以下を表示・分類の単位とする。

- 倉庫
- 配送コース
- 出荷日
- 生成実行単位
- `wms_wave_setting_id`

`delivery_course_id + shipping_date` だけで既存波動へ統合しない。

### 対象抽出条件

売上伝票:

```text
earnings.delivered_date <= 指定出荷日（include_past=trueの場合）
earnings.is_delivered = 0
earnings.picking_status = BEFORE
earnings.delivery_course_id IN 選択配送コース
配送コースの倉庫が選択倉庫配下
```

倉庫間移動:

```text
COALESCE(stock_transfers.picking_date, stock_transfers.delivered_date) <= 指定出荷日（include_past=trueの場合）
stock_transfers.is_active = true
stock_transfers.picking_status = BEFORE
stock_transfers.from_warehouse_id が選択実倉庫配下
stock_transfers.delivery_course_id IN 選択配送コース
仮想倉庫間で同一実倉庫の場合は除外
```

### 既存波動の扱い

- 手動生成では既存波動を再利用しない。
- 自動生成では、同じ `wms_wave_setting_id + shipping_date` に未完了波動がある場合はスキップしてよい。
- 完了済み・取消済み波動がある場合でも、新規対象伝票があれば新しい波動を作成できる。
- 既存波動へ伝票を後付けしない。

### 一意性

DB上の一意性は `wave_no` のみとする。

`wms_wave_setting_id + shipping_date` は検索用 INDEX とし、UNIQUE にはしない。

### 波動番号

現在形式を継続する。

```text
W{warehouse_code:03d}-C{course_code}-{YYYYMMDD}-{wave_id}
```

`wave_id` を含むため、同じ配送コース・同じ日付でも一意になる。

## 緊急対応方針（2026-05-09）

本番運用中のため、今日動かす修正は以下に限定する。

1. 既存データの限定パッチ
2. 波動が完了済みの既存波動へ再利用される問題の最小修正

今回は以下を実施しない。

- 自動補完サービスの常時実行化
- 補完ログテーブルの追加
- AI Core の出荷完了キュー処理変更
- `real_stocks` の再集計
- `Z-0-0` 全体移動

### 既存データパッチ

`real_stocks.available_quantity > 0` だが、WMS引当可能な `real_stock_lots` が不足している対象だけ、明示指定で補正ロットを作成する。

使用コマンド:

```bash
php artisan stock:patch-wms-pickable-lots --warehouse-id=91 --item-code=156643 --quantity-type=PIECE --limit=1
php artisan stock:patch-wms-pickable-lots --warehouse-id=91 --item-code=156643 --quantity-type=PIECE --limit=1 --apply
```

安全条件:

- `--apply` なしは dry-run のみ
- `real_stocks` は更新しない
- `real_stock_lots` に ACTIVE ロットを追加するだけ
- 補正数量は `real_stocks.available_quantity - WMS引当可能ロットavailable合計` を上限にする
- 商品デフォルトロケーションを優先し、なければ倉庫内のWMS引当可能な通常ロケーションを使う
- `Z-0-0` / `available_quantity_flags = 8` / `floor_id IS NULL` は使わない

ローカル実行結果:

- 商品CD `156643`
- `real_stock_id = 29561`
- 追加ロット `real_stock_lots.id = 52979`
- 数量 `24`
- ロケーション `AA-1` (`locations.id = 18`)

再dry-runで補正対象なしになることを確認済み。

### 最小コード修正

`WaveService::getOrCreateWave()` は既存波動を探す際、`COMPLETED`, `CLOSED`, `CANCELLED` を再利用しない。

これにより、完了済み波動へ新しい伝票が紐づくリスクを止める。一方で、配送コース変更などで未完了波動へ移動する既存動作は維持する。

## 引当補完仕様（後続対応）

### 目的

`real_stocks` には業務在庫があるが、WMSが引当可能なロットがない場合に限り、波動生成対象の商品だけ補完ロットを作成する。

### 実行タイミング

各 `trade_item` の `StockAllocationService::allocateForItem()` 実行前に行う。

理由:

- 波動対象外の商品を補正しない。
- 必要数量・数量種別・倉庫・商品が確定している。
- GET_LOCK の対象である `warehouse_id:item_id` と同じ粒度で安全に処理できる。

### 補完対象

以下を全て満たす場合のみ補完候補とする。

```text
対象 warehouse_id / item_id の real_stocks が存在する
real_stocks.available_quantity > 0
WMS引当可能ロット available 合計 < real_stocks.available_quantity
対象商品が今回の波動生成対象明細に含まれる
```

WMS引当可能ロットとは、以下を満たすロット。

```text
real_stock_lots.status = ACTIVE
real_stock_lots.current_quantity > real_stock_lots.reserved_quantity
locations.available_quantity_flags & quantityFlag != 0
```

### 補完数量

補完数量は以下の差分を上限とする。

```text
補完可能差分 = real_stocks.available_quantity - pickable_lot_available_quantity
補完数量 = min(今回必要数量, 補完可能差分)
```

親在庫を超えるロットを作ってはいけない。

### 補完ロケーション

補完先ロケーションの優先順位:

1. `item_incoming_default_locations` の対象 `warehouse_id + item_id` ロケーション
2. 倉庫内の引当可能ロケーション（`available_quantity_flags & quantityFlag != 0`）の代表ロケーション
3. 見つからない場合は補完しない

禁止:

- `Z-0-0`
- `available_quantity_flags = 8`
- `floor_id IS NULL` のロケーション
- 引当対象数量種別に対応しないロケーション

### 補完ロットの属性

補完ロットは `real_stock_lots` に作成する。

```text
real_stock_id: 対象 real_stocks.id
current_quantity: 補完数量
reserved_quantity: 0
status: ACTIVE
location_id: 補完先ロケーション
floor_id: 補完先ロケーションの floor_id
expiration_date: 商品が期限管理対象なら既存情報から取得。判断不能なら NULL
purchase_id: NULL
trade_item_id: NULL
created_at / updated_at: 実行時刻
```

`real_stocks.current_quantity` / `reserved_quantity` / `available_quantity` は更新しない。

### 冪等性

補完は再実行で増殖してはいけない。

実装では少なくとも以下を満たす。

- 同一 `real_stock_id` の補完後に再度 `pickable_lot_available_quantity` を再計算する。
- 補完数量は常に親availableとの差分以内にする。
- 補完ログに `wave_id`, `source_type`, `source_id`, `source_line_id`, `real_stock_id`, `item_id`, `warehouse_id`, `created_lot_id`, `quantity`, `reason` を残す。

必要であれば、後続で補完ログテーブルを追加する。

### 補完しないケース

- 親在庫が0以下
- 既に引当可能ロットで足りている
- 補完先ロケーションがない
- 商品がWMS対象外と判定される
- 負数在庫や予約超過など、補完で解消すべきでない異常

## Z-0-0 仕様

`Z-0-0` は「棚番なし・WMS引当対象外」の保管先として扱う。

- 全件移動しない。
- WMS引当候補に含めない。
- 補完ロットの作成先にしない。
- 棚番なし商品が `Z-0-0` に存在すること自体は異常扱いしない。
- WMS対象商品が `Z-0-0` にしかない場合は、親在庫と引当可能ロット差分の補完対象として扱う。

## 取消仕様

波動取消は対象 `wave_id` を明示して実行する。

取消できる条件:

- 波動が `CLOSED` ではない
- 出荷済みではない
- 外部連携キューが作成されていない
- ピッキング完了後の出荷連携が始まっていない

取消時に戻すもの:

- `wms_picking_item_results`
- `wms_shortage_allocations`
- `wms_shortages`
- `wms_reservations`
- `wms_picking_tasks`
- 対象 `earnings.picking_status`
- 対象 `stock_transfers.picking_status`
- `wms_waves.status = CLOSED`

取消時に戻さないもの:

- `real_stocks`
- `real_stock_lots`

理由:

WMS波動生成時点では親在庫・ロット在庫を直接拘束更新していないため。補完ロットについては取消では削除しない。補完ロットは親在庫との差分を埋める在庫整合用ロットであり、波動作業データではないため。

## 出荷完了キューとの関係

波動生成・引当補完だけでは、出荷確定時の在庫減算問題は完全には解決しない。

WMSはピッキング完了時に `earning_delivery_queue` を作成し、`items` に `earning_id`, `item_id`, `quantity`, `trade_item_id`, `real_stock_id` を渡す。一方、現行の AI Core `ProcessEarningDeliveryQueue` は `earning_ids` だけを使い、`LotAllocationService::deliver($earningId)` を呼び出す。

`LotAllocationService::deliver()` は `real_stock_lot_earnings.status = RESERVED` の既存レコードを前提に、該当ロットと親在庫を減算する。WMS波動生成経路は `wms_reservations` を作るが、通常の `real_stock_lot_earnings` は作らないため、現行AI Coreのままでは以下のリスクがある。

- `earning_delivery_queue` が `COMPLETED` になっても、該当する `real_stocks` / `real_stock_lots` が減算されない。
- WMS補完ロットを作って引当可能にしても、AI Coreが補完ロットを参照しないため出荷減算に使われない。
- 自社セットは `LotAllocationService::deliver()` だけでは構成品へ反映されない。

別途、AI Core 側で以下を修正する必要がある。

- `ProcessEarningDeliveryQueue` が WMS予約または補完ロットを前提に、親在庫とロットを同方向へ減算する。
- 自社セット構成品の在庫減算を追加する。
- `real_stock_lot_earnings` が存在しないWMS経路でも在庫が減るようにする。

### リリース依存

波動生成の既存波動統合をやめる修正は、在庫数量を直接変更しないため WMS 単独リリースが可能。

ただし、引当補完と出荷完了後の在庫減算まで有効化する場合は、AI Core の `earning_delivery_queue` 処理修正を同じリリース枠で適用する。WMSだけを先行して補完ロット作成・WMS出荷完了運用に入ると、queue完了と在庫減算の整合が取れない。

同時リリースできない場合は、以下の制限を置く。

- WMS単独リリースの範囲は、同日同コースの新規波動生成と取消安全条件までに限定する。
- 引当補完は feature flag または設定で無効のままにする。
- WMS出荷完了後に `earning_delivery_queue` を作る経路は、AI Core側がWMS itemsを処理できることを確認するまで本番運用対象にしない。
- 既にWMS出荷完了運用がある場合は、queue完了済みで在庫未減算の監査SQLを先に実行する。

## 実装対象

### sakemaru-wms

1. `WaveService::getOrCreateWave()` の既存波動再利用を廃止または用途限定する。
2. 手動生成経路は常に新規 `wms_waves` を作成する。
3. `StockAllocationService::allocateForItem()` の候補取得前に不足ロット補完を呼ぶ。
4. `LotCompensationService` を追加する。
5. 補完ログを残す。
6. 監査コマンドを追加し、親在庫・引当可能ロット・Z-0-0ロットをレポートする。

### sakemaru-ai-core

1. `ProcessEarningDeliveryQueue` のWMS経路を在庫減算できるように修正する。
2. 自社セット構成品の処理を追加する。
3. 親在庫直接更新経路を段階的にロット同期方式へ寄せる。

## 検証項目

### 波動生成

- 同じ出荷日・同じ配送コースで、午前波動と午後波動を別々に作成できる。
- 午前波動に入った伝票は午後波動に再度入らない。
- 午前波動が `COMPLETED` でも、午後の新規伝票は新しい波動に入る。
- 手動生成で `wave_no` が一意になる。
- ピッキングリストは配送コース別に出力される。

### 引当補完

- 商品CD `156643` のように親在庫あり・引当可能ロットなしの場合、補完ロット作成後に引当できる。
- 補完ロットは `Z-0-0` に作られない。
- 親在庫を超える補完ロットが作られない。
- 同じ波動生成をリトライしても補完ロットが増殖しない。
- 既に引当可能ロットが十分ある商品には補完しない。
- 補完先ロケーションがない場合は欠品として残し、ログに理由を残す。

### 取消

- 取消可能な波動は、作業データ削除と伝票ステータス戻しが行われる。
- 外部キュー作成済みの波動は取消できない。
- 補完ロットは取消で削除されない。

## 本番適用方針

1. まずローカルで `156643` を含む対象波動を再現する。
2. 引当補完 dry-run / ログ確認を行う。
3. 手動生成で新規波動が既存波動に統合されないことを確認する。
4. AI Core `ProcessEarningDeliveryQueue` が WMS queue `items` を使って親在庫・ロットを減算できることを確認する。
5. AI Core未リリースの場合、WMSは波動生成・取消のみ先行し、引当補完は有効化しない。
6. 小さい配送コースで本番適用する。
7. 監査コマンドで補完ロット数・欠品数・Z-0-0在庫・queue完了後の在庫減算を確認する。

## 参照ファイル

- `app/Filament/Resources/Waves/Pages/ListWaves.php`
- `app/Console/Commands/GenerateWavesCommand.php`
- `app/Services/WaveService.php`
- `app/Services/StockAllocationService.php`
- `app/Filament/Resources/Waves/Tables/WavesTable.php`
- `app/Models/Wave.php`
- `app/Models/WaveSetting.php`
- `storage/reports/20260509-wms-wave-stock-allocation-investigation.md`
