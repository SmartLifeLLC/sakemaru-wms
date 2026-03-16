# フロアプランJSON Export/Import 完全復元対応 作業計画

## 前提

- 仕様書: `20260316-191207-fix-floor-plan-json-export-import.md`
- 変更対象: `app/Filament/Pages/FloorPlanEditor.php` のみ
- picking_areas の code は `(string) $area->id`（L1089-1100）
- Import方針: 既存エリアを全削除 → JSONから完全復元

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | Export拡張 | picking_areas, walkable_areas, navmeta, picking_points をJSONに追加 | エクスポートされたJSONに全データが含まれている |
| P2 | Import — 壁・固定領域 & 新プロパティ復元 | walls/fixedAreas消失原因修正、picking_points/walkable_areas/navmeta復元追加 | Import後に壁・固定領域が表示される |
| P3 | Import — ピッキングエリア復元 | 既存エリア削除→再作成→Location紐付け→設定適用 | Import後にエリアが復元されLocation設定が反映される |
| P4 | 統合テスト | Export→Import往復テスト、後方互換性確認 | 全データの完全復元を確認 |

---

## P1: Export拡張

### 目的

`exportLayout()` に不足データ（picking_areas, walkable_areas, navmeta, picking_points）を追加し、フロアプランの全情報をJSONに含める。

### 修正対象ファイル

- `app/Filament/Pages/FloorPlanEditor.php` — `exportLayout()` メソッド（L893-957）

### 修正内容

`$layout` 配列の構築部分（L928-942付近）に以下を追加:

```php
// 既存の $layout 構築の後、return の前に追加:

// Picking areas
$pickingAreas = WmsPickingArea::where('warehouse_id', $this->selectedWarehouseId)
    ->where('floor_id', $this->selectedFloorId)
    ->orderBy('display_order')
    ->get()
    ->map(fn ($area) => [
        'code' => $area->code,
        'name' => $area->name,
        'color' => $area->color,
        'polygon' => $area->polygon,
        'available_quantity_flags' => $area->available_quantity_flags,
        'temperature_type' => $area->temperature_type,
        'is_restricted_area' => $area->is_restricted_area ?? false,
        'display_order' => $area->display_order ?? 0,
    ])
    ->values()
    ->toArray();

$layout['picking_areas'] = $pickingAreas;

// Picking start/end points
$layout['picking_points'] = [
    'start' => ['x' => $this->pickingStartX, 'y' => $this->pickingStartY],
    'end' => ['x' => $this->pickingEndX, 'y' => $this->pickingEndY],
];

// Walkable areas and navmeta
$layout['walkable_areas'] = $this->walkableAreas;
$layout['navmeta'] = $this->navmeta;
```

`use App\Models\WmsPickingArea;` が未追加なら追加（既存importを確認）。

### 完了条件

- Export JSONに `picking_areas`, `picking_points`, `walkable_areas`, `navmeta` が含まれる
- 既存フィールド（zones, walls, fixed_areas等）に影響なし

---

## P2: Import — 壁・固定領域 & 新プロパティ復元

### 目的

1. 壁（walls）・固定領域（fixed_areas）がImport後に消える問題を調査・修正
2. 新フィールド（picking_points, walkable_areas, navmeta）のImport処理を追加

### 調査手順（壁・固定領域消失）

現在のImport処理を追跡:

```
L998: $this->walls = $layout['walls'] ?? [];
L999: $this->fixedAreas = $layout['fixed_areas'] ?? [];
...
L1040: $this->saveLayout();  ← ここで walls/fixedAreas が DB保存される
...
L1043: $zones = $this->zones->toArray();  ← zones() は computed property で Location を再取得
L1044-1052: dispatch('layout-loaded', ...)  ← walls/fixedAreas を含む
```

**仮説**: `$this->zones` の computed property 呼び出し（L1043）が `$this->walls` や `$this->fixedAreas` をリセットしている可能性は低い。`saveLayout()` が正しく動作しているか確認:

