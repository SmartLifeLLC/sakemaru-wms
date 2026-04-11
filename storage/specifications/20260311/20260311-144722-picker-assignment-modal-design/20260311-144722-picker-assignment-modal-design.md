# ピッカー一括割り当てモーダル デザインアップデート仕様

- **作成日**: 2026-03-11
- **ステータス**: ドラフト
- **ディレクトリ**: storage/specifications/20260311/20260311-144722-picker-assignment-modal-design/
- **デザインベース**: `~/.claude/design-knowledge/modal-design.md`

## 目的

`admin/wms-picking-waitings` のピッカー一括割り当てモーダルを、波動生成モーダルと同じデザイン基準に統一する。現在は Filament 標準の `Select` / `CheckboxList` を使用しているが、カスタム ViewField コンポーネント（Searchable Select / Checkbox Grid）に置換し、紺色ヘッダー・フッターボタン右寄せの統一スタイルを適用する。

## 現状の実装

### 現在のモーダル構成（`ListWmsPickingWaitings.php`）

```php
->form([
    Select::make('warehouse_id')         // Filament標準Select
    CheckboxList::make('picker_ids')     // Filament標準CheckboxList
    Select::make('strategy_id')          // Filament標準Select
])
```

### 問題点

1. Filament 標準コンポーネントのデザインが他のカスタムモーダルと不統一
2. `->form()` を使用（Filament 4 では `->schema()` が正しい）
3. 紺色ヘッダー・フッター右寄せが適用されていない
4. 全角半角検索非対応
5. ピッカー選択がカード型チェックボックスではない

## モーダル基本設定

