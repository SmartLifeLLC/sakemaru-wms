# 横持ち出荷API + Handy V2 作業計画

## 前提

- 仕様書: `storage/specifications/20260418/shipping-and-proxy-shipment-spec.md`（出荷全体フロー）
- 設計書: `storage/specifications/20260418/20260418-proxy-shipment-api-handy-v2-design.md`（API・画面・テスト設計）
- 通常出荷API（PickingTaskController）は実装済み・稼働中
- 管理画面での横持ち出荷指示・確定は実装済み（ProxyShipmentService, ShortageConfirmationService）
- 横持ち出荷のモバイル/Web対応が未実装

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | DB・モデル準備 | migration + WmsShortageAllocation model変更 | migration実行成功、model変更反映 |
| P2 | バックエンドAPI実装 | Controller + QueryService + PickingService | 5エンドポイントが curl で動作 |
| P3 | StockTransferQueueServiceべき等化 | request_idベースの重複防止 | complete再送でqueue増えない |
| P4 | ルート・OpenAPI | route追加 + OA annotation | schedule:list表示、api-docs.json再生成 |
| P5 | Handy V2 横持ちタブ | Store + Service + Blade 3画面 | ブラウザで一覧→ピッキング→完了が動作 |
| P6 | テスト | Feature test + 手動試験手順 | テスト全件パス |

---

## P1: DB・モデル準備

### 目的

`wms_shortage_allocations` にモバイル作業の開始/完了ピッカー情報を保持するカラムを追加し、モデルを更新する。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `database/migrations/xxxx_add_picker_columns_to_wms_shortage_allocations.php` | 新規migration |
| `app/Models/WmsShortageAllocation.php` | fillable, casts, relation, scope追加 |

### 手順

1. migration作成:
   ```bash
   php artisan make:migration add_picker_columns_to_wms_shortage_allocations
   ```

2. 追加カラム:
   ```php
   $table->timestamp('started_at')->nullable()->after('is_finished');
   $table->unsignedBigInteger('started_picker_id')->nullable()->after('started_at');
   $table->unsignedBigInteger('finished_picker_id')->nullable()->after('started_picker_id');
   ```
   - connection: `sakemaru`
   - down: カラム削除

3. モデル変更（`WmsShortageAllocation.php`）:
   - `$fillable` に3カラム追加
   - `$casts` に `'started_at' => 'datetime'` 追加
   - relation追加:
     ```php
     public function startedPicker() { return $this->belongsTo(WmsPicker::class, 'started_picker_id'); }
     public function finishedPicker() { return $this->belongsTo(WmsPicker::class, 'finished_picker_id'); }
     ```
   - scope追加:
     ```php
     public function scopeReadyForProxyPicking($query) {
         return $query->where('is_confirmed', true)
             ->where('is_finished', false)
             ->whereIn('status', [self::STATUS_RESERVED, self::STATUS_PICKING]);
     }
     ```

4. migration実行:
   ```bash
   php artisan migrate
   ```

### 完了条件

- migration成功
- `describe wms_shortage_allocations` で3カラム確認
- モデルのscope/relation定義完了

---

## P2: バックエンドAPI実装

### 目的

横持ち出荷の5エンドポイント（一覧/詳細/開始/更新/完了）を実装する。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Http/Controllers/Api/ProxyShipmentController.php` | 新規Controller |
| `app/Services/Shortage/ProxyShipmentQueryService.php` | 新規: 一覧/詳細/候補ロケーション |
| `app/Services/Shortage/ProxyShipmentPickingService.php` | 新規: 開始/更新/完了 |

### 手順

#### 2.1 ProxyShipmentQueryService

設計書セクション8.1の通り:

```php
class ProxyShipmentQueryService {
    // 一覧: warehouse_id必須, shipment_date/delivery_course_id任意
    public function listForWarehouse(int $warehouseId, ?string $shipmentDate, ?int $deliveryCourseId): array

    // 詳細: allocation_idとwarehouse_idの一致検証含む
    public function findForWarehouse(int $allocationId, int $warehouseId): WmsShortageAllocation

    // 候補ロケーション: FEFO/FIFOで返却（設計書セクション6.3のSQL参照）
    public function getCandidateLocations(WmsShortageAllocation $allocation): array
}
```

一覧クエリ条件（設計書セクション6.2）:
```sql
WHERE sa.is_confirmed = true
  AND sa.is_finished = false
  AND sa.status IN ('RESERVED', 'PICKING')
  AND sa.target_warehouse_id = :warehouse_id
```

レスポンス整形:
- `target_warehouse_id` → `pickup_warehouse`
- `source_warehouse_id` → `destination_warehouse`
- item: code, name, jan_codes, volume, capacity_case, temperature_type, images
- customer: shortageのearning → trade → partner から取得
- summary: total_count, by_delivery_course

#### 2.2 ProxyShipmentPickingService

設計書セクション8.2の通り:

```php
class ProxyShipmentPickingService {
    // 開始: RESERVED→PICKING, started_at/started_picker_id設定
    public function start(WmsShortageAllocation $allocation, WmsPicker $picker): WmsShortageAllocation

