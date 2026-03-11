# 出荷ロジック全体仕様書

- **作成日**: 2026-03-10
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/202503100000/20260310-outbound-logic-specification/`

## 背景・目的

WMSの出荷ロジック（波動生成〜ピッキング〜欠品処理〜横持ち出荷〜出荷確定〜在庫移動・移動伝票生成）の全体フローと各ステップの条件・ルールを一元的に整理する。現状のコードベースから読み取れる全ての条件を網羅する。

---

## 1. 全体フロー概要

```
売上伝票(earnings) [picking_status=BEFORE]
    │
    ▼
━━━ 波動生成 (GenerateWavesCommand) ━━━
    │  - WaveSetting の picking_start_time に基づき実行
    │  - 配送コース単位で Wave を生成
    │  - 在庫引当 (StockAllocationService)
    │  - ピッキングタスク生成 (WmsPickingTask)
    │  - 在庫移動(stock_transfers)もピッキング対象に含む
    │
    ▼
━━━ ピッキング実行 ━━━
    │  - ハンディ端末からAPI経由で操作
    │  - ルート最適化 (A* + Nearest Insertion + 2-opt)
    │  - ピッキング結果を WmsPickingItemResult に記録
    │
    ▼
━━━ 欠品検出・処理 ━━━
    │  - 引当欠品: 在庫引当時に検出 (AllocationShortageDetector)
    │  - ピッキング欠品: ピッキング完了時に検出 (PickingShortageDetector)
    │  - 横持ち出荷: 他倉庫からの代理出荷 (ProxyShipmentService)
    │  - 欠品確認: 最終確定 (ShortageConfirmationService)
    │
    ▼
━━━ 出荷確定 ━━━
    │  - 印刷リクエスト生成 (PrintRequestService)
    │  - earnings.picking_status = 'SHIPPED'
    │  - stock_transfers.picking_status = 'SHIPPED'
    │
    ▼
━━━ 倉庫不一致処理 ━━━
       - 得意先倉庫 ≠ 配送コース倉庫の場合
       - 在庫移動伝票(stock_transfer_queue)を自動生成
       - WarehouseMismatchTransferService
```

---

## 2. 波動生成 (Wave Generation)

### 2.1 トリガー条件

| 項目 | 内容 |
|------|------|
| コマンド | `wms:generate-waves` |
| 実行間隔 | 毎分 (cron) |
| 対象テーブル | `wms_wave_settings` |

### 2.2 対象伝票の条件

#### 売上伝票 (earnings) の条件

```
earnings WHERE:
  - picking_status = 'BEFORE'
  - is_delivered = 0
  - delivered_date = {対象出荷日}
  - delivery_course_id = {WaveSettingの配送コースID}
  - 対応する trade が存在する
```

#### 在庫移動伝票 (stock_transfers) の条件

```
stock_transfers WHERE:
  - picking_status = 'BEFORE'
  - is_active = 1
  - picking_date (NULLの場合は delivered_date) = {対象出荷日}
  - delivery_course_id = {配送コースID}
  - 物理的なピッキングが必要（仮想倉庫チェックで除外されない）
```

**仮想倉庫による除外条件:**
- from_warehouse と to_warehouse が同一実倉庫の場合はピッキング不要
- `WarehouseResolver::isSameRealWarehouse()` で判定
- 実倉庫ID = `COALESCE(stock_warehouse_id, id)` で解決

### 2.3 Wave生成ロジック

```php
// WaveService::getOrCreateWave()
1. delivery_course_id + shipping_date で既存Wave検索
2. 見つからない場合:
   a. WaveSetting を delivery_course_id で検索
   b. picking_start_time <= 現在時刻 の設定を取得
   c. 設定なしの場合は一時的な WaveSetting + Wave を生成
