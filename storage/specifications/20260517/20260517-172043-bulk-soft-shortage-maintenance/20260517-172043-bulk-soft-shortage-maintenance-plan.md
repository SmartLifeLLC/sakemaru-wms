# 統合引当欠品処理 作業計画

## 前提

- ピッキング調整ページ（V1）: `ListWmsPickingWaitings.php` が対象
- 現在のヘッダーアクション: `openVersion2`, `assignPickers`, `unassignPickers`
- 引当欠品明細は `WmsPickingItemResult` で `has_soft_shortage = true`（DB生成カラム）
- 既存の個別編集ロジック: `WmsPickingItemEditResource.php` の `planned_qty` TextInputColumn
- モーダルデザイン: `incoming-detail-modal` クラス、ヘッダー紺色、ボタン右寄せ danger

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | Blade テンプレート作成 | Alpine.js テーブルコンポーネント新規作成 | ファイルが存在し構文エラーなし |
| P2 | アクション実装 | ListWmsPickingWaitings にヘッダーアクション＋保存ロジック追加 | php artisan route:list でエラーなし |
| P3 | CSS・ビルド・動作確認 | theme.css 更新、Vite ビルド、ブラウザで動作確認 | モーダル表示→編集→保存が正常動作 |

---

## P1: Blade テンプレート作成

### 目的

モーダル内に表示する引当欠品明細の一括編集テーブルを Alpine.js + Blade で実装する。

### 新規ファイル

`resources/views/filament/forms/components/bulk-soft-shortage-table.blade.php`

### 設計

**Alpine.js データ構造:**
```javascript
{
    items: @json($items),          // サーバーから注入される全明細
    changes: {},                   // { id: { planned_qty: number } }
    get changeCount() { ... },     // 変更件数のリアクティブ計算
    get hasChanges() { ... },      // 変更有無
    updatePlannedQty(item, value) { ... },  // 引当数変更ハンドラ
    calcShortage(item) { ... },    // 欠品数の即時計算（バラ換算）
    isChanged(item) { ... },       // 変更判定
}
```

**テーブルカラム（仕様書確定分）:**

| # | カラム | データキー | 編集 |
|---|--------|-----------|------|
| 1 | タスクID | `picking_task_id` | - |
| 2 | 配送コース | `delivery_course_label` | - |
| 3 | 得意先名 | `partner_name` | - |
| 4 | 伝票番号 | `serial_id` | - |
| 5 | 商品CD | `item_code` | - |
| 6 | 商品名 | `item_name` | - |
| 7 | 入り数 | `capacity_case` | - |
| 8 | 受注数 | `ordered_qty` | - |
| 9 | 受注区分 | `ordered_qty_type_label` | - |
| 10 | **引当数** | `planned_qty` | **テキスト入力** |
| 11 | 引当区分 | `planned_qty_type_label` | - (読取のみ) |
| 12 | 欠品数 | Alpine.js で即時計算 | - (自動) |

**スタイル要件:**
- `incoming-detail-modal` クラスの既存スタイルを活用
- コンパクト行: `text-xs`, `py-1`
- 変更行: `bg-amber-50 dark:bg-amber-900/20`
- 欠品解消行（欠品数=0）: `bg-green-50 dark:bg-green-900/20`
- テーブルヘッダー: `sticky top-0`
- テーブル本体: `max-h-[60vh] overflow-y-auto`
- 引当数入力: `w-16 text-center text-xs` の number input
- サマリー表示: 対象タスク数 / 明細数 / 変更件数

**バリデーション（クライアントサイド）:**
- 数値のみ、0以上
- `planned_qty` のバラ換算が `ordered_qty` のバラ換算を超えない
  - バラ換算: `qty * (qty_type === 'CASE' ? capacity_case : 1)`

**Livewire 連携:**
- `$wire.set('bulk_shortage_data', JSON.stringify(changes))` で変更データを送信
- Filament の `ViewField` の `statePath` 経由ではなく、Alpine → Livewire の直接バインドを使用

### 実装手順

1. ファイル作成: `resources/views/filament/forms/components/bulk-soft-shortage-table.blade.php`
2. Alpine.js コンポーネント骨格（`x-data`, `items`, `changes`）
3. テーブル HTML（thead + tbody ループ）
4. 引当数入力フィールド（`x-model`, `@input` でバリデーション＋変更追跡）
5. 欠品数の即時計算（`x-text` で表示）
6. 変更行のスタイル切替（`:class` バインド）
7. サマリー表示（変更件数）
8. Livewire への変更データ送信（`x-init` で `$watch`）

### 完了条件

- ファイルが存在し、Blade 構文エラーがない
- `@json($items)` で初期データを受け取れる構造

---

## P2: アクション実装

### 目的

`ListWmsPickingWaitings.php` にヘッダーアクション `bulkSoftShortageMaintenance` を追加。データ取得・モーダル表示・保存ロジックを実装する。

### 修正ファイル

`app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingWaitings.php`

### 設計

#### ヘッダーアクション配置

