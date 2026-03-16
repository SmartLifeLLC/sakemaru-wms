# 倉庫別ダッシュボード 作業計画

## 前提

- 現在 `/admin` トップページはFilamentデフォルトの `Dashboard::class`（`AccountWidget` + `FilamentInfoWidget`）
- `PendingTasksWidget` は既に `$warehouseId` プロパティ対応済み
- ユーザーモデルに `default_warehouse_id` あり
- 横持ち = `WmsShortageAllocation`、入荷予定 = `WmsOrderIncomingSchedule`

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | Dashboard Page + AdminPanel設定 | カスタムDashboardページ作成、倉庫セレクタ | `/admin` でカスタムダッシュボードが表示される |
| P2 | サマリーカード実装 | 5種のStatsカード | 当日の集計値が正しく表示される |
| P3 | 横持ち出荷依頼テーブル | 当日分の横持ちデータ一覧 | テーブルにデータ表示、一覧ページへのリンク動作 |
| P4 | 入荷予定テーブル | 当日分の入荷予定一覧 | テーブルにデータ表示、一覧ページへのリンク動作 |
| P5 | ピッキング待ちタスク統合 | PendingTasksWidgetの埋め込み | 倉庫フィルタ連動で表示される |
| P6 | UI調整・動作確認 | レスポンシブ、空データ、デザイン | 全セクション正常動作、空データ時のメッセージ表示 |

---

## P1: Dashboard Page + AdminPanel設定

### 目的

Filamentデフォルトダッシュボードを、倉庫セレクタ付きのカスタムダッシュボードに置き換える。

### 修正方針

1. `app/Filament/Pages/Dashboard.php` を新規作成
   - `Filament\Pages\Page` を継承（Filamentデフォルトの `Dashboard` ではない）
   - Livewireプロパティ: `$warehouseId`（int|null）
   - `mount()` で `auth()->user()->default_warehouse_id` をセット
   - `$warehouseId` が null の場合、最初のアクティブ倉庫をフォールバック
   - 倉庫一覧を `getWarehouseOptions()` メソッドで取得

2. `resources/views/filament/pages/dashboard.blade.php` を新規作成
   - ヘッダー: 倉庫セレクタ（`<select wire:model.live="warehouseId">`）+ 当日日付
   - セクション枠だけ作成（中身はP2〜P5で実装）

3. `AdminPanelProvider.php` を変更
   - L46 の `Dashboard::class` を `\App\Filament\Pages\Dashboard::class` に変更
   - デフォルトウィジェット（`AccountWidget`, `FilamentInfoWidget`）を削除

### 修正対象ファイル

| ファイル | 操作 |
|----------|------|
| `app/Filament/Pages/Dashboard.php` | 新規作成 |
| `resources/views/filament/pages/dashboard.blade.php` | 新規作成 |
| `app/Providers/Filament/AdminPanelProvider.php` | 変更（L45-52） |

### 完了条件

- `/admin` にアクセスすると倉庫セレクタと当日日付が表示される
- セレクタがユーザーの `default_warehouse_id` で初期選択される
- セレクタ変更で `$warehouseId` が更新される（Livewire反応確認）
- エラーなくページが表示される

---

## P2: サマリーカード実装

### 目的

当日の倉庫別KPIを5枚のカードで表示する。

### 修正方針

`Dashboard.php` にデータ取得メソッドを追加し、Bladeでカード表示:

```php
public function getSummaryData(): array
{
    $wh = $this->warehouseId;
    $today = now()->toDateString();

    return [
        'picking_slips' => WmsPickingTask::where('warehouse_id', $wh)
            ->whereDate('created_at', $today)
            ->where('task_type', 'WAVE')
            ->count(),
        'incoming_pending' => WmsOrderIncomingSchedule::where('warehouse_id', $wh)
            ->where('expected_arrival_date', $today)
            ->whereIn('status', [IncomingScheduleStatus::PENDING, IncomingScheduleStatus::PARTIAL])
            ->count(),
        'transfer_requests' => WmsShortageAllocation::where(fn ($q) =>
                $q->where('target_warehouse_id', $wh)
                  ->orWhere('source_warehouse_id', $wh))
            ->where('shipment_date', $today)
            ->whereNotIn('status', ['CANCELLED', 'FULFILLED'])
            ->count(),
        'shortages' => WmsShortage::where('warehouse_id', $wh)
            ->whereDate('detected_date', $today)
            ->where('is_resolved', false)
            ->count(),
        'picking_remaining' => WmsPickingTask::where('warehouse_id', $wh)
            ->whereIn('status', [WmsPickingTask::STATUS_PENDING, WmsPickingTask::STATUS_ASSIGNED])
            ->where('task_type', 'WAVE')
            ->count(),
    ];
}
```