| 項目 | 値 |
|------|-----|
| 配置先 | `App\Filament\Resources\WmsPickingTasks\Pages\ListWmsPickingWaitings` |
| トリガー | ヘッダーアクション「ピッカー割り当て」ボタン |
| モーダル幅 | `4xl` |
| CSSクラス | `picker-assign-modal` |
| ヘッダースタイル | 紺色 (#1e293b) |
| フッターボタン | 右寄せ |

## レイアウト構造

```
┌──────────────────────────────────────────┐
│ ■ ピッカー一括割り当て                  × │
│ 選択したピッカーに未割当タスクを...       │
├──────────────────────────────────────────┤
│ [倉庫セレクト        ] │ [割当戦略セレクト] │ ← Grid(2)
├──────────────────────────────────────────┤
│ ☑ 田中太郎   ☑ 鈴木一郎                 │ ← Checkbox Grid
│ □ 佐藤花子   ☑ 山田次郎                 │   (ピッカー選択)
│ 検索... 全選択  3件選択                  │
├──────────────────────────────────────────┤
│ 未割当タスク: 12件  選択ピッカー: 3名    │ ← サマリーバー
├──────────────────────────────────────────┤
│                          [割り当て実行]   │ ← フッター右寄せ
└──────────────────────────────────────────┘
```

## フォームフィールド定義

### warehouse_id（対象倉庫）

| 項目 | 値 |
|------|-----|
| タイプ | Searchable Select |
| ViewField | `filament.forms.components.warehouse-select` |
| ラベル | 対象倉庫 |
| データソース | `Warehouse::where('is_active', true)->orderBy('code')` |
| 必須 | Yes |
| Live | Yes |
| デフォルト値 | `auth()->user()->warehouse->id` |

### strategy_id（割当戦略）

| 項目 | 値 |
|------|-----|
| タイプ | Searchable Select |
| ViewField | `filament.forms.components.searchable-select`（新規汎用版） |
| ラベル | 割当戦略 |
| データソース | `WmsPickingAssignmentStrategy::where('warehouse_id', $warehouseId)->where('is_active', true)` |
| 必須 | Yes |
| Live | No |
| デフォルト値 | `is_default = true` の戦略 |

### picker_ids（ピッカー選択）

| 項目 | 値 |
|------|-----|
| タイプ | Checkbox Grid |
| ViewField | `filament.forms.components.checkbox-grid` |
| ラベル | ピッカー選択 |
| データソース | `WmsPicker::where('current_warehouse_id', $warehouseId)->where('is_available_for_picking', true)->where('is_active', true)` |
| 必須 | Yes |
| Live | No |
| ヘルパーテキスト | 出勤中で稼働可能なピッカーのみ表示されます |

### assign_preview（割当プレビュー）

| 項目 | 値 |
|------|-----|
| タイプ | Placeholder + HtmlString |
| ラベル | 割当サマリー |
| 内容 | 未割当タスク件数 + 選択ピッカー数のサマリーバー |

## Grid レイアウト

```php
->schema([
    Grid::make(2)->schema([
        // 左: 倉庫選択
        ViewField::make('warehouse_id')
            ->label('対象倉庫')
            ->view('filament.forms.components.warehouse-select')
            ->viewData([...])
            ->required()->live(),

        // 右: 割当戦略
        ViewField::make('strategy_id')
            ->label('割当戦略')
            ->view('filament.forms.components.searchable-select')
            ->viewData(function (Get $get) { ... })
            ->required(),
    ]),

    // ピッカー選択（全幅）
    ViewField::make('picker_ids')
        ->label('ピッカー選択')
        ->view('filament.forms.components.checkbox-grid')
        ->viewData(function (Get $get) { ... })
        ->required()
        ->visible(fn (Get $get) => $get('warehouse_id')),

    // 割当サマリー
    Placeholder::make('assign_preview')
        ->label('割当サマリー')
        ->content(function (Get $get): HtmlString { ... })
        ->visible(fn (Get $get) => $get('warehouse_id')),
])
```

## Blade コンポーネント

### 新規作成が必要なもの

| ファイル | ベース | カスタマイズ |
|---------|--------|------------|
| `resources/views/filament/forms/components/searchable-select.blade.php` | `warehouse-select.blade.php` | 変数名を `warehouses` → `items` に汎用化。code/name の表示有無をオプション化 |

### 既存コンポーネントの再利用

| ファイル | 用途 |
|---------|------|
| `warehouse-select.blade.php` | 倉庫選択（そのまま使用） |
| `checkbox-grid.blade.php` | ピッカー選択（そのまま使用） |

## CSS 定義

`resources/css/filament/admin/theme.css` に追記:

```css
/* ピッカー割り当てモーダル */
.picker-assign-modal .fi-modal-header {
    background-color: #1e293b !important;
    border-radius: 0.75rem 0.75rem 0 0;
    padding: 0.75rem 1rem;
}
.picker-assign-modal .fi-modal-header .fi-modal-heading {
    color: #ffffff !important;
}
.picker-assign-modal .fi-modal-header .fi-modal-description {
    color: #94a3b8 !important;
}
.picker-assign-modal .fi-modal-header .fi-modal-close-btn {
    color: #ffffff !important;
}
.picker-assign-modal .fi-modal-header .fi-modal-close-btn:hover {
    color: #cbd5e1 !important;
}
.picker-assign-modal .fi-modal-footer {
    justify-content: flex-end !important;
}
.picker-assign-modal .fi-modal-footer > * {
    justify-content: flex-end !important;
}
```

## アクション処理

```php
->action(function (array $data) {
    $service = new AssignPickersToTasksService;
    $result = $service->execute(
        warehouseId: $data['warehouse_id'],
        pickerIds: $data['picker_ids'],
        strategyId: $data['strategy_id']
    );
    // 既存の通知ロジックはそのまま
})
```

既存の `AssignPickersToTasksService` のロジックは変更なし。

## 表示データ（Placeholder/プレビュー）

### 割当サマリーバー

倉庫選択後に表示。未割当タスク件数と選択ピッカー数を表示:

```html
<div class="flex justify-between items-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800">
    <div class="flex items-center gap-4">
        <span class="text-xs text-slate-500 dark:text-gray-400">
            未割当タスク: <span class="font-bold text-slate-700 dark:text-gray-200">{count}件</span>
        </span>
        <span class="text-xs text-slate-500 dark:text-gray-400">
            選択ピッカー: <span class="font-bold text-blue-600 dark:text-blue-400">{count}名</span>
        </span>
    </div>
    <span class="text-xs text-slate-400 dark:text-gray-500">
        約 <span class="font-bold">{tasks/pickers}件</span>/人
    </span>
</div>
```

### 空状態

- 倉庫未選択: `fa-warehouse` + 「対象倉庫を選択してください」
- ピッカー0名: `fa-user-slash` + 「出勤中のピッカーがいません」
- 未割当タスク0件: `fa-check-circle` + 「未割当のタスクはありません」

## 対象ファイル

### 新規作成
- `resources/views/filament/forms/components/searchable-select.blade.php` — 汎用Searchable Select

### 既存変更
- `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingWaitings.php` — モーダル定義の書き換え
- `resources/css/filament/admin/theme.css` — `.picker-assign-modal` CSS追加

### 参照のみ
- `~/.claude/design-knowledge/modal-design.md` — デザイン基準
- `app/Services/Picking/AssignPickersToTasksService.php` — 割当ロジック（変更なし）
- `app/Models/WmsPicker.php` — ピッカーモデル（変更なし）
- `app/Models/WmsPickingAssignmentStrategy.php` — 戦略モデル（変更なし）

## 制約

- FK禁止、migrate:fresh 禁止（CLAUDE.md 準拠）
- `AssignPickersToTasksService` のビジネスロジックは変更しない
- モデル・DBスキーマの変更なし（純粋なUI変更）
- `->form()` → `->schema()` への変更（Filament 4 正式パターン）

## 確認事項

1. 割当戦略セレクトは warehouse_id 変更時に連動リセットが必要 — `afterStateUpdated` 相当の処理を ViewField でどう実現するか（`$watch` + `state = null` で対応可能）
2. ピッカー選択も warehouse_id 変更時にリセットが必要 — 同上
