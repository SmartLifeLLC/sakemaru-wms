# 配送コース変更機能 仕様書

## 概要

ピッキング開始前の伝票について、配送コースを変更できる機能を提供します。
ピッキングリスト画面（`/admin/wms-picking-item-edit`）での変更は廃止し、専用の配送コース変更画面を新設します。

## 背景

- 従来：ピッキングリストで配送コース変更を実施
- 問題点：ピッキングアイテム単位での変更はロジックが複雑
- 解決策：伝票単位で配送コースを変更する専用画面を作成

## 機能配置

**メニュー階層：**
```
出荷管理
 └─ 出荷ダッシュボード
     └─ 配送コース変更 ← 新規追加
```

## 対象データ

### 対象条件
- `wms_picking_tasks.status = 'PENDING'` （ピッキング未開始）
- 上記タスクに紐づく `wms_picking_item_results` の全 `trades`

### 対象外
- `status != 'PENDING'` のタスク（ピッキング開始済み、完了済み等）

## 画面仕様

### 一覧表示

**表示項目：**

| 列名 | データソース | 説明 |
|-----|------------|------|
| 伝票番号 | `trades.serial_id` | 売上伝票の管理番号（1伝票 = 1レコード） |
| 得意先コード | `trades` → `partners.code` | 取引先コード |
| 得意先名 | `trades` → `partners.name` | 取引先名称 |
| 現在の配送コースコード | `delivery_courses.code` | 現在割り当てられているコース |
| 現在の配送コース名 | `delivery_courses.name` | コース名称 |
| 変更先配送コース | （選択可能なドロップダウン） | 変更先のコース選択UI |

**取得クエリ例：**
```sql
SELECT DISTINCT
    t.id as trade_id,
    t.serial_id as trade_serial_id,
    p.code as partner_code,
    p.name as partner_name,
    dc.id as current_course_id,
    dc.code as current_course_code,
    dc.name as current_course_name,
    pt.warehouse_id
FROM wms_picking_tasks pt
INNER JOIN wms_picking_item_results pir ON pt.id = pir.picking_task_id
INNER JOIN trades t ON pir.trade_id = t.id
INNER JOIN partners p ON t.partner_id = p.id
INNER JOIN delivery_courses dc ON pt.delivery_course_id = dc.id
WHERE pt.status = 'PENDING'
ORDER BY t.serial_id
```

### 操作

1. **配送コース選択：** 各行のドロップダウンで変更先の配送コースを選択
2. **一括変更：** 選択した伝票の配送コースを一括で変更
3. **変更確認：** 変更前に確認ダイアログを表示

## 配送コース変更ロジック

### 処理フロー

```
1. 対象伝票の全wms_picking_item_resultsを取得
   ↓
2. 各picking_item_resultについて、移動先のwms_picking_taskを決定
   ↓
3. 移動先のタスクに応じてwms_picking_item_resultsを更新/移動
   ↓
4. 元のwms_picking_taskにアイテムが残っていない場合は削除
```

### 詳細ロジック

#### 1. 対象アイテムの取得

```php
// 対象伝票に紐づく全picking_item_results
$itemResults = DB::connection('sakemaru')
    ->table('wms_picking_item_results')
    ->where('trade_id', $tradeId)
    ->get();
```

#### 2. 移動先wms_picking_taskの決定

**グループ化条件（同一タスクに統合される条件）：**
- `warehouse_id`：倉庫
- `delivery_course_id`：配送コース（変更先）
- `wms_picking_area_id`：ピッキングエリア ← **追加**
- `floor_id`：フロア
- `temperature_type`：温度帯
- `is_restricted_area`：制限エリアフラグ

**タスク検索ロジック：**

```php
foreach ($itemResults as $itemResult) {
    // アイテムのlocation情報を取得
    $location = DB::connection('sakemaru')
        ->table('locations')
        ->where('id', $itemResult->location_id)
        ->first();

    // wms_locations経由でpicking_area_idを取得
    $wmsLocation = DB::connection('sakemaru')
        ->table('wms_locations')
        ->where('location_id', $itemResult->location_id)
        ->first();

    // 同じ条件のwms_picking_taskを検索
    $targetTask = DB::connection('sakemaru')
        ->table('wms_picking_tasks')
        ->where('warehouse_id', $warehouseId)
        ->where('delivery_course_id', $newCourseId)
        ->where('wms_picking_area_id', $wmsLocation->wms_picking_area_id)
        ->where('floor_id', $location->floor_id)
        ->where('temperature_type', $location->temperature_type)
        ->where('is_restricted_area', $location->is_restricted_area)
        ->where('status', 'PENDING')
        ->first();

    if (!$targetTask) {
        // 移動先タスクが存在しない場合は新規作成
        $targetTask = $this->createNewPickingTask(...);
    }

    // picking_item_resultを移動先タスクに紐づけ
    DB::connection('sakemaru')
        ->table('wms_picking_item_results')
        ->where('id', $itemResult->id)
        ->update(['picking_task_id' => $targetTask->id]);
}
```

