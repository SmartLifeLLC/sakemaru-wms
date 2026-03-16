# 配送コース・波動生成改善 作業計画

## 前提

- 仕様書: `storage/specifications/outbound/20260219-wms-wave-delivery-course-reform.md` (v1.2)
- ベースブランチ: `release/v1.0`
- 現状: `wms_wave_settings` は `warehouse_id` を持ち、earnings のフィルタにも使用中
- 目標: 配送コース基準に統一し、倉庫不一致・配送コース切替に対応

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | WarehouseResolver ユーティリティ作成 | COALESCE(stock_warehouse_id, id)パターン集約 | ユーティリティが存在しPintが通る |
| P1 | wms_wave_settings.warehouse_id 削除 | モデル・サービス・UI・テストデータから warehouse_id 参照を除去 | 全ファイルの warehouse_id 参照がアクセサ経由に変更済み |
| P2 | StockTransferQueueService 実倉庫ベース修正 | 横持ち出荷の最終配送先を実倉庫ベースに | determineToWarehouse() が正しく動作 |
| P3 | DB変更とモデル作成（配送コース切替） | 新規テーブル + モデル作成 | マイグレーション・モデルが存在 |
| P4 | 出荷倉庫不一致対応 | 出荷確定時の在庫移動伝票自動生成 | WarehouseMismatchTransferService が正しく動作 |
| P5 | 得意先配送コース時間切替 | コマンド・Filament画面・スケジューラー | 切替コマンドとUI画面が動作 |
| P6 | マイグレーション実行・テスト・検証 | F-1bマイグレーション実行 + 全体テスト | Pint通過・テスト通過 |

---

## P0: WarehouseResolver ユーティリティ作成

### 目的

`COALESCE(stock_warehouse_id, id)` パターンが複数箇所（IncomingController, ListWaves, GenerateWavesCommand）に分散しているため、`WarehouseResolver` に集約する。

### 修正対象ファイル

| ファイル | 種別 | 役割 |
|---------|------|------|
| `app/Services/WarehouseResolver.php` | 新規作成 | 実倉庫解決ユーティリティ |

### 実装内容

仕様書 10.2 F-0 の実装詳細に従い、以下のメソッドを実装:
- `resolveRealWarehouseId(int $warehouseId): int` — 仮想倉庫を実倉庫IDに解決
- `isSameRealWarehouse(int $wh1, int $wh2): bool` — 同一実倉庫判定
- `getRealWarehouseCode(int $warehouseId): ?string` — 実倉庫コード取得

### 完了条件

- `app/Services/WarehouseResolver.php` が存在する
- `./vendor/bin/pint` が通る

---

## P1: wms_wave_settings.warehouse_id 削除（モデル・サービス改修）

### 目的

`wms_wave_settings.warehouse_id` カラムを削除し、ピッキング倉庫を `delivery_courses.warehouse_id` から実行時取得に変更する。

### 修正順序と対象ファイル

**以下の順序で修正する（依存関係あり）:**

#### Step 1: マイグレーション作成（実行は P6）

| ファイル | 修正ID |
|---------|--------|
| `database/migrations/2026_02_21_000001_remove_warehouse_id_from_wms_wave_settings.php` | F-1b |

- 仕様書 10.2 F-1b の実装詳細に従う
- 重複チェック → UNIQUE削除 → カラム削除 → 新UNIQUE作成
- **この時点では migrate 実行しない**（P6で実行）

#### Step 2: WaveSetting モデル改修

| ファイル | 修正ID |
|---------|--------|
| `app/Models/WaveSetting.php` | M-2b |

修正内容:
1. `$fillable` から `'warehouse_id'` を削除
2. `warehouse()` BelongsTo リレーションを削除
3. `getWarehouseIdAttribute()` アクセサを追加:
   ```php
   public function getWarehouseIdAttribute(): ?int
   {
       return $this->deliveryCourse?->warehouse_id;
   }
   ```
4. **注意**: `$this->deliveryCourse` を使うため、N+1問題を避けるため呼び出し元で `->with('deliveryCourse')` を追加する

#### Step 3: WaveService 改修

| ファイル | 修正ID |
|---------|--------|
| `app/Services/WaveService.php` | M-2 |