    // 更新: picked_qty更新, RESERVED→暗黙PICKING遷移
    public function update(WmsShortageAllocation $allocation, WmsPicker $picker, int $pickedQty): WmsShortageAllocation

    // 完了: FULFILLED/SHORTAGE判定, is_finished=true, stock_transfer_queue作成, 親shortage再計算
    public function complete(WmsShortageAllocation $allocation, WmsPicker $picker, ?int $pickedQty): array
}
```

完了ステータス判定（設計書セクション6.6）:
- `picked_qty >= assign_qty` → `FULFILLED`
- `0 < picked_qty < assign_qty` → `SHORTAGE`
- `picked_qty = 0` → `SHORTAGE`

べき等性:
- `is_finished = true` の再送は200を返す（後処理スキップ）

#### 2.3 ProxyShipmentController

5エンドポイント実装:

| メソッド | パス | バリデーション |
|---------|------|--------------|
| `index` | GET /api/proxy-shipments | warehouse_id必須 |
| `show` | GET /api/proxy-shipments/{id} | warehouse_id必須、倉庫一致検証 |
| `start` | POST /api/proxy-shipments/{id}/start | warehouse_id必須、RESERVED/PICKING検証 |
| `update` | POST /api/proxy-shipments/{id}/update | warehouse_id必須、picked_qty: 0〜assign_qty |
| `complete` | POST /api/proxy-shipments/{id}/complete | warehouse_id必須 |

共通:
- レスポンス形式は既存API準拠: `{ is_success, code, result: { data, message } }`
- エラーは設計書セクション6.7の通り

### 完了条件

- 全5エンドポイントが curl で正常動作
- 一覧にRESERVED allocationが表示される
- PENDINGは表示されない
- start→update→completeのフローが通る

---

## P3: StockTransferQueueServiceべき等化

### 目的

横持ち出荷完了の再送で `stock_transfer_queue` が重複作成されないようにする。

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Services/Shortage/StockTransferQueueService.php` | request_id変更 + 既存チェック追加 |

### 手順

1. `createStockTransferQueue()` メソッドを改修:
   - `request_id` を `"proxy-shipment-{allocation_id}"` に変更（設計書セクション6.6）
   - insert前に同 `request_id` の既存queueを検索
   - 既存があればその `id` を返す（新規作成しない）

2. 既存の `request_id = (string) $allocation->id` との後方互換:
   - 新フォーマット `proxy-shipment-` prefix で区別可能

### 完了条件

- complete APIを2回呼んでも `stock_transfer_queue` は1件のみ
- 既存の管理画面経由の横持ち出荷完了は影響なし

---

## P4: ルート・OpenAPI

### 目的

APIルートを登録し、OpenAPI仕様を更新する。

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `routes/api.php` | 横持ちエンドポイント5件追加 |
| `app/Http/Controllers/Api/ProxyShipmentController.php` | OA annotation追加 |

### 手順

1. `routes/api.php` の `auth:sanctum` ミドルウェアグループ内に追加:
   ```php
   // Proxy shipment (横持ち出荷) endpoints
   Route::get('/proxy-shipments', [ProxyShipmentController::class, 'index']);
   Route::get('/proxy-shipments/{id}', [ProxyShipmentController::class, 'show']);
   Route::post('/proxy-shipments/{id}/start', [ProxyShipmentController::class, 'start']);
   Route::post('/proxy-shipments/{id}/update', [ProxyShipmentController::class, 'update']);
   Route::post('/proxy-shipments/{id}/complete', [ProxyShipmentController::class, 'complete']);
   ```

2. Controller各メソッドに `@OA\Get` / `@OA\Post` annotation追加（既存PickingTaskControllerのパターンを参考）

3. api-docs.json再生成:
   ```bash
   php artisan l5-swagger:generate
   ```

### 完了条件

- `php artisan route:list | grep proxy` で5ルート表示
- api-docs.json に proxy-shipments が含まれる

---

## P5: Handy V2 横持ちタブ

### 目的

Handy V2に横持ちタブを追加し、ブラウザからAPIテストできるようにする。

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `resources/js/handy-v2/stores/proxy-shipment.js` | 新規Store |
| `resources/js/handy-v2/services/proxy-shipment-service.js` | 新規Service |
| `resources/views/handy-v2/partials/proxy-shipment/list.blade.php` | 新規: 一覧画面 |
| `resources/views/handy-v2/partials/proxy-shipment/item.blade.php` | 新規: 作業画面 |
| `resources/views/handy-v2/partials/proxy-shipment/result.blade.php` | 新規: 結果画面 |
| `resources/js/handy-v2/app.js` | タブ追加、画面遷移追加 |
| `resources/views/handy-v2/app.blade.php` | 横持ちタブと画面差し込み |