`getHeaderActions()` の `openVersion2` の後、`assignPickers` の前に追加。

```php
Action::make('bulkSoftShortageMaintenance')
    ->label('統合引当欠品処理')
    ->icon('heroicon-o-wrench-screwdriver')
    ->color('warning')
    ->modalHeading('統合引当欠品処理')
    ->modalWidth('7xl')
    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
    ->modalFooterActionsAlignment(Alignment::End)
    ->modalSubmitAction(fn ($action) => $action
        ->makeModalSubmitAction('submit', [])
        ->label('確定')
        ->color('danger'))
    ->modalCancelActionLabel('変更せず閉じる')
    ->schema([
        ViewField::make('bulk_shortage_data')
            ->hiddenLabel()
            ->view('filament.forms.components.bulk-soft-shortage-table')
            ->viewData(fn () => static::getBulkShortageItems())
    ])
    ->action(fn (array $data) => static::saveBulkShortageChanges($data))
```

#### データ取得メソッド: `getBulkShortageItems()`

```php
private static function getBulkShortageItems(): array
{
    $warehouseId = auth()->user()?->getSelectedWarehouseId();
    $systemDate = ClientSetting::systemDateYMD();

    $items = WmsPickingItemResult::query()
        ->whereHas('pickingTask', function ($q) use ($warehouseId, $systemDate) {
            $q->whereIn('status', [
                WmsPickingTask::STATUS_PENDING,
                WmsPickingTask::STATUS_PICKING_READY,
            ])
            ->where('shipment_date', $systemDate);
            
            if ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            }
        })
        ->where('has_soft_shortage', true)
        ->with([
            'pickingTask.deliveryCourse',
            'item',
            'trade',
            'earning.buyer.partner',
        ])
        ->orderBy('picking_task_id')
        ->orderBy('walking_order')
        ->get();

    return [
        'items' => $items->map(fn ($item) => [
            'id' => $item->id,
            'picking_task_id' => $item->picking_task_id,
            'delivery_course_label' => $item->pickingTask?->deliveryCourse
                ? "[{$item->pickingTask->deliveryCourse->code}]{$item->pickingTask->deliveryCourse->name}"
                : '-',
            'partner_name' => $item->earning?->buyer?->partner?->name
                ?? $item->stockTransfer?->from_warehouse?->name
                ?? '-',
            'serial_id' => $item->trade?->serial_id ?? '-',
            'item_code' => $item->item?->code ?? '-',
            'item_name' => $item->item?->name ?? '-',
            'capacity_case' => (int) ($item->item?->capacity_case ?? 1),
            'ordered_qty' => (int) $item->ordered_qty,
            'ordered_qty_type' => $item->ordered_qty_type,
            'ordered_qty_type_label' => QuantityType::tryFrom($item->ordered_qty_type)?->name() ?? $item->ordered_qty_type,
            'planned_qty' => (int) $item->planned_qty,
            'planned_qty_type' => $item->planned_qty_type,
            'planned_qty_type_label' => QuantityType::tryFrom($item->planned_qty_type)?->name() ?? $item->planned_qty_type,
        ])->values()->toArray(),
        'task_count' => $items->pluck('picking_task_id')->unique()->count(),
    ];
}
```

#### 保存メソッド: `saveBulkShortageChanges()`

```php
private static function saveBulkShortageChanges(array $data): void
{
    $changesJson = $data['bulk_shortage_data'] ?? '{}';
    $changes = is_string($changesJson) ? json_decode($changesJson, true) : $changesJson;

    if (empty($changes)) {
        Notification::make()
            ->title('変更なし')
            ->body('変更された明細はありません')
            ->warning()
            ->send();
        return;
    }

    $updatedCount = 0;
    $errors = [];

    DB::connection('sakemaru')->transaction(function () use ($changes, &$updatedCount, &$errors) {
        foreach ($changes as $itemId => $change) {
            $record = WmsPickingItemResult::with(['item', 'pickingTask'])->find($itemId);
            if (!$record) continue;

            // ステータスチェック
            if (!in_array($record->pickingTask?->status, [
                WmsPickingTask::STATUS_PENDING,
                WmsPickingTask::STATUS_PICKING_READY,
            ])) {
                $errors[] = "ID {$itemId}: タスクのステータスが変更されています";
                continue;
            }

            $newPlannedQty = (int) ($change['planned_qty'] ?? $record->planned_qty);

            // バリデーション: バラ換算で受注数を超えないか
            $capacityCase = (int) ($record->item?->capacity_case ?? 1);
            $plannedPieces = $record->planned_qty_type === 'CASE'
                ? $newPlannedQty * max(1, $capacityCase)
                : $newPlannedQty;
            $orderedPieces = $record->ordered_qty_type === 'CASE'
                ? (int) $record->ordered_qty * max(1, $capacityCase)
                : (int) $record->ordered_qty;

            if ($plannedPieces > $orderedPieces) {
                $errors[] = "ID {$itemId}: 引当数が受注数を超えています";
                continue;
            }

            $oldPlannedQty = (int) $record->planned_qty;
            $record->planned_qty = $newPlannedQty;

            // picked_qty キャップ
            if ((int) $record->picked_qty > $newPlannedQty) {
                $record->picked_qty = $newPlannedQty;
            }

            // shortage_qty 再計算
            $record->shortage_qty = max(0, $orderedPieces - $plannedPieces);
            $record->save();

            // 操作ログ
            WmsAdminOperationLog::log(
                EWMSLogOperationType::ADJUST_PICKING_QTY,
                [
                    'target_type' => EWMSLogTargetType::PICKING_ITEM,
                    'target_id' => $record->id,
                    'picking_task_id' => $record->picking_task_id,
                    'picking_item_result_id' => $record->id,
                    'wave_id' => $record->pickingTask?->wave_id,
                    'earning_id' => $record->earning_id,
                    'qty_before' => $oldPlannedQty,
                    'qty_after' => $newPlannedQty,
                    'qty_type' => $record->planned_qty_type,
                    'operation_note' => '統合引当欠品処理による一括変更',
                ]
            );

            $updatedCount++;
        }
    });

    if (!empty($errors)) {
        Notification::make()
            ->title('一部エラーが発生しました')
            ->body(implode("\n", $errors))
            ->danger()
            ->send();
    }

    if ($updatedCount > 0) {
        Notification::make()
            ->title('引当数を一括更新しました')
            ->body("{$updatedCount}件の引当数を変更しました")
            ->success()
            ->send();
    }
}
```