修正内容:
1. `findExistingWave($warehouseId, $courseId, $shippingDate)` → `findExistingWave($courseId, $shippingDate)` に変更
   - 内部の `->where('warehouse_id', $warehouseId)` を削除
2. `getOrCreateWave($warehouseId, $courseId, $date)` → `getOrCreateWave($courseId, $date)` に変更
   - WaveSetting検索クエリから `->where('warehouse_id', $warehouseId)` を削除
   - `$warehouseId` パラメータを削除
3. `createTemporaryWave($warehouseId, $courseId, $date)` → `createTemporaryWave($courseId, $date)` に変更
   - `WaveSetting::create` から `'warehouse_id' => $warehouseId` を削除
4. wave_no生成で使用する warehouse は `$waveSetting->warehouse_id`（アクセサ経由）から取得

#### Step 4: DeliveryCourseChangeService 改修

| ファイル | 修正ID |
|---------|--------|
| `app/Services/DeliveryCourseChangeService.php` | M-6 |

修正内容:
1. `$this->waveService->getOrCreateWave($warehouseId, ...)` から `$warehouseId` 引数を削除
2. warehouseId はアクセサ経由で取得されるため、明示的な引数は不要

#### Step 5: GenerateWavesCommand 改修

| ファイル | 修正ID |
|---------|--------|
| `app/Console/Commands/GenerateWavesCommand.php` | M-1 |

修正内容:
1. WaveSetting取得に `->with('deliveryCourse')` を追加
2. `$warehouseId` を `$setting->warehouse_id`（アクセサ経由、deliveryCourse->warehouse_id）から取得
3. earnings フィルタから `->where('warehouse_id', $setting->warehouse_id)` を **削除**
   - `delivery_course_id` のみでフィルタ
4. 仮想倉庫判定ロジックを追加（仕様書 7.1 Step 3-e-iv）:
   - `WarehouseResolver::isSameRealWarehouse()` で判定
   - 同一実倉庫 → PickingTask/ItemResult を COMPLETED に設定、earning.picking_status = 'COMPLETED'
   - 異なる実倉庫 → 通常通り earning.picking_status = 'BEFORE_PICKING'
5. `$setting->warehouse_id` の使用箇所は全てアクセサ経由で動作する（在庫引当、PickingArea、PickingTask作成等）

#### Step 6: ListWaves 改修

| ファイル | 修正ID |
|---------|--------|
| `app/Filament/Resources/Waves/Pages/ListWaves.php` | M-7 |

修正内容:
1. `generateManualWave()` 内の `WaveSetting` 検索から `->where('warehouse_id', ...)` を削除
2. `WaveSetting::create(...)` から `'warehouse_id' => $warehouseId` を削除
3. **注意**: UI上の倉庫Select（フィルタリング用途）はそのまま残す（方針4.8）
4. earnings のフィルタ条件: `warehouse_id` を使用するUIフィルタは残す（表示用）が、Wave生成ロジック自体は `delivery_course_id` 基準に変更

#### Step 7: Filament WaveSetting UI 改修

| ファイル | 修正ID |
|---------|--------|
| `app/Filament/Resources/WaveSettings/Schemas/WaveSettingForm.php` | M-8 |
| `app/Filament/Resources/WaveSettings/Tables/WaveSettingsTable.php` | M-9 |

**WaveSettingForm.php (M-8)**:
1. `warehouse_id` Select を削除
2. `delivery_course_id` Select を全配送コースから選択可能に変更（倉庫名プレフィックス付き表示）
3. 倉庫表示用の Placeholder を追加（選択した配送コースの倉庫名を表示）

**WaveSettingsTable.php (M-9)**:
1. `warehouse_id` カラムを削除
2. `delivery_course_id` から倉庫名を導出する表示カラムを追加

#### Step 8: テストデータコマンド・シーダー改修

| ファイル | 修正ID |
|---------|--------|
| `app/Console/Commands/TestData/GenerateWaveSettingsCommand.php` | M-10 |
| `database/seeders/WaveSettingSeeder.php` | M-11 |
| `app/Console/Commands/TestData/GeneratePickerWaveCommand.php` | M-12 |

**GenerateWaveSettingsCommand.php (M-10)**:
1. 存在チェックから `->where('warehouse_id', ...)` を削除
2. `WaveSetting::create(...)` から `'warehouse_id' => $warehouse->id` を削除

