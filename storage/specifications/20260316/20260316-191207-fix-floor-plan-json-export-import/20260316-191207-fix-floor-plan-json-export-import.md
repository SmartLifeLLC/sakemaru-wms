# フロアプランJSON Export/Import 完全復元対応

- **作成日**: 2026-03-16
- **ステータス**: ドラフト
- **ディレクトリ**: `app/Filament/Pages/FloorPlanEditor.php`
- **対象画面**: `/admin/floor-plan-editor?warehouse=91&floor=26`

## 背景・目的

フロアプランエディタのJSON Export → Import時に以下の問題が発生している:

1. **壁（walls）と固定領域（fixed_areas）が消える** — Exportには含まれているが、Import後に `saveLayout()` を呼んだ後のデータ再読み込み（`loadLayout`）で復元されない可能性、またはImport後の `dispatch('layout-loaded')` 時に正しく渡されていない
2. **ピッキングエリア（picking areas）が復元されない** — Exportに `wms_picking_areas` のデータが含まれていない。Import時もエリアの再作成処理がない
3. **エリア設定がLocationに反映されない** — エリアがImportで復元されても `assignLocationsToArea()` + `applySettingsToLocations()` が呼ばれない
4. **歩行可能エリア・ナビメタ・ピッキング開始/終了地点が未Export** — `walkable_areas`, `navmeta`, `picking_start/end` がJSONに含まれない

## 現状の実装

### Export (`exportLayout()` L893-957)

現在エクスポートされるデータ:
```json
{
  "warehouse_code", "warehouse_name", "floor_code", "floor_name",
  "canvas": { "width", "height" },
  "colors": { "location", "wall", "fixed_area" },
  "text_styles": { "location", "wall", "fixed_area" },
  "zones": [{ "code1", "code2", "name", "x1_pos", "y1_pos", "x2_pos", "y2_pos",
              "available_quantity_flags", "temperature_type", "is_restricted_area", "shelf_count" }],
  "walls": [...],
  "fixed_areas": [...],
  "exported_at"
}
```

**エクスポートされていないデータ:**

| データ | 格納先 | 重要度 |
|--------|--------|--------|
| ピッキングエリア | `wms_picking_areas` テーブル | **高** — エリア設定がLocationに影響 |
| 歩行可能エリア | `wms_warehouse_layouts.walkable_areas` | 中 — 経路探索に使用 |
| ナビメタ | `wms_warehouse_layouts.navmeta` | 中 — 経路探索の設定 |
| ピッキング開始/終了地点 | `wms_warehouse_layouts` | 中 — 経路最適化に使用 |

### Import (`importLayoutData()` L962-1067)

現在のImport処理:
1. canvas, colors, text_styles → Livewireプロパティに反映 ✅
2. walls, fixed_areas → Livewireプロパティに反映 ✅
3. zones → `Location::updateOrCreate()` でDB更新 ✅
4. `saveLayout()` → `wms_warehouse_layouts` に保存 ✅
5. `dispatch('layout-loaded')` → フロントエンドに通知 ✅

**問題点:**
- `saveLayout()` は walls/fixed_areas を `$this->walls`, `$this->fixedAreas` から保存するが、Import直後のプロパティが正しく設定されているか要確認
- ピッキングエリアの復元処理が完全に欠如
- Import後に `loadPickingAreas()` を呼んでいないため、`$this->pickingAreas` が更新されない（ただし `dispatch('layout-loaded')` で既存の `$this->pickingAreas` は渡されている）

### データ保存先

| データ | テーブル | カラム/形式 |
|--------|---------|------------|
| 壁・固定領域 | `wms_warehouse_layouts` | `walls` JSON, `fixed_areas` JSON |
| ゾーン座標 | `locations` | `x1_pos`, `y1_pos`, `x2_pos`, `y2_pos` |
| ピッキングエリア | `wms_picking_areas` | 独立テーブル（polygon, color, settings） |
| エリア↔Location紐付け | `locations` | `wms_picking_area_id` |
| 歩行可能エリア | `wms_warehouse_layouts` | `walkable_areas` JSON |
| ナビメタ | `wms_warehouse_layouts` | `navmeta` JSON |
| 開始/終了地点 | `wms_warehouse_layouts` | `picking_start_x/y`, `picking_end_x/y` |

## 変更内容

### 概要

`exportLayout()` を拡張して全データをエクスポートし、`importLayoutData()` を拡張してピッキングエリアを含む全データを正しく復元する。

### 詳細設計

#### 1. Export拡張 (`exportLayout()`)

JSONに以下を追加:

```json
{
  // ...既存フィールド...
  "picking_areas": [
    {
      "code": "AREA-001",
      "name": "常温エリア",
      "color": "#8B5CF6",
      "polygon": [{"x": 100, "y": 100}, {"x": 500, "y": 100}, ...],
      "available_quantity_flags": 3,
      "temperature_type": "NORMAL",
      "is_restricted_area": false,
      "display_order": 1
    }
  ],
  "picking_points": {
    "start": { "x": 50, "y": 50 },
    "end": { "x": 1900, "y": 1400 }
  },
  "walkable_areas": [...],
  "navmeta": { "cell_size": ..., "erosion_distance": ..., ... }
}
```

**実装:**

