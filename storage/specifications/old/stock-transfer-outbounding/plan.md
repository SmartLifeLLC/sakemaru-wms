# 横持ち出荷指示 倉庫推薦機能 作業計画

## 前提

- 欠品管理機能（欠品一覧、承認待ち一覧）は既に実装済み
- 横持ち出荷指示モーダル（`proxy-shipment-allocations.blade.php`）で倉庫選択・数量指定が可能
- 現在の在庫リストは全倉庫を名前順でフラットに表示しているのみ
- 得意先別最寄倉庫テーブルは未作成

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | DB マイグレーション＆モデル作成 | 2テーブル（距離・最寄）の作成とEloquentモデル | マイグレーション実行成功、モデルから読み書き可能 |
| P1 | 在庫リストへの最寄倉庫ラベル表示 | 在庫リストで最寄倉庫に「おすすめ」ラベルを表示 | モーダルを開くと最寄倉庫が視覚的に区別される |
| P2 | 同一配送コース上横持ち出荷予定倉庫の表示 | 同wave・同配送コース内の既存横持ち設定倉庫を別セクションで表示 | 同一配送コースの横持ち予定倉庫が表示される |
| P3 | 動作確認・テスト | テストデータでの動作確認 | 全機能が正常動作 |

---

## P0: DB マイグレーション＆モデル作成

### 目的

得意先と倉庫間の距離データを保持するテーブルと、得意先別の最寄倉庫キャッシュテーブルを作成する。

### マイグレーション作成手順

#### テーブル1: `wms_partner_warehouse_distances`

```bash
php artisan make:migration create_wms_partner_warehouse_distances_table
```

カラム定義:
```php
Schema::connection('sakemaru')->create('wms_partner_warehouse_distances', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('partner_id');       // 得意先ID
    $table->unsignedBigInteger('warehouse_id');      // 倉庫ID
    $table->decimal('distance_km', 10, 2);           // 距離（km）
    $table->unsignedBigInteger('creator_id')->nullable();
    $table->unsignedBigInteger('last_updater_id')->nullable();
    $table->timestamps();

    // FK は作成しない
    $table->unique(['partner_id', 'warehouse_id']);
    $table->index('warehouse_id');
});
```

#### テーブル2: `wms_partner_nearest_warehouses`

```bash
php artisan make:migration create_wms_partner_nearest_warehouses_table
```

カラム定義:
```php
Schema::connection('sakemaru')->create('wms_partner_nearest_warehouses', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('partner_id');           // 得意先ID
    $table->unsignedBigInteger('nearest_warehouse_id'); // 最寄倉庫ID
    $table->decimal('min_distance_km', 10, 2);          // 最短距離（km）
    $table->unsignedBigInteger('creator_id')->nullable();
    $table->unsignedBigInteger('last_updater_id')->nullable();
    $table->timestamps();

    // FK は作成しない
    $table->unique('partner_id');
    $table->index('nearest_warehouse_id');
});
```

### モデル作成

#### `app/Models/WmsPartnerWarehouseDistance.php`

```php
class WmsPartnerWarehouseDistance extends WmsModel
{
    protected $table = 'wms_partner_warehouse_distances';

    protected $fillable = [
        'partner_id',
        'warehouse_id',
        'distance_km',
        'creator_id',
        'last_updater_id',
    ];

    protected $casts = [
        'distance_km' => 'decimal:2',
    ];

    public function partner() { return $this->belongsTo(\App\Models\Sakemaru\Partner::class); }
    public function warehouse() { return $this->belongsTo(\App\Models\Sakemaru\Warehouse::class); }
}
```

#### `app/Models/WmsPartnerNearestWarehouse.php`

```php
class WmsPartnerNearestWarehouse extends WmsModel
{
    protected $table = 'wms_partner_nearest_warehouses';

    protected $fillable = [
        'partner_id',
        'nearest_warehouse_id',
        'min_distance_km',
        'creator_id',
        'last_updater_id',
    ];

    protected $casts = [
        'min_distance_km' => 'decimal:2',
    ];

    public function partner() { return $this->belongsTo(\App\Models\Sakemaru\Partner::class); }
    public function nearestWarehouse() { return $this->belongsTo(\App\Models\Sakemaru\Warehouse::class, 'nearest_warehouse_id'); }
}
```