#### 3. 新規wms_picking_task作成

**必要な情報：**
- `wave_id`：Wave ID（後述のWave生成ロジック参照）
- `wms_picking_area_id`：ピッキングエリア（`wms_locations`から取得）
- `warehouse_id`：倉庫ID
- `warehouse_code`：倉庫コード
- `floor_id`：フロアID
- `temperature_type`：温度帯
- `is_restricted_area`：制限エリアフラグ
- `delivery_course_id`：配送コースID（変更先）
- `delivery_course_code`：配送コースコード
- `shipment_date`：出荷日
- `status`：'PENDING'
- `task_type`：'WAVE'

```php
$pickingTaskId = DB::connection('sakemaru')
    ->table('wms_picking_tasks')
    ->insertGetId([
        'wave_id' => $waveId,
        'wms_picking_area_id' => $pickingAreaId,
        'warehouse_id' => $warehouseId,
        'warehouse_code' => $warehouseCode,
        'floor_id' => $floorId,
        'temperature_type' => $temperatureType,
        'is_restricted_area' => $isRestrictedArea,
        'delivery_course_id' => $newCourseId,
        'delivery_course_code' => $newCourseCode,
        'shipment_date' => $shipmentDate,
        'status' => 'PENDING',
        'task_type' => 'WAVE',
        'picker_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
```

#### 4. Wave生成ロジック

**Wave決定フロー：**

```
1. 変更先配送コース・倉庫・出荷日で既存Waveを検索
   ↓
2-A. Wave存在 → そのWaveを使用
   ↓
2-B. Wave不存在 → wave_settingを検索
   ↓
3-A. wave_setting存在（現在時刻 >= picking_start_time）
      → 新規Wave生成（wave_settingを使用）
   ↓
3-B. wave_setting不存在 or 時刻が未到達
      → 臨時Wave生成（picking_start_time = null）
```

**実装例：**

```php
// 1. 既存Waveを検索
$existingWave = Wave::whereHas('waveSetting', function ($query) use ($warehouseId, $newCourseId) {
        $query->where('warehouse_id', $warehouseId)
              ->where('delivery_course_id', $newCourseId);
    })
    ->where('shipping_date', $shipmentDate)
    ->first();

if ($existingWave) {
    return $existingWave->id;
}

// 2. wave_settingを検索（現在時刻以前の開始時刻）
$currentTime = now()->format('H:i:s');
$waveSetting = WaveSetting::where('warehouse_id', $warehouseId)
    ->where('delivery_course_id', $newCourseId)
    ->whereTime('picking_start_time', '<=', $currentTime)
    ->orderBy('picking_start_time', 'desc')
    ->first();

if ($waveSetting) {
    // 3-A. 通常のWave生成
    $wave = Wave::create([
        'wms_wave_setting_id' => $waveSetting->id,
        'wave_no' => uniqid('TEMP_'),
        'shipping_date' => $shipmentDate,
        'status' => 'PENDING',
    ]);

    // wave_noを更新
    $waveNo = Wave::generateWaveNo(
        $warehouseCode,
        $newCourseCode,
        $shipmentDate,
        $wave->id
    );
    $wave->update(['wave_no' => $waveNo]);

    return $wave->id;
}

// 3-B. 臨時Wave生成（wave_setting不在）
$temporaryWaveSetting = WaveSetting::create([
    'warehouse_id' => $warehouseId,
    'delivery_course_id' => $newCourseId,
    'picking_start_time' => null, // 臨時Wave
    'picking_deadline_time' => null,
    'creator_id' => auth()->id(),
    'last_updater_id' => auth()->id(),
]);

$wave = Wave::create([
    'wms_wave_setting_id' => $temporaryWaveSetting->id,
    'wave_no' => uniqid('TEMP_'),
    'shipping_date' => $shipmentDate,
    'status' => 'PENDING',
]);

$waveNo = Wave::generateWaveNo(
    $warehouseCode,
    $newCourseCode,
    $shipmentDate,
    $wave->id
);
$wave->update(['wave_no' => $waveNo]);

return $wave->id;
```