3. Wave番号フォーマット: W{倉庫CD:3桁}-C{コースCD:3桁}-{YYYYMMDD}-{wave_id}
```

### 2.4 WaveSetting の構成

| カラム | 説明 |
|--------|------|
| delivery_course_id | 配送コースID（ユニーク制約） |
| picking_start_time | ピッキング開始時刻（この時刻以降に波動生成対象になる） |
| picking_deadline_time | ピッキング締切時刻 |
| name | 設定名 |

**注意:** `warehouse_id` は2026-02-21の改修で削除済み。配送コース単位で管理。

### 2.5 Wave ステータス遷移

```
PENDING → PICKING → SHORTAGE → COMPLETED → CLOSED
```

---

## 3. 在庫引当 (Stock Allocation)

### 3.1 引当戦略: FEFO → FIFO

| 優先順 | ソート基準 | 説明 |
|--------|-----------|------|
| 1 | `expiration_date ASC` (NULL last) | 賞味期限が早いものを優先 (FEFO) |
| 2 | `created_at ASC` | 同一賞味期限内では入庫日が古いものを優先 (FIFO) |
| 3 | `id ASC` | タイブレーカー |

### 3.2 引当対象在庫の条件

```sql
FROM real_stocks rs
JOIN real_stock_lots rsl ON rsl.real_stock_id = rs.id AND rsl.status = 'ACTIVE'
WHERE:
  - rs.current_quantity > rs.reserved_quantity  -- 引当可能数量あり
  - rs.warehouse_id = {対象倉庫ID}
  - rs.item_id = {対象商品ID}
  - location.available_quantity_flags & {数量タイプフラグ} != 0  -- 数量タイプ制約

  -- 得意先制限チェック（buyer_id指定時）
  - real_stock_lot_buyer_restrictions に制限なし
    OR 指定buyer_idが許可リストに含まれる
```

### 3.3 引当処理の制御

| 項目 | 値 |
|------|-----|
| バッチサイズ | 最大50行/バッチ |
| 最大ページ数 | 2ページ（100行） |
| ロック方式 | MySQL `GET_LOCK('alloc:{warehouse_id}:{item_id}', 1)` |
| 楽観ロック | `wms_lock_version` カラム |
| 冪等性 | `wms_idempotency_keys` テーブル（scope: `wave_reservation`） |

### 3.4 引当結果

| 結果 | wms_reservations.status | 説明 |
|------|------------------------|------|
| 全数引当成功 | `RESERVED` | shortage_qty = 0 |
| 一部引当 | `RESERVED` + 欠品レコード | 引当分はRESERVED、不足分はshortage |
| 全数欠品 | 欠品レコードのみ | shortage_qty = 全数量 |

### 3.5 数量管理の基本単位

全ての内部計算は **バラ (PIECE)** 単位で統一:
- ケース数量 × ケースサイズ = バラ数量
- ボール数量 × ボールサイズ = バラ数量
- 表示変換はUIレイヤーで実施

---

## 4. ピッキングタスク (Picking Task)

### 4.1 タスク生成ルール

```
trade_items をグルーピング:
  - (floor_id, picking_area_id) 単位で1タスク
  - 同一フロアの全アイテム → 1つのピッキングタスク
```

### 4.2 タスクの構成要素

| カラム | 説明 |
|--------|------|
| wave_id | 所属Wave |
| warehouse_id | 倉庫ID |
| trade_id | 取引ID |
| shipment_date | 出荷日 |
| delivery_course_id | 配送コースID |
| course_code | コースコード |
| warehouse_code | 倉庫コード |
| temperature_control_type | 温度帯（AMBIENT/CHILLED） |
| restricted_area | 制限エリアフラグ |
| status | タスクステータス |
| task_type | WAVE（通常）/ REALLOCATION（横持ち出荷） |
| picker_id | 担当ピッカー |

### 4.3 タスクステータス遷移

```
PENDING → PICKING_READY → PICKING → COMPLETED → SHIPPED
```

### 4.4 ピッキングアイテム結果 (WmsPickingItemResult)

各アイテムのピッキング詳細:

| カラム | 説明 |
|--------|------|
| ordered_qty / ordered_qty_type | 受注数量（元の注文） |
| planned_qty / planned_qty_type | 引当数量（計画） |
| picked_qty / picked_qty_type | ピッキング数量（実績） |
| shortage_qty | 欠品数量 |
| shortage_allocated_qty | 横持ち出荷で割当済み数量 |
| is_ready_to_shipment | 出荷準備完了フラグ |
| walking_order | 歩行順序（ルート最適化） |
| distance_from_previous | 前ロケーションからの距離 |
| source_type | EARNING / STOCK_TRANSFER |
| stock_transfer_id | 在庫移動ID（source_type=STOCK_TRANSFERの場合） |

### 4.5 ピッキングアイテムのステータス

```
PENDING → PICKING → COMPLETED
                  → SHORTAGE