Bladeカード表示:
- Tailwind CSS Grid (5列、mdで3列、smで2列)
- 各カードにアイコン・数値・ラベル
- 色: primary / success / info / danger / warning

### 修正対象ファイル

| ファイル | 操作 |
|----------|------|
| `app/Filament/Pages/Dashboard.php` | メソッド追加 |
| `resources/views/filament/pages/dashboard.blade.php` | カードセクション追加 |

### 完了条件

- 5枚のカードが表示され、数値が正しい
- 倉庫セレクタ変更で数値が更新される
- データがない場合は `0` が表示される

---

## P3: 横持ち出荷依頼テーブル

### 目的

当日の横持ち出荷依頼を一覧表示する。倉庫が出荷元か受入先かの方向も表示。

### 修正方針

`Dashboard.php` にデータ取得メソッド追加:

```php
public function getTransferRequests(): Collection
{
    return WmsShortageAllocation::where(fn ($q) =>
            $q->where('target_warehouse_id', $this->warehouseId)
              ->orWhere('source_warehouse_id', $this->warehouseId))
        ->where('shipment_date', today())
        ->whereNotIn('status', ['CANCELLED'])
        ->with(['shortage.item', 'sourceWarehouse', 'targetWarehouse', 'deliveryCourse'])
        ->orderByRaw("FIELD(status, 'PENDING', 'RESERVED', 'PICKING', 'FULFILLED', 'SHORTAGE')")
        ->limit(20)
        ->get();
}
```

Bladeテーブル:
- セクションヘッダー「横持ち出荷依頼（当日分）」
- カラム: 方向、ステータス（バッジ）、相手倉庫、商品CD、商品名、数量、配送コース
- 方向: `source_warehouse_id == $warehouseId` なら「出荷→」、それ以外は「←入荷」
- フッター: 「横持ち出荷依頼一覧へ →」リンク
- データなし: 「本日の横持ち出荷依頼はありません」

### ステータスバッジ色

`WmsShortageAllocation` のステータス定数に基づく:
- PENDING → warning (黄)
- RESERVED → info (青)
- PICKING → primary (琥珀)
- FULFILLED → success (緑)
- SHORTAGE → danger (赤)

### 修正対象ファイル

| ファイル | 操作 |
|----------|------|
| `app/Filament/Pages/Dashboard.php` | メソッド追加 |
| `resources/views/filament/pages/dashboard.blade.php` | テーブルセクション追加 |

### 完了条件

- テーブルにデータが正しく表示される
- 方向アイコンが正しい
- ステータスバッジの色が正しい
- フッターリンクが横持ち出荷依頼一覧ページに遷移する
- 倉庫変更で再取得される

---

## P4: 入荷予定テーブル

### 目的

当日の入荷予定を一覧表示する。

### 修正方針

`Dashboard.php` にデータ取得メソッド追加:

```php
public function getIncomingSchedules(): Collection
{
    return WmsOrderIncomingSchedule::where('warehouse_id', $this->warehouseId)
        ->where('expected_arrival_date', today())
        ->whereIn('status', [
            IncomingScheduleStatus::PENDING,
            IncomingScheduleStatus::PARTIAL,
            IncomingScheduleStatus::CONFIRMED,
        ])
        ->with(['item', 'contractor', 'sourceWarehouse'])
        ->orderByRaw("FIELD(status, 'PENDING', 'PARTIAL', 'CONFIRMED')")
        ->limit(30)
        ->get();
}
```

Bladeテーブル:
- セクションヘッダー「入荷予定（当日分）」
- カラム: ステータス（バッジ）、発注元、伝票番号、商品CD、商品名、予定数、入荷数、残数
- ステータスバッジ色: `IncomingScheduleStatus` enum の `color()` メソッド準拠
- フッター: 「入荷予定一覧へ →」リンク
- データなし: 「本日の入荷予定はありません」