### 完了条件

- `php artisan migrate` が成功する
- テーブルが作成される
- モデルでCRUD操作が可能

---

## P1: 在庫リストへの最寄倉庫ラベル表示

### 目的

横持ち出荷指示モーダルの在庫リストで、得意先にとって最寄の倉庫に「おすすめ」ラベルを表示する。

### 優先順位ロジック

仕様に記載の優先順位:
1. **同一配送コース内で既に横持ち出荷が予定されている店舗** → P2で対応
2. **得意先にとって最寄の倉庫** → このPhaseで対応

### 修正対象ファイル

#### 1. `WmsShortagesTable.php` / `WmsShortagesWaitingApprovalsTable.php`

`viewData` クロージャに最寄倉庫IDを追加:

```php
// 得意先の最寄倉庫を取得
$partnerId = $record->trade->partner_id ?? null;
$nearestWarehouseId = null;
if ($partnerId) {
    $nearest = \App\Models\WmsPartnerNearestWarehouse::where('partner_id', $partnerId)->first();
    $nearestWarehouseId = $nearest?->nearest_warehouse_id;
}

return [
    // ...既存のデータ...
    'nearest_warehouse_id' => $nearestWarehouseId,
];
```

#### 2. `proxy-shipment-allocations.blade.php`

在庫リストのテーブルを修正:

- `nearestWarehouseId` をAlpine.jsデータに追加
- 在庫リストの各行に「おすすめ」バッジを条件付きで表示
- 最寄倉庫を在庫リストの先頭にソート表示

```javascript
// Alpine.js に追加
nearestWarehouseId: {{ $nearest_warehouse_id ?? 'null' }},

// ソート済みの stocks（最寄倉庫を先頭に）
get sortedStocks() {
    return [...this.stocks].sort((a, b) => {
        if (a.warehouse_id == this.nearestWarehouseId) return -1;
        if (b.warehouse_id == this.nearestWarehouseId) return 1;
        return 0;
    });
},
```

在庫リストの行テンプレートに「おすすめ」ラベルを追加:

```html
<td class="...">
    <span x-text="stock.warehouse_name"></span>
    <span
        x-show="stock.warehouse_id == nearestWarehouseId"
        class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200"
    >おすすめ</span>
</td>
```

### 完了条件

- モーダルを開くと、得意先の最寄倉庫に「おすすめ」バッジが表示される
- 最寄倉庫が在庫リストの先頭に表示される
- 最寄データがない場合はバッジなしで通常表示される

---

## P2: 同一配送コース上横持ち出荷予定倉庫の表示

### 目的

同じWave・同じ配送コース内で、既に横持ち出荷が設定されている別の欠品レコードがあれば、その出荷倉庫を別セクションで表示する。同じ倉庫からまとめて出荷できるようにユーザーの意思決定を支援する。

### ロジック

1. 欠品レコードの `wave_id` と `delivery_course_id` を取得
2. 同じ `wave_id` ＋ 同じ配送コースを持つ他の `wms_shortages` を検索
3. それらの `wms_shortage_allocations`（status != CANCELLED）の `target_warehouse_id` を取得
4. 重複除外して倉庫名リストを作成

```php
// 同一配送コース内の横持ち出荷予定倉庫を取得
$sameCourseSameWaveAllocations = [];
if ($record->wave_id && $record->delivery_course_id) {
    $sameCourseSameWaveAllocations = \App\Models\WmsShortageAllocation::query()
        ->whereHas('shortage', function ($q) use ($record) {
            $q->where('wave_id', $record->wave_id)
              ->where('delivery_course_id', $record->delivery_course_id)
              ->where('id', '!=', $record->id); // 自分自身を除外
        })
        ->whereNotIn('status', ['CANCELLED'])
        ->with('targetWarehouse')
        ->get()
        ->groupBy('target_warehouse_id')
        ->map(function ($group) {
            $warehouse = $group->first()->targetWarehouse;
            return [
                'warehouse_id' => $warehouse->id,
                'warehouse_name' => $warehouse->name,
                'allocation_count' => $group->count(),
                'total_qty' => $group->sum('assign_qty'),
            ];
        })
        ->values()
        ->toArray();
}
```

