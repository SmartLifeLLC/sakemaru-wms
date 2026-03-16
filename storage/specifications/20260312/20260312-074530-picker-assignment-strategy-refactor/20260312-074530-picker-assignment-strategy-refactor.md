# ピッカー割り当て戦略リファクタリング

- **作成日**: 2026-03-12
- **ステータス**: ドラフト
- **ディレクトリ**: `/Users/jungsinyu/Projects/sakemaru-wms`

## 背景・目的

現在の `AssignPickersToTasksService` には以下の問題がある:

1. **倉庫991のハードコード**: 存在しない倉庫IDが条件分岐に埋め込まれている（デッドコード）
2. **戦略パラメータ未使用**: `strategy_id` を受け取るが、実際のロジックに反映していない
3. **均等割り当てがタスク件数ベース**: 商品数（アイテム数）の多寡を無視しており、作業量が偏る
4. **InitSystemSeeder未登録**: `WmsPickingAssignmentStrategySeeder` が `InitSystemSeeder` から呼ばれていない
5. **シーダーが倉庫991固定**: 実際の倉庫に合わせた初期戦略が必要

## 現状の実装

### AssignPickersToTasksService (`app/Services/Picking/AssignPickersToTasksService.php`)

```
execute(warehouseId, pickerIds, strategyId)
  ├── warehouseId === 991 → assignWithFloorPriority() ← ハードコード
  └── それ以外 → assignEqually()
       └── distributeTasksToPickers()
            └── findEligiblePicker() ← タスク件数が最少のピッカーを選択
                 └── canPickerHandleTask() ← 制限エリア＋ピッキングエリアのチェック
```

- `strategyId` は `find()` で取得するだけで、`strategy_key` も `parameters` も使っていない
- 均等割り当ては **タスク件数** ベース（1タスク=1カウント）
- 配送コース別のグルーピングは考慮していない

### PickingStrategyType Enum

| Key | ラベル | 実装状況 |
|-----|--------|----------|
| `EQUAL` | 均等割り当て | タスク件数ベースのみ実装 |
| `SKILL_BASED` | スキルレベル考慮 | 未実装（Enumのみ） |
| `ZONE_PRIORITY` | エリア優先 | 未実装（Enumのみ） |

### WmsPickingTask の関連フィールド

- `delivery_course_id` / `delivery_course_code`: 配送コース
- `floor_id`: フロア
- `wms_picking_area_id`: ピッキングエリア
- `pickingItemResults()`: HasMany → `getItemCountAttribute()` で商品数を取得可能

### WmsPickingAssignmentStrategy モデル

- `strategy_key`: PickingStrategyType Enum
- `parameters`: JSON（戦略固有パラメータ）
- `is_default`: 倉庫ごとに1つ
- `warehouse_id`: 倉庫紐付け

### WmsPickingAssignmentStrategySeeder

- 倉庫991のみに3つの戦略を定義（991は存在しない）
- `InitSystemSeeder` から呼ばれていない

## 変更内容

### 概要

1. 倉庫991のハードコードを削除
2. 戦略パラメータに基づく割り当てロジックを実装（Strategy Pattern）
3. 均等割り当てを「商品数（アイテム数）ベース」に変更
4. **配送コース別の塊を分割しない**（同一配送コースのタスクは同一ピッカーに割り当て）
5. InitSystemSeeder に戦略シーダーを登録
6. 実際の倉庫に合わせたシーダーに更新

### 詳細設計

#### 1. PickingStrategyType Enum の整理

現状の3種を維持。`EQUAL` の意味を「商品数均等」に変更:

| Key | ラベル | 説明 |
|-----|--------|------|
| `EQUAL` | 商品数均等割り当て | 商品数ベースで均等配分。配送コース単位で割り当て |
| `SKILL_BASED` | スキルレベル考慮 | 今回は未実装のまま（将来拡張） |
| `ZONE_PRIORITY` | エリア優先 | 今回は未実装のまま（将来拡張） |

`EQUAL` の `parameters` スキーマ:
```json
{
  "group_by": "delivery_course",
  "balance_metric": "item_count"
}
```

- `group_by`: グルーピング単位。`"delivery_course"` = 配送コース別にまとめる（デフォルト）
- `balance_metric`: 均等化の指標。`"item_count"` = 商品数（デフォルト）、`"task_count"` = タスク件数

#### 2. AssignPickersToTasksService リファクタリング

```
execute(warehouseId, pickerIds, strategyId)
  ├── 戦略を取得 → strategy_key で分岐
  ├── EQUAL → assignByItemCount()
  │    ├── タスクを配送コース別にグルーピング
  │    ├── 各グループの合計商品数を計算
  │    ├── グループを商品数降順でソート（大きいグループから割り当て）
  │    └── 各グループを「現在の累計商品数が最少」のピッカーに割り当て
  │         └── canPickerHandleTask() で適格性チェック（既存ロジック維持）
  ├── SKILL_BASED → 未実装エラー返却
  └── ZONE_PRIORITY → 未実装エラー返却
```

##### 配送コース別グルーピング + 商品数均等割り当てアルゴリズム

