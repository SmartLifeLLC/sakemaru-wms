# 棚卸し機能 実装仕様書

- **作成日**: 2026-05-23
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260523/20260523-022134-tanaoroshi-inventory-count/`

## 背景・目的

倉庫の実在庫と帳簿在庫の一致を確認し、差異を確定・反映するための棚卸し機能を実装する。

現在WMSには在庫スナップショット（`wms_stock_snapshots`）と廃棄/調整API（`stock-disposals`）は存在するが、棚卸し作業のワークフロー管理（開始→実棚入力→差異確認→確定→在庫反映）を一貫して行う機能がない。

**基本原則**: 棚卸し開始時に理論在庫をスナップショットし、確定時に差異数量のみを在庫調整として反映する。カウント中にreal_stocksを直接更新しない。

## 現状の実装

### 既存インフラ（再利用可能）

| コンポーネント | ファイル | 用途 |
|---|---|---|
| `InventoryStatus` enum | `app/Enums/InventoryStatus.php` | UNCONFIRMED / IN_PROGRESS / CONFIRMED / CANCELED |
| `TransactionType` enum | `app/Enums/TransactionType.php` | INVENTORY / INVENTORY_DIFF / INVENTORY_EXECUTE |
| `WmsStockSnapshot` model | `app/Models/WmsStockSnapshot.php` | 在庫スナップショット（参考。棚卸し専用スナップショットは別途作成） |
| `StockDisposalController` | `app/Http/Controllers/Api/StockDisposalController.php` | 廃棄/調整API（確定時の在庫反映パターン参考） |
| `HandyController` | `app/Http/Controllers/Handy/HandyController.php` | BHT-M60ハンディターミナルWebアプリ |
| WaveGroup作成フロー | `app/Filament/Resources/Waves/` | 棚卸し開始UIのモデル |
| WmsPickingWaitページ | `app/Filament/Pages/WmsPickingWait.php` | 進捗・詳細表示UIのモデル |
| `WmsModel` 基底クラス | `app/Models/WmsModel.php` | sakemaru接続 + modified_by追跡 |

### 参照ドキュメント

- `棚卸指示書（H）.pdf`: 倉庫作業者向け指示書サンプル（横向き、ロケーション順、バーコード付き）
- `棚卸差異リスト.pdf`: 差異確認レポートサンプル（167ページ、全倉庫対象）
- `preview (2).html`: 実装計画書（DB設計・API設計・ワークフロー詳細）

## 変更内容

### 概要

棚卸し作業の全ライフサイクル（作成→スナップショット→HT実棚入力→差異確認→確定→在庫調整反映）を管理する機能を、段階的に実装する。

### フェーズ分割

指示に従い「必要最低限→段階的拡張」で進める。各フェーズにゴールとテストを設定。

#### Phase 1: DB設計 + モデル + マイグレーション
**ゴール**: テーブル作成、モデル定義、基本リレーション確認

#### Phase 2: Web UI（一覧・作成・詳細）+ スナップショット取得
**ゴール**: 管理画面から棚卸し作成→スナップショット保存→商品一覧表示

#### Phase 3: 棚卸指示書PDF出力
**ゴール**: 指示書サンプルに準じたPDF出力

#### Phase 4: Android/HT API（実棚入力）
**ゴール**: HTからバーコードスキャン→実数量入力→保存

#### Phase 5: 差異確認 + 差異リスト出力
**ゴール**: 差異一覧表示、差異リストPDF出力

#### Phase 6: 確定 + 在庫調整反映
**ゴール**: 確定操作→difference_qty分のstock_movements作成→real_stocks更新

---

### 詳細設計

#### DB変更

##### テーブル: `wms_inventory_counts`（棚卸しヘッダー）

| カラム | 型 | 説明 |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `count_no` | VARCHAR(30) UNIQUE | 棚卸し番号（自動採番: `IC-YYYYMMDD-XXXXXXXX`） |
| `client_id` | INT | クライアントID |
| `warehouse_id` | INT | 対象倉庫ID |
| `warehouse_code` | VARCHAR(10) | 倉庫コード（スナップショット） |
| `warehouse_name` | VARCHAR(100) | 倉庫名称（スナップショット） |
| `count_date` | DATE | 棚卸し日 |
| `status` | ENUM | draft / counting / checked / confirmed / cancelled |
| `lock_mode` | BOOLEAN DEFAULT FALSE | 在庫移動ロック（運用ルールで管理。将来のシステムロック拡張用に保持） |
| `snapshot_taken_at` | DATETIME NULL | スナップショット取得日時 |
| `started_at` | DATETIME NULL | カウント開始日時 |
| `confirmed_at` | DATETIME NULL | 確定日時 |
| `confirmed_by` | INT NULL | 確定者ユーザーID |
| `memo` | TEXT NULL | メモ |
| `created_by` | INT NULL | 作成者ユーザーID |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

**status遷移**: `draft` → `counting` → `checked` → `confirmed` / `cancelled`

##### テーブル: `wms_inventory_count_items`（棚卸し明細）

| カラム | 型 | 説明 |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `inventory_count_id` | BIGINT UNSIGNED | FK的参照（wms_inventory_counts.id） |
| `real_stock_id` | BIGINT NULL | real_stocks.id |
| `item_id` | INT | 商品ID |
| `item_code` | VARCHAR(20) | 商品CD（スナップショット） |
| `item_name` | VARCHAR(200) | 商品名（スナップショット） |
| `barcode` | VARCHAR(50) NULL | JANコード |
| `location_id` | INT NULL | ロケーションID |
| `floor_id` | INT NULL | フロアID（locations.floor_id → floors.id） |
| `floor_name` | VARCHAR(50) NULL | フロア名（スナップショット。例: 1F, 2F, YXxxx） |
| `location_code1` | VARCHAR(10) NULL | 通路/エリア（locations.code1 スナップショット。例: H, A, AA） |
| `location_code2` | VARCHAR(10) NULL | 棚（locations.code2） |
| `location_code3` | VARCHAR(10) NULL | 段（locations.code3） |
| `location_no` | VARCHAR(30) NULL | ロケーション番号（code1-code2-code3 結合表示用） |
| `lot_id` | INT NULL | ロットID |
| `lot_no` | VARCHAR(30) NULL | ロット番号 |
| `expiration_date` | DATE NULL | 賞味期限 |
| `received_at` | DATETIME NULL | 入庫日 |
| `system_quantity` | DECIMAL(15,3) | 理論在庫数量（スナップショット） |
| `first_count_quantity` | DECIMAL(15,3) NULL | 1回目実棚数量 |
| `second_count_quantity` | DECIMAL(15,3) NULL | 2回目実棚数量 |
| `final_count_quantity` | DECIMAL(15,3) NULL | 最終確定数量 |
| `difference_quantity` | DECIMAL(15,3) NULL | 差異数量（final - system） |
| `cost_price` | DECIMAL(15,4) DEFAULT 0 | 仕入原価（スナップショット） |
| `difference_amount` | DECIMAL(15,2) NULL | 差異金額（difference_qty × cost） |
| `input_count` | TINYINT DEFAULT 0 | 入力回数 |
| `last_counted_at` | DATETIME NULL | 最終入力日時 |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

**インデックス**:
- `(inventory_count_id, item_id)` — 検索用
- `(inventory_count_id, floor_id, location_code1, location_code2, location_code3)` — フロア別グルーピング + エリア別フィルター + ロケーション順表示用
- `(inventory_count_id, barcode)` — バーコードスキャン検索用

##### テーブル: `wms_inventory_count_item_logs`（入力監査ログ）

| カラム | 型 | 説明 |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `inventory_count_item_id` | BIGINT UNSIGNED | wms_inventory_count_items.id |
| `device_id` | VARCHAR(50) NULL | HT端末ID |
| `user_id` | INT NULL | 入力者 |
| `count_round` | TINYINT | 入力回（1 or 2） |
| `old_quantity` | DECIMAL(15,3) NULL | 変更前数量 |
| `new_quantity` | DECIMAL(15,3) | 変更後数量 |
| `request_uuid` | VARCHAR(36) UNIQUE | 冪等性キー |
| `created_at` | DATETIME | |

#### モデル変更

##### 新規モデル

| モデル | テーブル | 基底クラス |
|---|---|---|
| `WmsInventoryCount` | `wms_inventory_counts` | `WmsModel` |
| `WmsInventoryCountItem` | `wms_inventory_count_items` | `WmsModel` |
| `WmsInventoryCountItemLog` | `wms_inventory_count_item_logs` | `WmsModel` |

**WmsInventoryCount**:
- `items()` hasMany WmsInventoryCountItem
- `warehouse()` belongsTo Warehouse
- `confirmedByUser()` belongsTo User
- `createdByUser()` belongsTo User
- `generateCountNo()` — `IC-YYYYMMDD-{8 random chars}`（WaveGroup.generateGroupNo()パターン）
- `scopeByStatus()` — ステータス絞り込み

**WmsInventoryCountItem**:
- `inventoryCount()` belongsTo WmsInventoryCount
- `logs()` hasMany WmsInventoryCountItemLog
- `item()` belongsTo Item
- `calculateDifference()` — final_count_quantity - system_quantity

#### サービス変更

##### 新規: `InventoryCountService`（`app/Services/InventoryCount/InventoryCountService.php`）

| メソッド | 説明 |
|---|---|
| `create(data)` | 棚卸しヘッダー作成 + count_no自動採番 |
| `takeSnapshot(inventoryCount)` | 対象倉庫の全real_stocks → count_itemsにスナップショット |
| `startCounting(inventoryCount)` | status → counting、started_at設定 |
| `registerCount(countItem, quantity, round, deviceId, userId, requestUuid)` | 実棚数量入力（冪等性チェック付き） |
| `calculateDifferences(inventoryCount)` | 全明細の差異計算、status → checked |
| `confirm(inventoryCount, userId)` | 確定処理（差異分の在庫調整反映） |
| `cancel(inventoryCount)` | 取消 |

**スナップショット取得（takeSnapshot）の詳細**:
```
real_stocks
  JOIN items ON items.id = real_stocks.item_id
  JOIN locations ON locations.id = real_stocks.location_id
  LEFT JOIN floors ON floors.id = locations.floor_id
  WHERE real_stocks.warehouse_id = :warehouse_id
  AND real_stocks.quantity != 0