```

### 4.6 仮想倉庫によるピッキングスキップ

**条件:** 売上伝票の倉庫と配送コースの倉庫が同一実倉庫

```php
if (WarehouseResolver::isSameRealWarehouse($earning->warehouse_id, $deliveryCourse->warehouse_id)) {
    // 物理ピッキング不要
    WmsPickingItemResult.status = 'COMPLETED'  // 自動完了
    earnings.picking_status = 'COMPLETED'
}
```

### 4.7 ルート最適化

| 項目 | 内容 |
|------|------|
| サービス | `PickRouteService` |
| アルゴリズム | A* + Nearest Insertion + 2-opt |
| 距離キャッシュ | `WmsLayoutDistanceService` (レイアウトハッシュ付き) |
| フロントポイント | ロケーション境界から5px内側 |
| 歩行順序 | ロケーション訪問順に連番割当 |

---

## 5. 欠品処理 (Shortage Handling)

### 5.1 欠品の種類

| 種類 | 検出タイミング | 検出サービス | 内容 |
|------|--------------|-------------|------|
| 引当欠品 (ALLOCATION) | 在庫引当時 | AllocationShortageDetector | 在庫不足で引当できない |
| ピッキング欠品 (PICKING) | ピッキング完了時 | PickingShortageDetector | 実棚と計画の差異 |

### 5.2 欠品レコードの数量フィールド

| フィールド | 計算式 | 説明 |
|-----------|--------|------|
| order_qty | - | 受注数量（バラ単位） |
| planned_qty | - | 引当数量（バラ単位） |
| picked_qty | - | ピッキング実績数量 |
| allocation_shortage_qty | order_qty - planned_qty | 引当欠品数量 |
| picking_shortage_qty | planned_qty - picked_qty | ピッキング欠品数量 |
| shortage_qty | order_qty - picked_qty | 総欠品数量 |
| remaining_qty | shortage_qty - SUM(allocations.assign_qty) | 未対応欠品数量 |

### 5.3 欠品ステータス遷移

```
OPEN → REALLOCATING → FULFILLED / OPEN → CANCELLED
                    → SHORTAGE（横持ちでも欠品）
                    → PARTIAL_SHORTAGE（一部のみ充足）
```

### 5.4 チェーン欠品 (Chained Shortage)

横持ち出荷先でも欠品が発生した場合、`parent_shortage_id` で親子関係を追跡。

### 5.5 ソフト欠品 vs ハード欠品

| 種類 | 条件 | 説明 |
|------|------|------|
| ソフト欠品 (引当欠品) | ordered_qty > planned_qty | 引当段階での不足 |
| ハード欠品 (物理欠品) | planned_qty > picked_qty | 現物ピッキング時の不足 |

---

## 6. 横持ち出荷 (Proxy Shipment / Cross-Dock Delivery)

### 6.1 横持ち出荷フロー

```
欠品検出 (wms_shortages)
    │
    ▼
横持ち出荷指示作成 (ProxyShipmentService::createProxyShipment)
    │  - WmsShortageAllocation 作成 (status=PENDING)
    │  - 代理倉庫(from_warehouse_id) を指定
    │  - 価格情報(purchase_price, tax_exempt_price, price)を設定
    │  - shortage.status = 'REALLOCATING'
    │
    ▼
横持ちピッキングタスク生成
    │  - task_type = 'REALLOCATION'
    │  - 代理倉庫での在庫引当 + ピッキング
    │
    ▼
欠品確認 (ShortageConfirmationService::confirm)
    │  - total_allocated_qty 計算
    │  - picking_item_result.shortage_allocated_qty = total_allocated_qty
    │  - picking_item_result.is_ready_to_shipment = true
    │  - shortage.is_confirmed = true
    │  - ConfirmShortageAllocations::execute() 実行
    │
    ▼
横持ち出荷完了
    └─→ StockTransferQueueService::createStockTransferQueue()
        - stock_transfer_queue レコード生成
        - 宛先倉庫の決定（下記参照）
