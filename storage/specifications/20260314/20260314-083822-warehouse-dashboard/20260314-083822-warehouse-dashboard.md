# 倉庫別ダッシュボード（トップページ）リニューアル

- **作成日**: 2026-03-14
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260314/20260314-083822-warehouse-dashboard/`

## 背景・目的

現在のダッシュボード（`/admin`）はFilamentデフォルトのままで、実用的な情報が表示されていない。出荷ダッシュボードは全倉庫の合計値を表示し、入荷ダッシュボードは検索型UIで「一覧性」に欠ける。

倉庫担当者が**ログイン直後に自分の担当倉庫の当日状況を一目で把握**できるダッシュボードが必要。特に以下の情報が重要:

1. **横持ち出荷依頼リスト** — 欠品による他倉庫からの代理出荷（`wms_shortage_allocations`）の当日分
2. **入荷予定リスト** — 当日到着予定の入荷スケジュール（`wms_order_incoming_schedules`）

## 現状の実装

### 既存ダッシュボード構成

| ページ | ファイル | 内容 |
|--------|----------|------|
| 出荷ダッシュボード | `app/Filament/Pages/WmsOutbound.php` | 全倉庫合計のStats + チャート |
| 入荷ダッシュボード | `app/Filament/Pages/WmsInbound.php` | Livewire検索型UI |

### ウィジェット
- `WmsOutboundOverview` — 全倉庫ループでStats集計
- `WmsOutboundChartsWidget` — `wms_daily_stats` ベースのトレンドチャート
- `PendingTasksWidget` — 未着手ピッキングタスク一覧

### ユーザーの倉庫設定
- `users.default_warehouse_id`（sakemaru共有DB）
- 各リストページで `auth()->user()->default_warehouse_id` を参照してプリセットビュー生成

## 変更内容

### 概要

`/admin` のトップページを**倉庫別ダッシュボード**に置き換える。ユーザーの `default_warehouse_id` をデフォルト表示し、倉庫切り替えセレクタで他倉庫も確認可能にする。

### 詳細設計

#### ページ構成

**新規ダッシュボードページ**: `app/Filament/Pages/Dashboard.php`
- Filamentデフォルトの `\Filament\Pages\Dashboard` を置き換え
- カスタムBladeビュー使用

#### レイアウト

```
┌─────────────────────────────────────────────────────┐
│ [倉庫セレクタ ▼]          2026-03-14（金）            │
├─────────────────────────────────────────────────────┤
│                                                     │
│  📊 本日のサマリー（Stats Cards）                     │
│  ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐     │
│  │出荷数 │ │入荷予定│ │横持依頼│ │欠品数 │ │ピッキング│ │
│  │  42  │ │  15  │ │   3  │ │   2  │ │  残8  │     │
│  └──────┘ └──────┘ └──────┘ └──────┘ └──────┘     │
│                                                     │
├─────────────────────────────────────────────────────┤
│                                                     │
│  🚚 横持ち出荷依頼（当日分）                          │
│  ┌─────────────────────────────────────────────┐    │
│  │ ステータス│出荷元│商品CD│商品名│数量│配送コース│   │
│  │ 未着手   │倉庫A │1234 │○○  │ 5 │ C01    │    │
│  │ ピッキング│倉庫B │5678 │△△  │ 3 │ C02    │    │
│  └─────────────────────────────────────────────┘    │
│  → 横持ち出荷依頼一覧へ                              │
│                                                     │
├─────────────────────────────────────────────────────┤
│                                                     │
│  📦 入荷予定（当日分）                                │
│  ┌─────────────────────────────────────────────┐    │
│  │ ステータス│発注元│商品CD│商品名│予定数│入荷数│    │
│  │ 未入荷   │自動  │1234 │○○  │ 10 │  0  │     │
│  │ 一部入荷 │手動  │5678 │△△  │ 20 │ 15  │     │
│  └─────────────────────────────────────────────┘    │
│  → 入荷予定一覧へ                                    │
│                                                     │
├─────────────────────────────────────────────────────┤
│                                                     │
│  📋 ピッキング待ちタスク                              │
│  （既存 PendingTasksWidget を倉庫フィルタ付きで再利用） │
│                                                     │
└─────────────────────────────────────────────────────┘
```

#### 1. 倉庫セレクタ

- デフォルト: `auth()->user()->default_warehouse_id`
- 全アクティブ倉庫から選択可能
- Livewire `$warehouseId` プロパティで管理
- 選択変更時に全セクションを更新

#### 2. サマリーカード

当日（`today()`）の倉庫別集計:

| カード | データソース | クエリ |
|--------|-------------|--------|
| 出荷伝票数 | `wms_picking_tasks` | `warehouse_id = $wh AND created_at = today AND task_type = WAVE` |
| 入荷予定数 | `wms_order_incoming_schedules` | `warehouse_id = $wh AND expected_arrival_date = today AND status IN (PENDING, PARTIAL)` |
| 横持ち依頼数 | `wms_shortage_allocations` | `(target_warehouse_id = $wh OR source_warehouse_id = $wh) AND shipment_date = today AND status NOT IN (CANCELLED, FULFILLED)` |
| 欠品数 | `wms_shortages` | `warehouse_id = $wh AND detected_date = today AND is_resolved = false` |
| ピッキング残 | `wms_picking_tasks` | `warehouse_id = $wh AND status IN (PENDING, ASSIGNED) AND task_type = WAVE` |

#### 3. 横持ち出荷依頼テーブル（当日分）

**データソース**: `WmsShortageAllocation`

**クエリ条件**:
```php
WmsShortageAllocation::where(function ($q) use ($warehouseId) {
    $q->where('target_warehouse_id', $warehouseId)  // 受入倉庫として
      ->orWhere('source_warehouse_id', $warehouseId); // 出荷元として
})
->where('shipment_date', today())
->whereNotIn('status', ['CANCELLED'])
->with(['shortage.item', 'sourceWarehouse', 'targetWarehouse', 'deliveryCourse'])
->orderBy('status')  // 未着手→ピッキング→完了
->get();
```

**表示カラム**:

| カラム | フィールド | 備考 |
|--------|-----------|------|
| 方向 | — | 出荷元なら「→出荷」、受入なら「←入荷」アイコン |
| ステータス | `status` | バッジ表示 |
| 相手倉庫 | `source/target_warehouse.name` | 自倉庫でない方を表示 |
| 商品CD | `shortage.item.code` | |
| 商品名 | `shortage.item.name` | |
| 数量 | `assign_qty` / `assign_qty_type` | QuantityType表示 |
| 配送コース | `deliveryCourse.name` | |

**フッターリンク**: 横持ち出荷依頼一覧ページへ遷移

#### 4. 入荷予定テーブル（当日分）

**データソース**: `WmsOrderIncomingSchedule`

**クエリ条件**:
```php
WmsOrderIncomingSchedule::where('warehouse_id', $warehouseId)
->where('expected_arrival_date', today())
->whereIn('status', [
    IncomingScheduleStatus::PENDING,
    IncomingScheduleStatus::PARTIAL,
    IncomingScheduleStatus::CONFIRMED,
])
->with(['item', 'contractor', 'sourceWarehouse'])
->orderByRaw("FIELD(status, 'PENDING', 'PARTIAL', 'CONFIRMED')")
->get();
```

**表示カラム**:

| カラム | フィールド | 備考 |
|--------|-----------|------|
| ステータス | `status` | バッジ表示（色はIncomingScheduleStatus enum準拠） |
| 発注元 | `order_source` | OrderSource enum表示 |
| 伝票番号 | `slip_number` | |
| 商品CD | `item.code` | |
| 商品名 | `item.name` | |
| 予定数 | `expected_quantity` / `quantity_type` | |
| 入荷数 | `received_quantity` | |
| 残数 | `remaining_quantity` | アクセサ使用 |

**フッターリンク**: 入荷予定一覧ページへ遷移

#### 5. ピッキング待ちタスク

既存の `PendingTasksWidget` を改修し、`$warehouseId` プロパティを受け取れるようにする。

### Blade/UI実装方針

- **Livewireフルページコンポーネント**として実装（Filament Pageを継承）
- 各セクションはメソッドでデータ取得 → Bladeでレンダリング
- テーブルはTailwind CSSで直接記述（Filament Tableではなく軽量HTML）
- レスポンシブ対応: Stats CardsはGrid、テーブルは横スクロール

### 影響範囲

| 対象 | 影響 |
|------|------|
| Filament デフォルトダッシュボード | 完全に置き換え |
| `PendingTasksWidget` | 倉庫フィルタ対応の改修 |
| 既存の出荷/入荷ダッシュボード | **変更なし**（ナビゲーションから引き続きアクセス可能） |

## 制約

1. **FK禁止**: 全リレーションはアプリケーションレベル
2. **`migrate:fresh` / `migrate:refresh` 禁止**: 共有DB
3. **DB変更なし**: 今回は新規テーブル/カラム追加不要（既存データのみ参照）
4. **パフォーマンス**: 各クエリは当日データのみ対象のため軽量。N+1回避のため `with()` を使用

## 対象ファイル

### 新規作成

| ファイル | 内容 |
|----------|------|
| `app/Filament/Pages/Dashboard.php` | 新ダッシュボードページ（Livewire） |
| `resources/views/filament/pages/dashboard.blade.php` | ダッシュボードBlade |

### 既存変更

| ファイル | 変更内容 |
|----------|---------|
| `app/Filament/Widgets/PendingTasksWidget.php` | 倉庫ID受け取り対応 |
| `app/Providers/Filament/AdminPanelProvider.php` | デフォルトダッシュボード差し替え（必要に応じて） |

### 参照のみ

| ファイル | 参照理由 |
|----------|---------|
| `app/Models/WmsShortageAllocation.php` | 横持ちデータ取得 |
| `app/Models/WmsOrderIncomingSchedule.php` | 入荷予定データ取得 |
| `app/Models/Sakemaru/User.php` | `default_warehouse_id` 取得 |
| `app/Models/Sakemaru/Warehouse.php` | 倉庫一覧取得 |
| `app/Enums/AutoOrder/IncomingScheduleStatus.php` | ステータスバッジ色 |
| `app/Enums/AutoOrder/OrderSource.php` | 発注元ラベル |
| `app/Filament/Pages/WmsOutbound.php` | 既存実装パターン参考 |
| `app/Filament/Pages/WmsInbound.php` | 既存実装パターン参考 |
| `app/Filament/Widgets/WmsOutboundOverview.php` | Stats集計パターン参考 |

## 確認事項

1. **横持ちの方向性**: 横持ちテーブルで「自倉庫が出荷元」と「自倉庫が受入先」の両方を表示するか？それとも片方のみ？
2. **サマリーカードの追加項目**: 上記5項目以外に表示したいKPIはあるか？（例: 出荷金額、完了率）
3. **ピッキング待ちの表示**: 既存 `PendingTasksWidget` の再利用で問題ないか？新しいデザインにするか？
4. **倉庫セレクタの記憶**: 選択した倉庫をセッションに保存して次回アクセス時も維持するか？（現在は常に `default_warehouse_id` に戻る）
5. **当日以外のデータ**: 翌日・翌々日の入荷予定も表示するか？（事前準備のため）
6. **既存ダッシュボードの扱い**: 出荷ダッシュボード・入荷ダッシュボードはナビゲーションに残すか？