**WaveSettingSeeder.php (M-11)**:
1. `WaveSetting::create(...)` から `'warehouse_id' => $warehouse->id` を削除

**GeneratePickerWaveCommand.php (M-12)**:
1. `WaveSetting` 検索から `->where('warehouse_id', ...)` を削除
2. `WaveSetting::create(...)` から `'warehouse_id' => ...` を削除
3. `assignTasksToPicker()` の Wave 検索を `whereHas('waveSetting.deliveryCourse', ...)` に変更

### 完了条件

- 全ファイルから `wms_wave_settings.warehouse_id` への直接書き込み参照が除去されている
- `$waveSetting->warehouse_id` は全てアクセサ経由で `deliveryCourse->warehouse_id` を返す
- `./vendor/bin/pint` が通る
- **マイグレーションは未実行**（P6で実行）

---

## P2: StockTransferQueueService 実倉庫ベース修正

### 目的

横持ち出荷の最終配送先を、実倉庫ベース（WarehouseResolver使用）で判定する。

### 修正対象ファイル

| ファイル | 修正ID |
|---------|--------|
| `app/Services/Shortage/StockTransferQueueService.php` | M-4 |

### 実装内容

仕様書 10.2 M-4 に従い:
1. `use App\Services\WarehouseResolver;` を追加
2. `determineToWarehouse()` protected メソッドを追加
3. `createStockTransferQueue()` 内の `to_warehouse_code` を `determineToWarehouse()` の結果に変更

```
判定ロジック:
  shortage.earning_id → earnings.warehouse_id → 実倉庫解決
  shortage.warehouse_id → 実倉庫解決
  異なる実倉庫 → to = 販売倉庫の実倉庫コード（直接配送）
  同一実倉庫 → to = sourceWarehouse.code（既存動作維持）
```

### 完了条件

- `determineToWarehouse()` メソッドが存在する
- `WarehouseResolver` を使用している
- `./vendor/bin/pint` が通る

---

## P3: DB変更とモデル作成（配送コース切替）

### 目的

得意先の配送コース時間切替設定テーブルとモデルを作成する。

### 修正対象ファイル

| ファイル | 修正ID |
|---------|--------|
| `database/migrations/xxxx_create_wms_buyer_delivery_course_switch_settings_table.php` | F-1 |
| `app/Models/WmsBuyerDeliveryCourseSwitchSetting.php` | F-2 |

### 実装内容

**マイグレーション (F-1)**:
- 仕様書 6.1 のスキーマに従う
- connection: `sakemaru`
- カラム: id, buyer_id, switch_time, to_delivery_course_id, last_executed_date, last_executed_at, deleted_at, created_at, updated_at
- UNIQUE: `(buyer_id, switch_time)`
- INDEX: `(switch_time)`
- **マイグレーション実行する** (`php artisan migrate`)

**モデル (F-2)**:
- 仕様書 10.2 F-2 に従う
- `WmsModel` を継承
- `SoftDeletes` 使用
- リレーション: `buyer()`, `toDeliveryCourse()`
- バリデーションルール: `switchTimeRule()` — 15分単位のみ許可

### 完了条件

- マイグレーションファイルが存在する
- `php artisan migrate` でテーブルが作成される
- モデルが存在し、SoftDeletes・リレーションが正しい
- `./vendor/bin/pint` が通る

---

## P4: 出荷倉庫不一致対応

### 目的

出荷確定（SHIPPED）時に、配送コース倉庫と販売倉庫の不一致を検出し、在庫移動伝票を自動生成する。

### 修正対象ファイル

| ファイル | 修正ID |
|---------|--------|
| `app/Services/WarehouseMismatchTransferService.php` | F-4 |
| `app/Models/WmsPickingTask.php` | M-3 |

### 実装内容

**WarehouseMismatchTransferService (F-4)**:
- 仕様書 10.2 F-4 に従う
- `createMismatchTransfer(int $earningId): ?int`
- 処理フロー（仕様書 7.2）:
  1. earning取得 → 配送コースの倉庫取得
  2. 実倉庫ベースで不一致チェック（WarehouseResolver使用）
  3. 同一実倉庫 → null返却
  4. べき等性チェック（request_id = "wh-mismatch-{earning_id}"）
  5. ピッキング明細取得（横持ち出荷済み明細を除外）
  6. stock_transfer_queue に在庫移動伝票キューを作成