```

### 6.2 横持ち出荷先倉庫の決定ロジック

```php
// StockTransferQueueService::determineToWarehouse()
if (earningの実倉庫 ≠ shortageの実倉庫) {
    // 直送: 売上倉庫（得意先倉庫）へ
    to_warehouse = earning の実倉庫コード
} else {
    // 同一実倉庫内: ソース倉庫へ
    to_warehouse = ソース倉庫コード
}
```

### 6.3 横持ち出荷のShortageAllocationステータス遷移

```
PENDING → RESERVED → PICKING → FULFILLED
                             → SHORTAGE（代理倉庫でも欠品）
                             → CANCELLED
```

### 6.4 横持ち出荷に必要な価格情報

| フィールド | 取得元 | 説明 |
|-----------|--------|------|
| purchase_price | trade_item | 仕入単価 |
| tax_exempt_price | trade_item | 税抜単価 |
| price | trade_item | 販売単価 |

---

## 7. 出荷確定 (Shipment Confirmation)

### 7.1 出荷確定の前提条件

| 条件 | チェック内容 |
|------|------------|
| タスク完了 | 全ピッキングアイテムが `is_ready_to_shipment = true` |
| 欠品同期 | 全欠品レコードが `is_synced = true`（基幹システムと同期済み） |
| ステータス | ピッキングアイテムが COMPLETED または SHORTAGE |

### 7.2 出荷確定処理フロー

```
1. PrintRequestService::createPrintRequest()
   │
   ├─ プリンター設定取得（倉庫 × 配送コース）
   ├─ ピッキングタスクから earning_ids / stock_transfer_ids を収集
   ├─ PrintRequestQueue レコード作成
   │   - print_type = 'CLIENT_SLIP_PRINTER'
   │   - printer_driver_id = プリンター設定から
   │   - earning_ids = [...]
   │   - stock_transfer_ids = [...]（在庫移動がある場合）
   │
   ├─ earnings.picking_status = 'SHIPPED' に更新
   └─ stock_transfers.picking_status = 'SHIPPED' に更新
```

### 7.3 印刷書類の種類

| 書類 | 対象 | 条件 |
|------|------|------|
| 売上伝票 | earnings | earning_ids が存在する場合 |
| 倉庫間移動納品書 | stock_transfers | stock_transfer_ids が存在する場合 |

---

## 8. 倉庫不一致処理・在庫移動伝票の生成

### 8.1 倉庫不一致とは

得意先に紐づく倉庫（`earnings.warehouse_id`）と配送コースに紐づく倉庫が異なる場合に発生。
例: 得意先は倉庫A所属だが、配送コース上は倉庫Bから出荷する場合。

### 8.2 検出タイミング

**トリガー:** `WmsPickingTask.status` が `SHIPPED` に変更された時点

```php
// WmsPickingTask モデルのステータス変更フック
if ($status === 'SHIPPED') {
    WarehouseMismatchTransferService::createMismatchTransfer($earningId);
}
```

### 8.3 不一致検出ロジック

```php
// WarehouseMismatchTransferService::createMismatchTransfer()
1. earning.warehouse_id を取得
2. delivery_course.warehouse_id を取得
3. WarehouseResolver::isSameRealWarehouse() で比較

if (異なる実倉庫) {
    // stock_transfer_queue レコード生成
    request_id = "wh-mismatch-{earning_id}"  // 冪等性キー
    from_warehouse = ピッキング倉庫（配送コース倉庫）
    to_warehouse = 売上倉庫（得意先倉庫）
}
```

### 8.4 仮想倉庫の解決

```php
// WarehouseResolver::resolveRealWarehouseId($warehouseId)
$warehouse = Warehouse::find($warehouseId);
return $warehouse->stock_warehouse_id ?? $warehouse->id;

// WarehouseResolver::isSameRealWarehouse($a, $b)
return resolveRealWarehouseId($a) === resolveRealWarehouseId($b);
```

### 8.5 移動伝票の生成

在庫移動伝票は `stock_transfer_queue` テーブルに登録され、基幹システム（sakemaru-ai-core）で処理される。

| フィールド | 内容 |
|-----------|------|
| request_id | `wh-mismatch-{earning_id}` (冪等性キー) |
| from_warehouse | ピッキング実行倉庫のコード |
| to_warehouse | 得意先倉庫（売上伝票の倉庫）のコード |
| 関連earning_id | 対象の売上伝票ID |

---

## 9. 在庫移動のピッキング統合

### 9.1 背景

在庫移動（stock_transfers）も物理的なピッキングが必要な場合がある。Wave生成時にearningsと一緒にピッキング対象として取り込む。

### 9.2 ピッキング対象となる在庫移動の条件

```
stock_transfers WHERE:
  - picking_status = 'BEFORE'
  - is_active = 1
  - 物理ピッキング必要
    AND NOT (from_warehouse と to_warehouse が同一実倉庫)
    AND NOT (仮想倉庫間の帳簿移動)