→ wms_inventory_count_items に INSERT
  floor_id, floor_name（floors.name）,
  location_code1/code2/code3, location_no,
  item_code, item_name, barcode, cost_price 等をスナップショット
（倉庫内全ロケーション・全フロア対象）
```

**確定処理（confirm）の詳細**:
```
トランザクション内で:
  1. status = confirmed、confirmed_at、confirmed_by 設定
  2. difference_quantity != 0 の明細に対して:
     - StockDisposalController 経由で廃棄/調整レコード作成
       (理由コード: INVENTORY — 棚卸し調整)
     - 正差異=入庫調整、負差異=出庫調整
  3. wms_lock_version による楽観ロック
```

#### UI変更

##### Filament Resource: `WmsInventoryCountResource`

**一覧ページ（ListWmsInventoryCounts）**:
- WaveGroup一覧と同様のレイアウト
- カラム: 棚卸しNo / 倉庫 / 棚卸し日 / ステータス / 進捗（入力済/全体）/ 作成者 / 作成日時
- フィルター: 倉庫、ステータス、棚卸し日範囲
- ツールバーアクション: 「棚卸し作成」ボタン

**作成モーダル**:
- 倉庫選択（Select）
- 棚卸し日（DatePicker、デフォルト当日）
- メモ（Textarea）
- 作成後 → 倉庫内全在庫のスナップショット取得（非同期ジョブ）→ 詳細ページへ遷移

**詳細ページ（ViewWmsInventoryCount）**:
- WmsPickingWaitページ構造を参考
- ヘッダー: 棚卸し情報（No/倉庫/日付/ステータス/進捗）
- テーブル: 商品スナップショット一覧
  - カラム: フロア / エリア(code1) / ロケーション(code1-code2-code3) / 商品CD / 商品名 / ロットNo / 賞味期限 / 理論数量 / 1回目実棚 / 2回目実棚 / 最終数量 / 差異数量 / 差異金額
  - グルーピング: **フロア（1F / 2F / YXxxx）** で視覚的にグループ化
  - フィルター: **フロア** / **エリア（code1）** / 未入力のみ / 差異ありのみ
  - デフォルトソート: floor_name → location_code1 → code2 → code3 ASC（フロア→ロケーション歩行順）
- アクション:
  - 「カウント開始」（draft → counting）
  - 「差異計算」（counting → checked）
  - 「確定」（checked → confirmed、確認ダイアログ付き）
  - 「取消」（confirmed以外 → cancelled）
  - 「棚卸指示書PDF」（PDF出力）
  - 「差異リストPDF」（PDF出力、checked/confirmed時）

##### Android API エンドポイント

| メソッド | パス | 説明 |
|---|---|---|
| GET | `/api/wms/inventory-counts` | 棚卸し一覧（status=counting のみ） |
| GET | `/api/wms/inventory-counts/{id}` | 棚卸しヘッダー情報 |
| GET | `/api/wms/inventory-counts/{id}/items` | 明細一覧（filter: **floor**, **code1(エリア)**, uncounted, has-difference） |
| POST | `/api/wms/inventory-counts/{id}/scan` | バーコード/商品CDスキャン検索 |
| POST | `/api/wms/inventory-count-items/{itemId}/count` | 実棚数量登録（request_uuid冪等性） |
| GET | `/api/wms/inventory-count-items/{itemId}/logs` | 入力履歴 |

### 影響範囲

| 影響箇所 | 内容 | リスク |
|---|---|---|
| `real_stocks` テーブル | 確定時にquantity更新 | 楽観ロック（wms_lock_version）で保護 |
| `StockDisposalController` | 理由コード `INVENTORY` 追加、棚卸し調整レコード作成 | 既存の廃棄APIフローを流用 |
| メガメニュー | 「在庫管理 > 棚卸し」メニュー追加 | 既存メニュー構造への追加 |
| ルーティング | Android API エンドポイント追加 | 既存APIルートへの追加 |

## 制約

1. **FK禁止**: wms_inventory_count_items.inventory_count_id 等はアプリケーション層で整合性管理
2. **migrate:fresh/refresh/reset 禁止**: `php artisan migrate` のみ使用
3. **real_stocks直接更新はconfirm時のみ**: カウント中は一切触らない
4. **楽観ロック必須**: real_stocks更新時は `wms_lock_version` チェック
5. **冪等性**: Android API入力は `request_uuid` で重複送信を防止
6. **在庫移動ロックは運用ルール**: システム的なロックは実装しない。棚卸し中の入出庫停止は運用手順書で管理
7. **段階的実装**: 各フェーズでゴール設定 + テスト実施、慎重に進める
8. **サブエージェント活用**: 各フェーズの実装はサブエージェントで並列化

## 対象ファイル

### 新規作成

**マイグレーション**:
- `database/migrations/XXXX_create_wms_inventory_counts_table.php`
- `database/migrations/XXXX_create_wms_inventory_count_items_table.php`
- `database/migrations/XXXX_create_wms_inventory_count_item_logs_table.php`

**モデル**:
- `app/Models/WmsInventoryCount.php`
- `app/Models/WmsInventoryCountItem.php`
- `app/Models/WmsInventoryCountItemLog.php`

**サービス**:
- `app/Services/InventoryCount/InventoryCountService.php`

**Filament Resource**:
- `app/Filament/Resources/WmsInventoryCountResource.php`
- `app/Filament/Resources/WmsInventoryCount/Pages/ListWmsInventoryCounts.php`
- `app/Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php`
- `app/Filament/Resources/WmsInventoryCount/Tables/WmsInventoryCountTable.php`
- `app/Filament/Resources/WmsInventoryCount/Tables/WmsInventoryCountItemTable.php`

**APIコントローラー**:
- `app/Http/Controllers/Api/InventoryCountController.php`

**PDF出力（Phase 3/5）**:
- `app/Services/InventoryCount/InventoryInstructionPdfService.php`
- `app/Services/InventoryCount/InventoryDiffListPdfService.php`

### 既存変更

- `routes/api.php` — 棚卸しAPIルート追加
- `app/Providers/Filament/AdminPanelProvider.php` — リソース登録（自動検出の場合は不要）
- メガメニュー Blade — 「在庫管理 > 棚卸し」メニュー追加
- `app/Http/Controllers/Api/StockDisposalController.php` — 理由コード `INVENTORY` 追加

### 参照のみ

- `app/Models/WmsStockSnapshot.php` — スナップショットロジック参考
- `app/Filament/Pages/WmsPickingWait.php` — 進捗・詳細UI参考
- `app/Filament/Resources/Waves/` — Wave作成フロー参考
- `app/Http/Controllers/Api/StockDisposalController.php` — 在庫調整API参考
- `app/Http/Controllers/Handy/HandyController.php` — HT WebApp参考
- `app/Enums/InventoryStatus.php` — ステータスenum参考
- `app/Enums/TransactionType.php` — INVENTORY / INVENTORY_DIFF / INVENTORY_EXECUTE
- `storage/specifications/20260523/棚卸指示書（H）.pdf` — 指示書レイアウト参考
- `storage/specifications/20260523/20230815 棚卸差異リスト.pdf` — 差異リストレイアウト参考
- `storage/specifications/20260523/preview (2).html` — 実装計画書

## 確認事項

### 全件決定済み

- **HT実棚入力** → Android API（既存Handy WebAppではなく新規APIエンドポイント）
- **lock_mode** → 運用ルールで管理（システム的ロックは実装しない）
- **在庫調整反映** → 既存 `StockDisposalController` に理由コード `INVENTORY` を追加して連携
- **棚卸し対象の粒度** → 全倉庫単位、ロケーション順で並び替え。`floors` テーブル（1F / 2F / YXxxx）でグルーピング + `locations.code1` でフィルタリング
- **PDF出力ライブラリ** → TCPDF（`tecnickcom/tcpdf` ^6.10、WMSプロジェクトに導入済み。sakemaru-ai-core の `BaseTcpdfService` 等に実装パターンあり）