```
1. 未割当タスクを取得（withCount('pickingItemResults')で商品数を含む）
2. delivery_course_id でグルーピング
   - delivery_course_id が NULL のタスクは個別タスクとして扱う
3. 各グループの合計商品数を計算
4. グループを合計商品数の降順でソート（First Fit Decreasing）
5. ピッカーごとの累計商品数を初期化（全員0）
6. 各グループについて:
   a. グループ内の任意のタスクを担当できるピッカーを抽出（canPickerHandleTask）
   b. 適格ピッカーの中で累計商品数が最少のピッカーを選択
   c. グループ内の全タスクをそのピッカーに割り当て
   d. ピッカーの累計商品数を加算
7. 担当できるピッカーがいないグループはスキップ（未割当のまま）
```

**ポイント:**
- 配送コースの塊は絶対に分割しない
- 「First Fit Decreasing」方式で、大きいグループから先に割り当てることで偏りを最小化
- `canPickerHandleTask()` はグループ内の**全タスク**でチェック（1つでもNGならそのピッカーは不可）

#### 3. canPickerHandleTask の変更

変更なし。既存の制限エリア＋ピッキングエリアチェックを維持。

#### 4. Seeder の更新

`WmsPickingAssignmentStrategySeeder` を更新:
- 倉庫991固定を削除
- 全アクティブ倉庫に対してデフォルト戦略を生成
- `InitSystemSeeder` に登録

```php
// 全アクティブ倉庫に対してデフォルト EQUAL 戦略を作成
$warehouses = Warehouse::where('is_active', true)->get();

foreach ($warehouses as $warehouse) {
    WmsPickingAssignmentStrategy::updateOrCreate(
        ['warehouse_id' => $warehouse->id, 'is_default' => true],
        [
            'name' => "標準割り当て",
            'description' => '商品数ベースで均等に配分。配送コース単位でまとめて割り当て。',
            'strategy_key' => PickingStrategyType::EQUAL->value,
            'parameters' => [
                'group_by' => 'delivery_course',
                'balance_metric' => 'item_count',
            ],
            'is_active' => true,
        ]
    );
}
```

### 影響範囲

| ファイル | 影響 |
|----------|------|
| `AssignPickersToTasksService` | ロジック全面書き換え |
| `WmsPickingAssignmentStrategySeeder` | 倉庫991固定→全倉庫対応 |
| `InitSystemSeeder` | シーダー呼び出し追加 |
| `PickingStrategyType` Enum | ラベル・説明文の更新 |
| `ListWmsPickingWaitings` | プレビューの商品数表示（任意） |

UI（モーダル）は変更不要。`strategy_id` は既に渡されているため、サービス側の変更のみで動作する。

## 制約

1. **FK制約なし**: アプリレベルでのデータ整合性管理を維持
2. **`migrate:fresh` / `migrate:refresh` 禁止**: 新規マイグレーション不要（DB変更なし）
3. **配送コース分割禁止**: 同一 `delivery_course_id` のタスクは必ず同一ピッカーに割り当てる
4. **既存のピッカー適格性チェックを維持**: `canPickerHandleTask()` のロジックは変更しない
5. **トランザクション安全性**: `sakemaru` コネクションでのトランザクションを維持

## 対象ファイル

### 既存変更

| ファイル | 変更内容 |
|----------|----------|
| `app/Services/Picking/AssignPickersToTasksService.php` | 991ハードコード削除、戦略パターン実装、商品数均等割り当て |
| `app/Enums/PickingStrategyType.php` | ラベル・説明文更新 |
| `database/seeders/WmsPickingAssignmentStrategySeeder.php` | 全倉庫対応に変更 |
| `database/seeders/InitSystemSeeder.php` | `WmsPickingAssignmentStrategySeeder` 呼び出し追加 |

### 参照のみ

| ファイル | 参照理由 |
|----------|----------|
| `app/Models/WmsPickingAssignmentStrategy.php` | 戦略モデル構造確認 |
| `app/Models/WmsPickingTask.php` | タスクモデル・リレーション確認 |
| `app/Models/WmsPicker.php` | ピッカーモデル・エリア関連確認 |
| `app/Models/WmsPickingArea.php` | エリア・フロア関連確認 |
| `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingWaitings.php` | モーダル呼び出し確認 |

## 確認事項

1. **`delivery_course_id` が NULL のタスク**: 個別タスクとして扱う（1タスク=1グループ）でよいか？
=> delivery_course_idがないものはwarehouse_idで一つのタスクでまとめる。
2. **プレビュー表示**: モーダルの割当サマリーに商品数を追加表示するか？（現在はタスク件数のみ）
=> 追加表示する
3. **既存の割り当て済みタスク**: 再割り当て機能は不要か？（現在は未割当のみ対象）
=> 不要。ただし、割り当て済みを解除する機能を追加。解除後計算させる。
4. **SKILL_BASED / ZONE_PRIORITY**: 今回は未実装のまま残すが、選択時にエラーメッセージを返す実装でよいか？
=> ZONE_PRIORITYはなくす。SKILL_BASED は実装する。　SKILLによって商品割り当て件数の調節（均等割り当てー＞非受による割り当て）