### 修正対象ファイル

#### 1. `WmsShortagesTable.php` / `WmsShortagesWaitingApprovalsTable.php`

`viewData` に `same_course_allocations` を追加。

#### 2. `proxy-shipment-allocations.blade.php`

在庫リストの上に新セクションを追加:

```html
<!-- 同一配送コース上横持ち出荷予定倉庫 -->
<div x-show="sameCourseAllocations.length > 0" class="mb-4 ...">
    <div class="font-bold text-sm text-amber-700 dark:text-amber-300 mb-2">
        同一配送コース上横持ち出荷予定倉庫
    </div>
    <table class="w-full text-sm ...">
        <thead>
            <tr>
                <th>倉庫名</th>
                <th>横持ち出荷件数</th>
                <th>合計数量</th>
            </tr>
        </thead>
        <tbody>
            <template x-for="alloc in sameCourseAllocations" :key="alloc.warehouse_id">
                <tr @click="addAllocation(alloc.warehouse_id, remainingQty)" class="cursor-pointer hover:bg-amber-50 ...">
                    <td x-text="alloc.warehouse_name"></td>
                    <td x-text="alloc.allocation_count + '件'"></td>
                    <td x-text="alloc.total_qty"></td>
                </tr>
            </template>
        </tbody>
    </table>
</div>
```

### 完了条件

- 同一Wave・同一配送コースに既存の横持ち出荷指示がある場合、セクションが表示される
- 倉庫をクリックすると在庫リストと同様に横持ち出荷指示に追加される
- 横持ち予定がない場合はセクションが非表示

---

## P3: 動作確認・テスト

### 確認事項

1. **P0確認**: マイグレーション成功、テーブル存在確認
2. **P1確認**:
   - `wms_partner_nearest_warehouses` にデータがある得意先の欠品でモーダルを開く
   - 最寄倉庫に「おすすめ」バッジが表示される
   - 最寄倉庫が先頭にソートされる
   - データがない場合は通常表示
3. **P2確認**:
   - 同一Wave・同一配送コースで複数欠品がある状態を作成
   - 1つ目の欠品で横持ち出荷を設定
   - 2つ目の欠品のモーダルを開く
   - 「同一配送コース上横持ち出荷予定倉庫」セクションに先ほどの倉庫が表示される
   - 横持ち予定がない場合はセクション非表示
4. **既存機能の回帰確認**:
   - 横持ち出荷指示の作成・更新・削除が正常動作
   - 承認フローが正常動作
   - 在庫リストのクリックで横持ち追加が動作

### 完了条件

- 全確認項目がOK
- 既存機能に影響なし

---

## 制約（厳守）

1. **FK禁止**: マイグレーションにForeignKey制約を作成しない
2. **DB破壊禁止**: `migrate:fresh`, `migrate:refresh` 等は絶対に実行しない
3. **sakemaruコネクション**: Schema操作は `Schema::connection('sakemaru')` を使用
4. **WmsModel継承**: 新規モデルは `WmsModel` を継承
5. **既存ロジック変更禁止**: ProxyShipmentServiceの作成・更新・削除ロジックは変更しない
6. **Filament 4パターン**: 正しいインポートパスを使用

## 全体完了条件

- 2テーブル（距離・最寄）が作成され、モデルで操作可能
- 欠品対応モーダルの在庫リストで最寄倉庫に「おすすめ」ラベルが表示される
- 同一配送コース上の横持ち出荷予定倉庫が別セクションで表示される
- 既存の欠品対応フロー（作成・更新・削除・承認）に影響なし
