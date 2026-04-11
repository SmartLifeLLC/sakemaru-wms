# ピッカー割り当て戦略リファクタリング 作業計画

## 前提

- `AssignPickersToTasksService` に倉庫991のハードコードが存在（デッドコード）
- `strategy_id` を受け取るが未使用
- 均等割り当てがタスク件数ベース（商品数無視）
- 配送コース別のグルーピングなし
- `ZONE_PRIORITY` 戦略は不要（削除）
- `SKILL_BASED` 戦略を実装する（スキルレベルで割り当て比率調整）
- 割り当て済みタスクの解除機能が必要

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | Enum整理・戦略パラメータスキーマ定義 | ZONE_PRIORITY削除、ラベル更新、パラメータスキーマ定義 | Enumが正しく動作、既存DB戦略との整合性確認 |
| P2 | AssignPickersToTasksService リファクタリング | 991削除、EQUAL(商品数均等+配送コースグループ)、SKILL_BASED実装 | 戦略に基づく割り当てが正しく動作 |
| P3 | 割り当て解除機能 | 割り当て済みタスクのpicker_id解除アクション | 一括解除が動作、statusがPENDINGに戻る |
| P4 | モーダルUI更新 | プレビューに商品数表示、解除ボタン追加 | モーダルで商品数確認可能、解除→再割り当てフロー動作 |
| P5 | Seeder更新 | 全倉庫対応、InitSystemSeeder登録 | `php artisan db:seed --class=InitSystemSeeder` で戦略が生成される |
| P6 | 動作確認 | 全体通しテスト | 割り当て→解除→再割り当てのフロー完了 |

---

## P1: Enum整理・戦略パラメータスキーマ定義

### 目的

- `ZONE_PRIORITY` を削除
- `EQUAL` / `SKILL_BASED` のラベル・説明を更新
- 各戦略の `parameters` JSONスキーマを定義

### 修正対象ファイル

- `app/Enums/PickingStrategyType.php`

### 修正内容

#### PickingStrategyType Enum

```php
enum PickingStrategyType: string implements HasColor, HasLabel
{
    case EQUAL = 'equal';
    case SKILL_BASED = 'skill_based';
    // ZONE_PRIORITY を削除

    public function getLabel(): ?string
    {
        return match ($this) {
            self::EQUAL => '商品数均等割り当て',
            self::SKILL_BASED => 'スキルレベル考慮割り当て',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::EQUAL => 'info',
            self::SKILL_BASED => 'warning',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::EQUAL => '商品数ベースで均等に配分します。配送コース単位でまとめて割り当てます。',
            self::SKILL_BASED => 'スキルレベルに応じて商品数の割り当て比率を調整します。',
        };
    }
}
```

#### 各戦略の parameters スキーマ

**EQUAL:**
```json
{
  "group_by": "delivery_course"
}
```
- `group_by`: `"delivery_course"` (デフォルト・現在唯一の選択肢)
- delivery_course_id が NULL のタスクは warehouse_id で1グループにまとめる

**SKILL_BASED:**
```json
{
  "group_by": "delivery_course",
  "skill_rates": {
    "1": 0.5,
    "2": 0.8,
    "3": 1.0,
    "4": 1.2,
    "5": 1.5
  }
}
```
- `group_by`: EQUAL と同じ
- `skill_rates`: PickerSkillLevel値 → 割り当て比率。SENIOR(3)=1.0 が基準
  - TRAINEE(1): 0.5倍（半分の商品数）
  - JUNIOR(2): 0.8倍
  - SENIOR(3): 1.0倍（基準）
  - EXPERT(4): 1.2倍
  - MASTER(5): 1.5倍
- parameters で倍率をカスタマイズ可能（省略時はデフォルト値を使用）

### 注意事項

- DBに既存の `ZONE_PRIORITY` レコードがある場合がある → Seeder（P5）で削除/更新
- Filament の WmsPickingAssignmentStrategyResource で `strategy_key` を使っている箇所の確認

### 完了条件

- `ZONE_PRIORITY` が Enum から削除されていること
- `EQUAL` / `SKILL_BASED` のラベル・説明が更新されていること
- Filament リソースの strategy_key 表示が正常であること

---

## P2: AssignPickersToTasksService リファクタリング

### 目的