#### 5. 元タスクのクリーンアップ

```php
// 元のpicking_taskにアイテムが残っていない場合は削除
$remainingItems = DB::connection('sakemaru')
    ->table('wms_picking_item_results')
    ->where('picking_task_id', $oldTaskId)
    ->count();

if ($remainingItems === 0) {
    DB::connection('sakemaru')
        ->table('wms_picking_tasks')
        ->where('id', $oldTaskId)
        ->delete();
}
```

## データ整合性

### トランザクション管理

全ての配送コース変更操作は、トランザクション内で実行します。

```php
DB::transaction(function () use ($tradeId, $newCourseId) {
    // 1. アイテム取得
    // 2. 移動先タスク決定/作成
    // 3. アイテム移動
    // 4. 元タスククリーンアップ
});
```

### 制約事項

- **ピッキング開始済みの伝票は変更不可**（status != 'PENDING'）
- **配送コース変更時、在庫引当（wms_reservations）は変更しない**
  - 在庫自体は変わらないため、予約情報はそのまま保持
- **wms_picking_item_results.trade_idは維持**
  - 伝票との紐付けは保持

## エラーハンドリング

### エラーケース

1. **Wave生成失敗**
   - 臨時Waveで対応

2. **picking_task作成失敗**
   - トランザクションロールバック、エラーメッセージ表示

3. **配送コース不正**
   - バリデーションエラー

4. **権限不足**
   - アクセス拒否

## UI/UX

### Filament実装方針

- **リソース：** `DeliveryCourseChangeResource`
- **ページ：** List page with custom actions
- **アクション：**
  - BulkAction：選択した伝票の配送コースを一括変更
  - TableAction：行ごとの配送コース変更

### バリデーション

- 変更先配送コースは必須
- 変更先配送コースは倉庫に紐づく有効なコースであること
- 対象伝票はstatus = 'PENDING'であること

## テスト項目

### 単体テスト

1. Wave検索・生成ロジック
2. picking_task検索・作成ロジック
3. picking_item_results移動ロジック
4. 元タスククリーンアップロジック

### 統合テスト

1. 配送コース変更エンドツーエンドテスト
2. トランザクションロールバックテスト
3. 複数伝票の一括変更テスト

### 動作確認

1. 既存Waveへの移動
2. 新規Wave生成が必要な場合の移動
3. 臨時Wave生成が必要な場合の移動
4. 複数フロア・温度帯にまたがる伝票の変更

---

## 実装計画

### フェーズ1：基盤準備

1. **サービスクラス作成**
   - `App\Services\DeliveryCourseChangeService`
     - 配送コース変更のビジネスロジックを集約
   - `App\Services\WaveService`
     - Wave検索・生成ロジックを分離（既存コードから抽出）

2. **DTOクラス作成**（オプション）
   - `App\DataTransferObjects\CourseChangeRequest`

### フェーズ2：コアロジック実装

3. **Wave管理ロジック**
   - 既存Wave検索メソッド
   - 新規Wave生成メソッド（wave_settingベース）
   - 臨時Wave生成メソッド

4. **picking_task管理ロジック**
   - グループ化条件での検索メソッド
   - 新規picking_task作成メソッド

5. **配送コース変更メインロジック**
   - アイテム取得
   - 移動先決定
   - アイテム移動
   - クリーンアップ

### フェーズ3：UI実装

6. **Filamentリソース作成**
   - `DeliveryCourseChangeResource`
   - リスト表示実装
   - 配送コース選択UI

7. **アクション実装**
   - 行単位の配送コース変更アクション
   - 一括変更アクション

### フェーズ4：テスト・検証

8. **テスト作成**
   - ユニットテスト
   - フィーチャーテスト

9. **動作確認**
   - 各種シナリオでの動作確認

### フェーズ5：既存機能の削除

10. **ピッキングリスト画面からの配送コース変更機能削除**
    - `/admin/wms-picking-item-edit` の関連コード削除

---

## 見積もり（工数）

| タスク | 見積もり |
|-------|---------|
| サービスクラス設計・実装 | 4h |
| Wave管理ロジック実装 | 3h |
| picking_task管理ロジック実装 | 3h |
| 配送コース変更メインロジック | 4h |
| Filamentリソース・UI実装 | 4h |
| テスト作成 | 3h |
| 動作確認・調整 | 2h |
| 既存機能削除 | 1h |
| **合計** | **24h（約3日）** |