#### 必要な use 文追加

```php
use App\Enums\QuantityType;
use App\Enums\EWMSLogOperationType;
use App\Enums\EWMSLogTargetType;
use App\Models\WmsAdminOperationLog;
use App\Models\WmsPickingItemResult;
use Illuminate\Support\Facades\DB;
```

### 実装手順

1. `ListWmsPickingWaitings.php` に use 文を追加
2. `getHeaderActions()` に `bulkSoftShortageMaintenance` アクションを追加（`openVersion2` の後）
3. `getBulkShortageItems()` メソッドを追加
4. `saveBulkShortageChanges()` メソッドを追加
5. `php artisan route:list` でエラーがないか確認

### 完了条件

- ヘッダーにボタンが表示される
- クリックでモーダルが開く
- `php artisan route:list` がエラーなし

---

## P3: CSS・ビルド・動作確認

### 目的

Tailwind CSS の動的クラスを safelist に追加し、Vite ビルド後にブラウザで動作確認する。

### 修正ファイル

`resources/css/filament/admin/theme.css`

### 実装手順

1. `theme.css` の `@source inline()` に必要なクラスを追加:
   - `bg-amber-50 dark:bg-amber-900/20` — 変更行
   - `bg-green-50 dark:bg-green-900/20` — 欠品解消行
   - その他 Blade 内で動的に使用するクラス
2. `npm run build` でビルド
3. ブラウザで動作確認:
   - `https://wms.sakemaru.test/admin/wms-picking-waitings` にアクセス
   - 「統合引当欠品処理」ボタンが表示されること
   - クリックでモーダルが開くこと
   - 引当欠品明細がテーブル表示されること
   - 引当数を編集すると変更行がハイライトされること
   - 欠品数がリアクティブに再計算されること
   - 「確定」で保存が成功すること
   - 保存後にテーブルの欠品件数が更新されること
4. エッジケース確認:
   - 欠品明細が0件の場合のメッセージ表示
   - 受注数を超える値を入力した場合のバリデーション
   - 変更なしで確定した場合の挙動

### 完了条件

- `npm run build` が成功
- モーダル表示 → 編集 → 保存 の一連フローが正常動作
- 変更行のスタイルが正しく適用
- バリデーションエラーが正しく表示

---

## 制約（厳守）

1. **DB破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh`, `db:wipe` は絶対禁止
2. **FK禁止**: 外部キー制約は使用しない
3. **planned_qty の上限**: バラ換算で `ordered_qty` を超える値は不可
4. **ステータス制限**: `PENDING` / `PICKING_READY` のタスクの明細のみ対象
5. **操作ログ必須**: `WmsAdminOperationLog::log(ADJUST_PICKING_QTY, ...)` で全変更を記録
6. **既存機能への影響なし**: 個別編集ページ、ピッカー割り当て、欠品対応は変更しない
7. **引当区分は変更しない**: 引当数（`planned_qty`）のみ編集可能
8. **Filament 4 のインポートパス**: `Filament\Actions\Action`（NOT `Filament\Tables\Actions\Action`）

## 全体完了条件

1. ピッキング調整ページのヘッダーに「統合引当欠品処理」ボタンが表示される
2. ボタンクリックでモーダルが開き、当日分・選択倉庫の引当欠品明細が一覧表示される
3. 引当数を編集すると欠品数がリアクティブに更新される
4. 「確定」ボタンで変更が一括保存され、操作ログが記録される
5. 保存後、テーブルの引当欠品件数が反映される
6. 既存の個別編集ページ・ピッカー割り当て機能に影響がない