### 修正対象ファイル

| ファイル | 操作 |
|----------|------|
| `app/Filament/Pages/Dashboard.php` | メソッド追加 |
| `resources/views/filament/pages/dashboard.blade.php` | テーブルセクション追加 |

### 完了条件

- テーブルにデータが正しく表示される
- ステータスバッジの色が enum 準拠
- 残数 = 予定数 - 入荷数 が正しい
- フッターリンクが入荷予定一覧ページに遷移する

---

## P5: ピッキング待ちタスク統合

### 目的

既存 `PendingTasksWidget` をダッシュボードに埋め込む。

### 修正方針

`PendingTasksWidget` は既に `$warehouseId` プロパティ対応済み（L14）。

Blade内で Livewire コンポーネントとして埋め込む:

```blade
<div class="mt-6">
    @livewire(\App\Filament\Widgets\PendingTasksWidget::class, [
        'warehouseId' => $this->warehouseId,
    ], key('pending-tasks-' . $this->warehouseId))
</div>
```

`key()` に `$warehouseId` を含めることで、倉庫変更時にウィジェットが再レンダリングされる。

### 修正対象ファイル

| ファイル | 操作 |
|----------|------|
| `resources/views/filament/pages/dashboard.blade.php` | ウィジェット埋め込み追加 |

### 完了条件

- ピッキング待ちタスクがダッシュボード下部に表示される
- 倉庫変更でタスク一覧が更新される
- タスクなし時は「未準備のタスクはありません」が表示される

---

## P6: UI調整・動作確認

### 目的

全体のUI統一、レスポンシブ対応、エッジケース対応。

### 調整項目

1. **レスポンシブ**
   - サマリーカード: `grid-cols-2 md:grid-cols-3 lg:grid-cols-5`
   - テーブル: `overflow-x-auto` でスクロール対応

2. **空データ表示**
   - 各テーブルが0件の場合、灰色背景+メッセージ
   - サマリーカードは常に0表示（非表示にしない）

3. **ローディング**
   - 倉庫セレクタ変更時に `wire:loading` でスピナー表示

4. **デザイン統一**
   - セクション間の余白: `space-y-6`
   - テーブルヘッダー: 濃い灰色背景
   - 偶数行: ストライプ（`even:bg-gray-50`）

5. **動作確認チェックリスト**
   - [ ] `/admin` にアクセスしてダッシュボードが表示される
   - [ ] 倉庫セレクタがdefault_warehouse_idで初期選択される
   - [ ] セレクタ変更で全セクション更新される
   - [ ] 横持ちデータが正しく表示される（方向、ステータス色）
   - [ ] 入荷予定が正しく表示される（ステータス色、残数計算）
   - [ ] ピッキング待ちタスクが表示される
   - [ ] 各テーブルのフッターリンクが正しいページに遷移する
   - [ ] データなし時にメッセージ表示される
   - [ ] 既存の出荷/入荷ダッシュボードがナビからアクセス可能

### 修正対象ファイル

| ファイル | 操作 |
|----------|------|
| `resources/views/filament/pages/dashboard.blade.php` | UI調整 |
| `app/Filament/Pages/Dashboard.php` | 必要に応じて調整 |

### 完了条件

- 上記チェックリスト全項目がクリア
- エラーログにWarning/Error なし

---

## 制約（厳守）

1. **DB破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` は実行しない
2. **FK禁止**: 外部キー制約は使用しない
3. **DB変更なし**: 今回のタスクでマイグレーション作成は不要
4. **既存ページ温存**: 出荷ダッシュボード（`WmsOutbound`）、入荷ダッシュボード（`WmsInbound`）は変更しない
5. **Filament 4準拠**: インポートパスは CLAUDE.md の正しいパスを使用
6. **パフォーマンス**: N+1回避のため `with()` を使用、当日データのみ取得

## 全体完了条件

- P1〜P6 が全て完了していること
- `/admin` にアクセスして倉庫別ダッシュボードが正しく表示されること
- 既存の出荷/入荷ダッシュボードが引き続きアクセス可能であること