```php
// exportLayout() に追加
$pickingAreas = WmsPickingArea::where('warehouse_id', $this->selectedWarehouseId)
    ->where('floor_id', $this->selectedFloorId)
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
$layout['picking_points'] = [
    'start' => ['x' => $this->pickingStartX, 'y' => $this->pickingStartY],
    'end' => ['x' => $this->pickingEndX, 'y' => $this->pickingEndY],
];
$layout['walkable_areas'] = $this->walkableAreas;
$layout['navmeta'] = $this->navmeta;
```

#### 2. Import拡張 (`importLayoutData()`)

**壁・固定領域の復元確認:**
- 現在のコード L998-999 で `$this->walls` と `$this->fixedAreas` は設定済み
- L1040 の `saveLayout()` で DB に保存される
- 問題がある場合は `saveLayout()` 内で `$this->walls` が空になっていないか確認

**ピッキングエリアの復元:**

```php
// Import picking areas
if (isset($layout['picking_areas']) && !empty($layout['picking_areas'])) {
    foreach ($layout['picking_areas'] as $areaData) {
        $area = WmsPickingArea::updateOrCreate(
            [
                'warehouse_id' => $this->selectedWarehouseId,
                'floor_id' => $this->selectedFloorId,
                'code' => $areaData['code'],
            ],
            [
                'name' => $areaData['name'],
                'color' => $areaData['color'] ?? '#8B5CF6',
                'polygon' => $areaData['polygon'] ?? [],
                'available_quantity_flags' => $areaData['available_quantity_flags'] ?? null,
                'temperature_type' => $areaData['temperature_type'] ?? null,
                'is_restricted_area' => $areaData['is_restricted_area'] ?? false,
                'display_order' => $areaData['display_order'] ?? 0,
                'is_active' => true,
            ]
        );

        // Assign locations inside polygon and apply area settings
        $this->reassignLocationsToArea($area);
        $area->applySettingsToLocations();
    }
}
```

**歩行可能エリア・ナビメタ・ピッキング地点の復元:**

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

**ピッキングエリアの再読み込み（Import末尾）:**

```php
// saveLayout() の後に追加
$this->loadPickingAreas();
```

#### 3. Import処理の実行順序

```
1. canvas/colors/text_styles のプロパティ設定
2. walls/fixed_areas のプロパティ設定
3. picking_points/walkable_areas/navmeta のプロパティ設定
4. saveLayout() → wms_warehouse_layouts に全データ保存
5. zones → Location::updateOrCreate() でDB保存
6. picking_areas → WmsPickingArea::updateOrCreate() でDB保存
   → reassignLocationsToArea() でLocation紐付け
   → applySettingsToLocations() でエリア設定をLocationに反映
7. loadPickingAreas() → $this->pickingAreas を最新化
8. dispatch('layout-loaded') → フロントエンドに全データ送信
```

**重要:** ゾーン（Location）の保存をピッキングエリアの復元より先に実行する必要がある。エリアのLocation割り当て（`reassignLocationsToArea`）はLocationの座標に依存するため。

### 影響範囲

| 機能 | 影響 |
|------|------|
| フロアプランエディタ Export | JSON形式に新フィールド追加（後方互換性あり） |
| フロアプランエディタ Import | 新フィールドがない旧JSONも引き続き読み込み可能 |
| ピッキングエリア管理 | Import時にエリアが再作成される |
| ロケーション設定 | Import時にエリア設定が適用される |
| 経路探索 | walkable_areas/navmeta が復元される |

## 制約

- FK禁止（既存制約通り）
- `migrate:fresh` / `migrate:refresh` 禁止
- 旧フォーマットJSON（`picking_areas` フィールドなし）のImportは従来通り動作すること（後方互換性）
- `wms_picking_areas.code` をユニークキーとして使用（`warehouse_id` + `floor_id` + `code` の組み合わせ）

## 対象ファイル

### 既存変更
- `app/Filament/Pages/FloorPlanEditor.php` — `exportLayout()` と `importLayoutData()` の拡張

### 参照のみ
- `app/Models/WmsPickingArea.php` — エリアモデル（変更なし）
- `app/Models/WmsWarehouseLayout.php` — レイアウトモデル（変更なし）
- `app/Models/Sakemaru/Location.php` — ロケーションモデル（変更なし）
- `resources/views/filament/pages/floor-plan-editor.blade.php` — フロントエンド（変更なし）

## 確認事項

1. **壁・固定領域が消える原因の特定**: Import後の `saveLayout()` 呼び出し時に `$this->walls` / `$this->fixedAreas` が正しく設定されているか、実際にデバッグして確認が必要。コード上は L998-999 で設定してから L1040 で保存しているので問題ないはずだが、Livewireのプロパティ同期タイミングの問題の可能性もある
可能な限り自動テスト。ただし、DBのrefresh / freshは禁止
2. **`wms_picking_areas.code` の生成ルール**: 現在のコードでピッキングエリア作成時に `code` がどう生成されるか確認が必要。Import時のマッチングキーとして使用するため
実際に生成してみてテストと確認
3. **既存ピッキングエリアとの競合**: Import先に既にピッキングエリアが存在する場合、`updateOrCreate` で更新するが、Import JSONに含まれないエリアは残存する。これを削除すべきか
削除. import を優先する。全く同じ形をつくりたい。ベースとなるロケーションがない場合はエラーにする。