```

### 9.3 在庫移動のステータス遷移

```
BEFORE → BEFORE_PICKING → PICKING → COMPLETED → SHIPPED
```

### 9.4 ピッキングアイテムでの識別

| フィールド | 売上伝票の場合 | 在庫移動の場合 |
|-----------|--------------|--------------|
| source_type | `EARNING` | `STOCK_TRANSFER` |
| earning_id | 売上ID | NULL |
| stock_transfer_id | NULL | 在庫移動ID |

---

## 10. 配送コース切替 (Delivery Course Change)

### 10.1 手動切替

`DeliveryCourseChangeService` による配送コース変更:

```
1. 対象 trade の PENDING ピッキングアイテムを取得
2. 新しい配送コースのWaveを取得/生成
3. ピッキングアイテムを新Waveの適切なタスクに再割当
4. earnings.delivery_course_id を更新
5. 空になった旧タスクをクリーンアップ
```

### 10.2 自動切替（時間ベース）

`wms:switch-delivery-course` コマンドによる自動切替:

| 項目 | 内容 |
|------|------|
| 実行間隔 | 15分ごと |
| 設定テーブル | `wms_buyer_delivery_course_switch_settings` |
| 条件 | `switch_time <= 現在時刻` AND `last_executed_date != 今日` |
| 処理 | buyer_id の全未出荷 earnings の delivery_course_id をアトミックに UPDATE |

---

## 11. 関連テーブル一覧

### 11.1 WMS管理テーブル

| テーブル | 説明 |
|---------|------|
| wms_waves | 波動（出荷バッチ） |
| wms_wave_settings | 波動設定（配送コース×時間帯） |
| wms_reservations | 在庫引当レコード |
| wms_picking_tasks | ピッキングタスク |
| wms_picking_item_results | ピッキングアイテム結果 |
| wms_shortages | 欠品レコード |
| wms_shortage_allocations | 横持ち出荷割当 |
| wms_picking_logs | ピッキング操作ログ |
| wms_picking_areas | ピッキングエリア定義 |
| wms_pickers | ピッカー（作業者） |
| wms_locations / wms_location_levels | ロケーション管理 |
| wms_stock_transfers | 倉庫内在庫移動 |
| wms_buyer_delivery_course_switch_settings | 配送コース自動切替設定 |
| wms_idempotency_keys | 冪等性管理 |

### 11.2 基幹システム参照テーブル

| テーブル | 説明 | 用途 |
|---------|------|------|
| earnings | 売上伝票 | ピッキング対象の元データ |
| trades / trade_items | 取引・取引明細 | 注文数量・商品情報 |
| real_stocks / real_stock_lots | 実在庫 | 引当対象在庫 |
| stock_transfers | 在庫移動 | ピッキング対象（倉庫間移動） |
| stock_transfer_queue | 在庫移動キュー | 移動伝票の生成指示 |
| delivery_courses | 配送コース | Wave のグルーピング単位 |
| warehouses | 倉庫マスタ | 仮想/実倉庫の判定 |
| print_request_queue | 印刷キュー | 伝票印刷指示 |

### 11.3 ビュー

| ビュー | 説明 |
|--------|------|
| wms_v_stock_available | リアルタイム引当可能在庫（real_stocks + real_stock_lots） |

---

## 12. ステータス遷移まとめ

### earnings.picking_status

```
BEFORE → BEFORE_PICKING → PICKING → COMPLETED → SHIPPED
```

| ステータス | トリガー |
|-----------|---------|
| BEFORE | 初期状態 |
| BEFORE_PICKING | Wave生成・引当完了 |
| PICKING | ピッキングタスク開始 |
| COMPLETED | 全タスクのピッキング完了 |
| SHIPPED | 出荷確定（印刷リクエスト発行） |

### wms_waves.status

```
PENDING → PICKING → SHORTAGE → COMPLETED → CLOSED
```

### wms_picking_tasks.status

```
PENDING → PICKING_READY → PICKING → COMPLETED → SHIPPED
```

### wms_reservations.status

```
RESERVED → RELEASED / CONSUMED / CANCELLED
```

### wms_shortages.status

```
OPEN → REALLOCATING → SHORTAGE / PARTIAL_SHORTAGE / FULFILLED → CANCELLED
```

### wms_shortage_allocations.status

```
PENDING → RESERVED → PICKING → FULFILLED / SHORTAGE / CANCELLED
```

---

## 13. 設計原則・制約

| 原則 | 内容 |
|------|------|
| FK禁止 | 全リレーションはアプリケーションレベルで管理 |
| 楽観ロック | `wms_lock_version` による同時更新検出 |
| 冪等性 | `wms_idempotency_keys` による重複処理防止 |
| トランザクション | 在庫引当は原子的（reservation + real_stocks 更新） |
| 数量基準 | 内部はバラ(PIECE)単位で統一 |
| 仮想倉庫解決 | `COALESCE(stock_warehouse_id, id)` で実倉庫ID取得 |
| DB破壊禁止 | migrate:fresh/refresh/reset/db:wipe 絶対禁止 |

---

## 14. 対象ファイル

### サービス層
| ファイル | 役割 |
|---------|------|
| `app/Services/WaveService.php` | Wave ライフサイクル管理 |
| `app/Services/StockAllocationService.php` | 在庫引当(FEFO→FIFO) |
| `app/Services/WarehouseResolver.php` | 仮想/実倉庫の解決 |
| `app/Services/DeliveryCourseChangeService.php` | 配送コース変更 |
| `app/Services/WarehouseMismatchTransferService.php` | 倉庫不一致処理 |
| `app/Services/Picking/PickRouteService.php` | ルート最適化 |
| `app/Services/Shortage/AllocationShortageDetector.php` | 引当欠品検出 |
| `app/Services/Shortage/PickingShortageDetector.php` | ピッキング欠品検出 |
| `app/Services/Shortage/ProxyShipmentService.php` | 横持ち出荷作成 |
| `app/Services/Shortage/ShortageConfirmationService.php` | 欠品確認 |
| `app/Services/Shortage/StockTransferQueueService.php` | 在庫移動キュー |
| `app/Services/Print/PrintRequestService.php` | 印刷リクエスト |

### モデル
| ファイル | テーブル |
|---------|---------|
| `app/Models/Wave.php` | wms_waves |
| `app/Models/WaveSetting.php` | wms_wave_settings |
| `app/Models/WmsPickingTask.php` | wms_picking_tasks |
| `app/Models/WmsPickingItemResult.php` | wms_picking_item_results |
| `app/Models/WmsReservation.php` | wms_reservations |
| `app/Models/WmsShortage.php` | wms_shortages |
| `app/Models/WmsShortageAllocation.php` | wms_shortage_allocations |

### コマンド
| ファイル | コマンド |
|---------|---------|
| `app/Console/Commands/GenerateWavesCommand.php` | `wms:generate-waves` |
| `app/Console/Commands/SwitchDeliveryCourseCommand.php` | `wms:switch-delivery-course` |

### 参考仕様書
| ファイル | 内容 |
|---------|------|
| `storage/specifications/outbound/README.md` | 出荷システム全体仕様 |
| `storage/specifications/outbound/stock-transfers-picking-integration.md` | 在庫移動ピッキング統合 |
| `storage/specifications/outbound/print-request-queue-integration.md` | 印刷リクエスト統合 |
| `storage/specifications/wave-delivery-course-reform/plan.md` | 配送コース・波動改善計画 |

---

## 確認事項

1. **earnings.picking_status の管理**: 現在は複数箇所（WaveService、WmsPickingTask フック、PrintRequestService）で更新されている。状態管理の一元化が必要か？
2. **stock_transfer_queue の処理**: 基幹システム（sakemaru-ai-core）側の処理フローの確認が必要。WMS側では queue に投入するところまで。
3. **横持ち出荷のチェーン**: 代理出荷先でもさらに欠品 → 別倉庫から横持ち、のチェーンはどこまでサポートするか？（現在は parent_shortage_id で追跡可能）
4. **配送コース自動切替**: 15分間隔の粒度で十分か？切替後の Wave 再生成は自動で行われるか？
5. **印刷リクエストのタイミング**: 出荷確定と印刷は同タイミングか、分離すべきか？