1. `saveLayout()` 内の `WmsWarehouseLayout::updateOrCreate()` で `$this->walls`, `$this->fixedAreas` が空でないことを確認するログを追加してテスト
2. DB保存後に実際のレコードを確認

**もう一つの仮説**: `dispatch('layout-loaded')` 後にフロントエンド側で壁・固定領域が正しく描画されない可能性。Blade/Alpine.js側の `layout-loaded` イベントハンドラを確認する。

### 修正内容

#### 新プロパティの復元追加

`importLayoutData()` のプロパティ設定部分（L984-999の後）に追加:

```php
// Import picking points
if (isset($layout['picking_points'])) {
    $this->pickingStartX = $layout['picking_points']['start']['x'] ?? 0;
    $this->pickingStartY = $layout['picking_points']['start']['y'] ?? 0;
    $this->pickingEndX = $layout['picking_points']['end']['x'] ?? 0;
    $this->pickingEndY = $layout['picking_points']['end']['y'] ?? 0;
}

// Import walkable areas and navmeta
if (isset($layout['walkable_areas'])) {
    $this->walkableAreas = $layout['walkable_areas'];
}
if (isset($layout['navmeta'])) {
    $this->navmeta = $layout['navmeta'];
}
```

これにより `saveLayout()` で全プロパティがDB保存される。

#### 壁・固定領域の消失修正

調査結果に基づいて修正。可能性のある修正:

1. **Livewireプロパティの型問題**: JSONデコード時にオブジェクト/配列の型が変わる場合 → キャストで明示的に配列変換
2. **フロントエンド描画問題**: `layout-loaded` ハンドラで walls/fixedAreas を正しく受け取っていない → Blade側修正（この場合は対象ファイルが増える）

### 修正対象ファイル

- `app/Filament/Pages/FloorPlanEditor.php` — `importLayoutData()`
- （調査結果次第で）`resources/views/filament/pages/floor-plan-editor.blade.php`

### 完了条件

- Import後に壁と固定領域がキャンバスに表示される
- picking_points, walkable_areas, navmeta がDB保存される
- 旧フォーマットJSON（新フィールドなし）でもエラーなくImport可能

---

## P3: Import — ピッキングエリア復元

### 目的

Import時にピッキングエリアを完全復元し、Location紐付けとエリア設定の適用を行う。

### 修正内容

`importLayoutData()` のゾーン保存処理（L1002-1037）の**後**に追加:

```php
// Import picking areas (complete replacement)
if (isset($layout['picking_areas']) && !empty($layout['picking_areas'])) {
    // 1. 既存のピッキングエリアを削除（Import優先で完全上書き）
    $existingAreas = WmsPickingArea::where('warehouse_id', $this->selectedWarehouseId)
        ->where('floor_id', $this->selectedFloorId)
        ->get();

    foreach ($existingAreas as $existingArea) {
        // Location紐付け解除
        Location::where('wms_picking_area_id', $existingArea->id)
            ->update(['wms_picking_area_id' => null]);
        $existingArea->delete();
    }

    // 2. JSONからエリアを再作成
    foreach ($layout['picking_areas'] as $areaData) {
        $area = WmsPickingArea::create([
            'warehouse_id' => $this->selectedWarehouseId,
            'floor_id' => $this->selectedFloorId,
            'code' => uniqid(),  // 一時コード
            'name' => $areaData['name'],
            'color' => $areaData['color'] ?? '#8B5CF6',
            'polygon' => $areaData['polygon'] ?? [],
            'available_quantity_flags' => $areaData['available_quantity_flags'] ?? null,
            'temperature_type' => $areaData['temperature_type'] ?? null,
            'is_restricted_area' => $areaData['is_restricted_area'] ?? false,
            'display_order' => $areaData['display_order'] ?? 0,
            'is_active' => true,
        ]);

        // code を ID に更新（既存パターンに合わせる）
        $area->update(['code' => (string) $area->id]);

        // 3. polygon 内の Location を紐付け
        $this->assignLocationsToArea($area);

        // 4. エリア設定を Location に適用
        $area->applySettingsToLocations();
    }
}

// ピッキングエリアを再読み込み
$this->loadPickingAreas();
```