### 手順

#### 5.1 proxy-shipment-service.js

入荷サービス（`incoming-service.js`）のパターンを踏襲:

```javascript
const proxyShipmentService = {
    getList(warehouseId, shipmentDate, deliveryCourseId)  // GET /api/proxy-shipments
    getDetail(allocationId, warehouseId)                   // GET /api/proxy-shipments/{id}
    start(allocationId, warehouseId)                       // POST .../start
    update(allocationId, warehouseId, pickedQty)           // POST .../update
    complete(allocationId, warehouseId, pickedQty)         // POST .../complete
}
```

#### 5.2 proxy-shipment.js (Store)

```javascript
createProxyShipmentStore() → {
    // State
    allocations, summary,
    shipmentDateFilter, deliveryCourseFilter,
    currentAllocation, candidateLocations,
    pickedQty, lastResult,

    // Actions
    async loadAllocations(warehouseId)
    async loadDetail(allocationId, warehouseId)
    async startAllocation(allocationId, warehouseId)
    async updateAllocation(allocationId, warehouseId, pickedQty)
    async completeAllocation(allocationId, warehouseId, pickedQty)

    // Barcode
    checkBarcode(barcode)
}
```

#### 5.3 画面設計（設計書セクション9参照）

**一覧画面** (`list.blade.php`):
- 日付フィルタ（初期値: meta.business_date）
- 配送コースフィルタ（summaryから生成）
- allocationカード一覧（配送コース、得意先、商品、数量、ステータス）

**作業画面** (`item.blade.php`):
- 商品画像・JAN・容量
- 候補ロケーション一覧
- 数量入力（+/-ボタン）
- バーコード一致判定
- 「更新」「完了」ボタン

**結果画面** (`result.blade.php`):
- 完了メッセージ
- 実績数
- stock_transfer_queue_id（存在する場合）
- 「一覧へ戻る」ボタン

#### 5.4 app.js / app.blade.php 変更

- TABS に `PROXY_SHIPMENT` 追加
- SCREENS に `PROXY_SHIPMENT_LIST`, `PROXY_SHIPMENT_ITEM`, `PROXY_SHIPMENT_RESULT` 追加
- タブUI: `入荷 | 出荷 | 横持 | 設定`
- store初期化に `proxyShipment: createProxyShipmentStore()` 追加

#### 5.5 ビルド

```bash
npm run build
```

### 完了条件

- `/handy-v2/` で横持ちタブが表示される
- 一覧に確定済みallocationが表示される
- 日付/配送コースフィルタが動作する
- ピッキング→完了フローがブラウザで動作する

---

## P6: テスト

### 目的

横持ちAPIの自動テストを作成し、手動試験手順を文書化する。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `tests/Feature/Api/ProxyShipmentApiTest.php` | 新規: Feature test |

### テストケース（設計書セクション10.1）

1. `RESERVED` allocation が一覧に出る
2. `PENDING` allocation は一覧に出ない
3. `is_finished = true` は一覧に出ない
4. warehouse_id不一致は422
5. start で `PICKING` に変わる
6. start の再送は成功扱い
7. update で `picked_qty` が更新される
8. `picked_qty > assign_qty` は422
9. complete で `stock_transfer_queue` が1件だけ作られる
10. complete 再送で queue が増えない
11. `picked_qty = 0` で complete → SHORTAGE、queue作成なし

### 手動試験手順

1. 管理画面で横持ち出荷を作成・確定
2. `/handy-v2/` にログイン
3. 倉庫選択
4. 横持ちタブで当日データ表示確認
5. 日付変更、配送コース絞り込み確認
6. 数量更新 → 完了
7. DBで `stock_transfer_queue.request_id = proxy-shipment-{id}` 確認
8. 完了済み再表示しないことを確認

### テスト実行

```bash
php artisan test --filter=ProxyShipmentApiTest
```

### 完了条件

- 全テストケースパス
- 手動試験でブラウザフロー正常動作

---

## 制約（厳守）

1. **migrate:fresh / migrate:refresh 絶対禁止** — 共有DB（sakemaru）のため
2. **FK制約を作成しない** — 全リレーションはアプリケーションレベル
3. **PickingTaskControllerを変更しない** — 通常出荷と横持ちの責務混在を防ぐ
4. **ProxyShipmentService（既存）を変更しない** — 管理画面用CRUDは今回スコープ外
5. **完了APIは必ずべき等** — Android端末の通信再送対策
6. **Phase 1では在庫予約連動しない** — 候補ロケーション提示に留める

## 全体完了条件

1. 横持ちAPI 5エンドポイントが正常動作
2. 完了API再送で `stock_transfer_queue` が重複しない
3. Handy V2 で一覧→ピッキング→完了フローが動作
4. Feature test 全件パス
5. OpenAPI仕様にproxy-shipmentsが含まれる