- 倉庫991のハードコードを削除
- `strategy_key` に基づくロジック分岐を実装
- EQUAL: 商品数均等 + 配送コースグルーピング
- SKILL_BASED: スキルレベル比率での商品数割り当て

### 修正対象ファイル

- `app/Services/Picking/AssignPickersToTasksService.php`

### 修正方針

#### 全体構造

```
execute(warehouseId, pickerIds, strategyId)
  ├── 戦略を取得 → strategy_key で分岐
  ├── 共通: タスク取得 + 配送コースグルーピング + 商品数カウント
  ├── EQUAL → assignByItemCountEqual()
  └── SKILL_BASED → assignByItemCountSkillBased()
```

#### 共通処理: 配送コースグルーピング

```php
// 1. 未割当タスクを取得（商品数をカウント）
$unassignedTasks = WmsPickingTask::where('warehouse_id', $warehouseId)
    ->whereNull('picker_id')
    ->whereIn('status', [STATUS_PENDING, STATUS_PICKING_READY])
    ->withCount('pickingItemResults as item_count')
    ->with('pickingArea')
    ->get();

// 2. 配送コースでグルーピング
// delivery_course_id が NULL → 'warehouse_{warehouseId}' キーで1グループ
$groups = $unassignedTasks->groupBy(function ($task) use ($warehouseId) {
    return $task->delivery_course_id ?? "warehouse_{$warehouseId}";
});

// 3. 各グループの合計商品数を計算
$groupSummaries = $groups->map(fn ($tasks, $key) => [
    'key' => $key,
    'tasks' => $tasks,
    'total_items' => $tasks->sum('item_count'),
]);

// 4. 合計商品数の降順でソート（First Fit Decreasing）
$sortedGroups = $groupSummaries->sortByDesc('total_items')->values();
```

#### EQUAL 戦略: 商品数均等割り当て

```
1. ピッカーごとの累計商品数を初期化（全員0）
2. グループを商品数降順で処理（First Fit Decreasing）
3. 各グループについて:
   a. グループ内の全タスクを担当できるピッカーを抽出（canPickerHandleTask）
   b. 適格ピッカーの中で累計商品数が最少のピッカーを選択
   c. グループ内の全タスクをそのピッカーに割り当て
   d. ピッカーの累計商品数にグループの合計商品数を加算
```

#### SKILL_BASED 戦略: スキルレベル比率割り当て

```
1. 各ピッカーのスキルレート取得（parameters.skill_rates またはデフォルト）
2. ピッカーごとの「重み付き累計商品数」を初期化
   - 重み付き累計 = 実際の累計商品数 / skill_rate
   - 例: TRAINEE(rate=0.5) に10件割り当て → 重み付き = 10/0.5 = 20
   - 例: MASTER(rate=1.5) に10件割り当て → 重み付き = 10/1.5 = 6.67
3. グループを商品数降順で処理
4. 各グループについて:
   a. 適格ピッカーを抽出
   b. 「重み付き累計商品数」が最少のピッカーを選択
   c. グループ内の全タスクをそのピッカーに割り当て
   d. ピッカーの実際の累計商品数を加算、重み付き累計を再計算
```

**SKILL_BASED の効果例:**
- 3人のピッカー: TRAINEE(0.5), SENIOR(1.0), MASTER(1.5)
- 合計商品数 300 の場合:
  - TRAINEE: 約50件 (300 × 0.5/3.0)
  - SENIOR: 約100件 (300 × 1.0/3.0)
  - MASTER: 約150件 (300 × 1.5/3.0)

#### canPickerHandleTask の変更

変更なし。グループ単位で適格性チェック:
- グループ内の**全タスク**について `canPickerHandleTask()` を通すか
- **1つでもNG**なタスクがあればそのピッカーはそのグループを担当不可

#### タスク割り当て時のステータス更新

```php
$task->update([
    'picker_id' => $pickerId,
    'status' => WmsPickingTask::STATUS_PICKING_READY,
]);
```

### 完了条件

- 倉庫991のハードコードが完全に削除されていること
- `assignWithFloorPriority()` メソッドが削除されていること
- EQUAL 戦略で配送コース単位のグルーピング + 商品数均等が動作すること
- SKILL_BASED 戦略でスキルレベル比率に基づく割り当てが動作すること
- 存在しない strategy_key の場合にエラーメッセージが返ること
- トランザクション安全性が維持されていること