**WmsPickingTask (M-3)**:
- 出荷確定（picking_status = SHIPPED）時にトリガーを追加
- `WarehouseMismatchTransferService::createMismatchTransfer()` を呼び出す
- 既存の `booted()` メソッドの status変更リスナー内に追加

### 完了条件

- `WarehouseMismatchTransferService` が存在する
- `WmsPickingTask` の出荷確定トリガーが動作する
- `./vendor/bin/pint` が通る

---

## P5: 得意先配送コース時間切替

### 目的

得意先の配送コースを時間帯で自動切替する機能を新規追加する。

### 修正対象ファイル

| ファイル | 修正ID |
|---------|--------|
| `app/Console/Commands/SwitchDeliveryCourseCommand.php` | F-3 |
| `app/Filament/Resources/WmsBuyerDeliveryCourseSwitchSettings/` | F-5 |
| `routes/console.php` | M-5 |

### 実装内容

**SwitchDeliveryCourseCommand (F-3)**:
- 仕様書 7.3 に従う
- コマンド名: `wms:switch-delivery-course`
- 処理フロー:
  1. 現在の時刻スロットを15分単位に切り捨て
  2. Step1: 原子UPDATE（実行制御） — `last_executed_date != CURDATE()` 条件
  3. Step2: buyer_details を一括更新 — JOIN で `delivery_course_id` を更新
  4. ログ出力

**Filament Resource (F-5)**:
- `WmsBuyerDeliveryCourseSwitchSettingResource.php`
- リスト・作成・編集ページ
- フォーム: buyer_id (Select), switch_time (TimePicker/Select), to_delivery_course_id (Select)
- テーブル: 得意先名, 切替時刻, 切替先配送コース, 最終実行日, 状態
- バリデーション: switch_time は15分単位のみ

**スケジューラー (M-5)**:
- `routes/console.php` に追加:
  ```php
  Schedule::command('wms:switch-delivery-course')
      ->everyFifteenMinutes()
      ->onOneServer()
      ->withoutOverlapping();
  ```

### 完了条件

- コマンドが `php artisan wms:switch-delivery-course` で実行可能
- Filament管理画面で切替設定のCRUDが可能
- スケジューラーに登録されている
- `./vendor/bin/pint` が通る

---

## P6: マイグレーション実行・テスト・検証

### 目的

wms_wave_settings.warehouse_id 削除のマイグレーションを実行し、全体の動作を検証する。

### 実行手順

1. **コード品質チェック**: `./vendor/bin/pint`
2. **マイグレーション実行**: `php artisan migrate`（F-1b: warehouse_id 削除）
3. **テスト実行**: `composer test`
4. **手動確認**:
   - WaveSetting 一覧画面が表示されること
   - WaveSetting 作成・編集で warehouse_id フィールドがないこと
   - 配送コース切替設定の画面が表示されること
5. **テストデータ確認**:
   - `php artisan wms:generate-test-data` が動作すること

### 完了条件

- `./vendor/bin/pint` 成功
- `composer test` 成功
- `php artisan migrate` 成功
- Filament管理画面が正常表示

---

## 制約（厳守）

1. **migrate:fresh / migrate:refresh / migrate:reset 禁止** — 本番データが削除される
2. **wms_ 以外のテーブルへの ALTER TABLE 禁止** — earnings, buyer_details, delivery_courses 等
3. **FK（外部キー）禁止** — 全リレーションはアプリケーションレベル
4. **SELECT→判定→UPDATE 禁止** — 原子的UPDATE方式を使用
5. **request_id によるべき等性必須** — 全在庫移動伝票
6. **仮想倉庫は COALESCE(stock_warehouse_id, id) で実倉庫に解決** — WarehouseResolver 使用
7. **Filament 4 のインポートパスを使用** — `Filament\Schemas\Components\Section` 等

## 全体完了条件

- 全7Phase（P0〜P6）が完了
- `./vendor/bin/pint` 成功
- `composer test` 成功
- `wms_wave_settings.warehouse_id` が削除されている
- `wms_buyer_delivery_course_switch_settings` テーブルが存在する
- `WarehouseResolver`, `WarehouseMismatchTransferService` が存在する
- `SwitchDeliveryCourseCommand` が動作する
- Filament 管理画面が正常に動作する