**ベースロケーション不在時のバリデーション:**

ゾーン（zones）の保存前にバリデーションを追加:

```php
// zones が必須（ベースとなるロケーションがない場合はエラー）
if (isset($layout['picking_areas']) && !empty($layout['picking_areas'])) {
    if (!isset($layout['zones']) || empty($layout['zones'])) {
        throw new \Exception('ピッキングエリアをインポートするにはゾーン（ロケーション）が必要です');
    }
}
```

### 実行順序の確認

```
1. canvas/colors/text_styles 設定     ← 既存
2. walls/fixed_areas 設定             ← 既存
3. picking_points/walkable_areas/navmeta 設定  ← P2で追加
4. saveLayout()                        ← 既存（全プロパティ保存）
5. zones → Location::updateOrCreate()  ← 既存（先にLocationを作成/更新）
6. picking_areas 全削除 → 再作成       ← P3で追加
   → assignLocationsToArea()           ← Location座標に依存するため zones の後
   → applySettingsToLocations()        ← エリア設定をLocationに反映
7. loadPickingAreas()                  ← P3で追加
8. dispatch('layout-loaded')           ← 既存（pickingAreas が最新化済み）
```

### 修正対象ファイル

- `app/Filament/Pages/FloorPlanEditor.php` — `importLayoutData()`

### 完了条件

- Import後にピッキングエリアがキャンバスに表示される
- エリア内のLocationに `wms_picking_area_id` が設定される
- エリアの `available_quantity_flags`, `temperature_type`, `is_restricted_area` がLocationに反映される
- ゾーンなしでピッキングエリアだけImportしようとするとエラーになる

---

## P4: 統合テスト

### 目的

Export → Import の往復テストで全データの完全復元を確認する。

### テスト手順

#### テスト1: 全データ Export → Import

1. `/admin/floor-plan-editor?warehouse=91&floor=26` を開く
2. Export ボタンでJSONダウンロード
3. JSONファイルの内容を確認:
   - `walls` に壁データが含まれているか
   - `fixed_areas` に固定領域データが含まれているか
   - `picking_areas` にエリアデータが含まれているか
   - `picking_points`, `walkable_areas`, `navmeta` が含まれているか
4. 同じフロアでImport
5. 確認:
   - 壁が表示されているか
   - 固定領域が表示されているか
   - ピッキングエリアが表示されているか
   - ゾーン（ロケーション）の座標が正しいか
   - エリア設定（温度帯・数量区分・制限）がLocationに反映されているか

#### テスト2: 後方互換性

1. 旧フォーマットJSON（`picking_areas` フィールドなし）を用意
2. Import実行
3. エラーなくImportが完了すること
4. 既存のピッキングエリアが削除されないこと（`picking_areas` キーが無い場合はスキップ）

#### テスト3: エラーケース

1. ゾーンなし・ピッキングエリアありのJSONをImport → エラーメッセージが表示されること

### 完了条件

- テスト1〜3がすべてパスする
- 壁・固定領域・ピッキングエリア・歩行可能エリアが完全に復元される

---

## 制約（厳守）

1. **FK禁止** — 外部キー制約は使用しない
2. **DB破壊コマンド禁止** — `migrate:fresh`, `migrate:refresh`, `db:wipe` は絶対に実行しない
3. **後方互換性** — 旧フォーマットJSON（新フィールドなし）のImportが従来通り動作すること
4. **Import優先** — ピッキングエリアは既存を全削除してから再作成（完全上書き）
5. **ロケーション必須** — ピッキングエリアのImportにはベースとなるロケーション（zones）が必要。ない場合はエラー

## 全体完了条件

- `exportLayout()` が全データ（zones, walls, fixed_areas, picking_areas, picking_points, walkable_areas, navmeta）をJSONに含める
- `importLayoutData()` が全データを正しく復元する
- 壁・固定領域がImport後にキャンバスに表示される
- ピッキングエリアがImport後に復元され、Location紐付け・設定適用が完了する
- 旧フォーマットJSONのImportでエラーが発生しない