---

## P3: 割り当て解除機能

### 目的

割り当て済みタスクの `picker_id` をNULLに戻し、`status` を `PENDING` に戻す機能を追加。

### 修正対象ファイル

- `app/Services/Picking/AssignPickersToTasksService.php` に `unassign()` メソッド追加

### 修正内容

```php
/**
 * 指定倉庫の割り当て済みタスクを解除する
 *
 * @param int $warehouseId 倉庫ID
 * @return array ['success' => bool, 'unassigned_count' => int, 'message' => string]
 */
public function unassign(int $warehouseId): array
{
    // PICKING_READY のタスクのみ解除可能（PICKINGは作業中のため不可）
    $count = WmsPickingTask::where('warehouse_id', $warehouseId)
        ->whereNotNull('picker_id')
        ->where('status', WmsPickingTask::STATUS_PICKING_READY)
        ->update([
            'picker_id' => null,
            'status' => WmsPickingTask::STATUS_PENDING,
        ]);

    return [
        'success' => true,
        'unassigned_count' => $count,
        'message' => "{$count}件の割り当てを解除しました",
    ];
}
```

**注意:** `STATUS_PICKING`（作業中）のタスクは解除しない。作業中のピッカーの割り当てを外すと混乱するため。

### 完了条件

- `PICKING_READY` のタスクのみ解除されること
- `PICKING` のタスクは解除されないこと
- 解除後に `picker_id` が NULL、`status` が `PENDING` になること

---

## P4: モーダルUI更新

### 目的

- プレビューに合計商品数を追加表示
- 割り当て解除ボタンを追加

### 修正対象ファイル

- `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingWaitings.php`

### 修正内容

#### 4-1. プレビューに商品数追加

`assign_preview` Placeholder の content を更新:

```php
// 既存の未割当タスク数に加え、合計商品数を取得
$unassignedCount = WmsPickingTask::where('warehouse_id', $warehouseId)
    ->whereNull('picker_id')
    ->where('status', 'PENDING')
    ->count();

$totalItemCount = WmsPickingTask::where('warehouse_id', $warehouseId)
    ->whereNull('picker_id')
    ->where('status', 'PENDING')
    ->withCount('pickingItemResults as item_count')
    ->get()
    ->sum('item_count');

// 表示に商品数を追加
// 未割当タスク: XX件 / 商品数: XX件 / 選択ピッカー: X名 / 約XX件/人
```

#### 4-2. 割り当て解除ボタン

`getHeaderActions()` に `unassignPickers` アクションを追加:

```php
Action::make('unassignPickers')
    ->label('割り当て解除')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->requiresConfirmation()
    ->modalHeading('ピッカー割り当て解除')
    ->modalDescription('選択した倉庫の「ピッキング準備完了」タスクの割り当てを解除します。作業中のタスクは解除されません。')
    ->schema([
        ViewField::make('unassign_warehouse_id')
            ->label('対象倉庫')
            ->view('filament.forms.components.warehouse-select')
            ->viewData([...]) // 既存と同じ
            ->default($defaultWarehouseId)
            ->required()
            ->live(),

        Placeholder::make('unassign_preview')
            ->content(function (Get $get): HtmlString {
                $warehouseId = $get('unassign_warehouse_id');
                if (!$warehouseId) return new HtmlString('...');

                $readyCount = WmsPickingTask::where('warehouse_id', $warehouseId)
                    ->whereNotNull('picker_id')
                    ->where('status', 'PICKING_READY')
                    ->count();

                // 解除対象: XX件（ピッキング準備完了）
            }),
    ])
    ->action(function (array $data) {
        $service = new AssignPickersToTasksService;
        $result = $service->unassign($data['unassign_warehouse_id']);
        // 通知
    }),
```

### 完了条件

- プレビューに合計商品数が表示されること
- 割り当て解除ボタンが表示されること
- 解除確認ダイアログが表示されること
- 解除後にテーブルがリフレッシュされること

---

## P5: Seeder更新・InitSystemSeeder登録

### 目的

- `WmsPickingAssignmentStrategySeeder` を全倉庫対応に変更
- `InitSystemSeeder` に登録
- 既存の ZONE_PRIORITY レコードを削除/更新

### 修正対象ファイル

- `database/seeders/WmsPickingAssignmentStrategySeeder.php`
- `database/seeders/InitSystemSeeder.php`

### 修正内容

#### WmsPickingAssignmentStrategySeeder

```php
public function run(): void
{
    // 1. 既存の ZONE_PRIORITY レコードを EQUAL に変更（または削除）
    WmsPickingAssignmentStrategy::where('strategy_key', 'zone_priority')
        ->update([
            'strategy_key' => PickingStrategyType::EQUAL->value,
            'name' => DB::raw("REPLACE(name, '冷凍エリア優先', '商品数均等割り当て')"),
            'is_active' => false,
        ]);

    // 2. 全アクティブ倉庫にデフォルト EQUAL 戦略を作成
    $warehouses = Warehouse::where('is_active', true)->get();

    foreach ($warehouses as $warehouse) {
        // デフォルト: 商品数均等
        WmsPickingAssignmentStrategy::updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'is_default' => true],
            [
                'name' => '商品数均等割り当て',
                'description' => '商品数ベースで均等に配分。配送コース単位でまとめて割り当て。',
                'strategy_key' => PickingStrategyType::EQUAL->value,
                'parameters' => ['group_by' => 'delivery_course'],
                'is_active' => true,
            ]
        );

        // スキルレベル考慮
        WmsPickingAssignmentStrategy::updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'name' => 'スキルレベル考慮割り当て'],
            [
                'description' => 'スキルレベルに応じて商品数の割り当て比率を調整。',
                'strategy_key' => PickingStrategyType::SKILL_BASED->value,
                'parameters' => [
                    'group_by' => 'delivery_course',
                    'skill_rates' => [
                        '1' => 0.5,
                        '2' => 0.8,
                        '3' => 1.0,
                        '4' => 1.2,
                        '5' => 1.5,
                    ],
                ],
                'is_default' => false,
                'is_active' => true,
            ]
        );
    }
}
```

#### InitSystemSeeder

```php
// ピッカー割り当て戦略の初期設定
$this->call(WmsPickingAssignmentStrategySeeder::class);
```

### 完了条件

- `php artisan db:seed --class=WmsPickingAssignmentStrategySeeder` が正常に実行できること
- 全アクティブ倉庫に EQUAL（デフォルト）と SKILL_BASED の戦略が作成されること
- 既存の ZONE_PRIORITY レコードが無効化されていること
- InitSystemSeeder に登録されていること

---

## P6: 動作確認

### 目的

全体通しでの動作確認。

### 確認手順

1. シーダー実行で戦略が正しく生成されるか
2. モーダルで倉庫選択 → 戦略選択 → ピッカー選択 → プレビュー（タスク件数+商品数）が表示されるか
3. EQUAL 戦略で割り当て実行 → 配送コース単位でまとまっているか、商品数が均等か
4. SKILL_BASED 戦略で割り当て実行 → スキルレベルに応じた比率になっているか
5. 割り当て解除 → PICKING_READY のみ解除されるか
6. 解除後に再割り当て → 正常に動作するか

### 完了条件

- 全フロー（割り当て → 解除 → 再割り当て）がエラーなく動作すること
- 配送コースが分割されていないこと
- SKILL_BASED で比率が概ね正しいこと

---

## 制約（厳守）

1. **FK制約禁止** — アプリレベルでデータ整合性管理
2. **`migrate:fresh` / `migrate:refresh` 禁止** — 本番DB共有
3. **配送コース分割禁止** — 同一 delivery_course_id のタスクは同一ピッカーに割り当て
4. **canPickerHandleTask の既存ロジック維持** — 制限エリア＋ピッキングエリアチェックは変更しない
5. **sakemaru コネクションのトランザクション維持**
6. **PICKING ステータスのタスクは解除不可** — 作業中のタスクは触らない

## 全体完了条件

- 倉庫991のハードコードが完全に除去されていること
- ZONE_PRIORITY が Enum とシーダーから削除されていること
- EQUAL/SKILL_BASED が戦略パラメータに基づいて動作すること
- 配送コース単位の割り当てが保証されていること
- モーダルで商品数が確認できること
- 割り当て解除→再割り当てのフローが動作すること
