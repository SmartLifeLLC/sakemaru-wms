# 入荷予定日の算出理由表示 作業計画

## 前提

- `calculateArrivalDate()` は既に `shift_reasons` と `shifted_days` を計算し、`wms_order_calculation_logs.calculation_details` JSONに `到着日調整`（整数）と `調整理由`（文字列）として保存済み
- `wms_order_candidates` / `wms_stock_transfer_candidates` に `original_arrival_date`（調整前日付）保存済み
- `wms_order_calculation_logs.lead_time_days` に リードタイム日数保存済み
- 全モーダルで既に `$log` (WmsOrderCalculationLog) をロード済み

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | 入荷予定・入荷完了モーダル | 共通Blade + 2テーブルファイルに算出理由を追加 | 入荷予定・入荷完了の詳細モーダルでステップ形式の算出理由が表示される |
| P2 | 発注確定待ちモーダル | order-candidate-detail.blade.php に算出理由を追加 | 発注確定待ち詳細モーダルでステップ形式の算出理由が表示される |
| P3 | 移動確定待ちモーダル | transfer-candidate-detail.blade.php に算出理由を追加 | 移動確定待ち詳細モーダルでステップ形式の算出理由が表示される |

---

## P1: 入荷予定・入荷完了モーダル

### 目的

`incoming-schedule-detail.blade.php`（入荷予定・入荷完了で共用）の予定日行の下に、算出理由をステップ形式で表示する。

### 修正方針

#### Step 1: viewData追加（2ファイル共通）

`WmsOrderIncomingSchedulesTable.php` と `WmsIncomingCompletedTable.php` の `viewDetail` アクション内の `viewData` に以下を追加:

```php
'orderDate' => ..., // 既存
'expectedArrivalDate' => ..., // 既存
// ↓ 追加
'leadTimeDays' => $log?->lead_time_days ?? 0,
'originalArrivalDate' => $candidate?->original_arrival_date
    ? \Carbon\Carbon::parse($candidate->original_arrival_date)->format('Y/m/d（D）')
    : null,
'shiftedDays' => (int) ($details['到着日調整'] ?? 0),
'shiftReasons' => $details['調整理由'] ?? '',
```

**注意**: `$candidate` は `WmsOrderCandidate` or `WmsStockTransferCandidate`。既にロード済みの `$record->orderCandidate` or `$record->transferCandidate` を使う。
- 入荷予定テーブル: `$record->orderCandidate` → `original_arrival_date`
- 移動由来の場合: `$record->transferCandidate` → `original_arrival_date`

#### Step 2: Blade テンプレート修正

`incoming-schedule-detail.blade.php` の予定日行（L56-58付近）の下に算出理由セクションを追加:

**表示フォーマット（ステップ形式）:**

```
予定日    2026/04/07(火)
          04/04(土) + LT 1日 → 04/05(日) → 納品可能曜日調整(+2日) → 04/07(火)
```

**表示ルール:**
- 計算ログがある場合のみ表示（`$hasOrderCandidate && $hasCalculationLog`）
- `$shiftedDays > 0` の場合: ステップ形式で調整理由を表示
- `$shiftedDays == 0` の場合: 「LT {N}日（調整なし）」とシンプル表示
- デザイン: `text-xs text-gray-400 dark:text-gray-500` で予定日セル内の下段に表示

### 修正対象ファイル

1. `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php`
2. `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php`
3. `resources/views/filament/components/incoming-schedule-detail.blade.php`

### 完了条件

- `admin/wms-order-incoming-schedules` の詳細モーダルで予定日の下に算出理由がステップ形式で表示される
- `admin/wms-incoming-completed` の詳細モーダルでも同様に表示される
- 計算ログがないレコード（手動登録等）では算出理由が表示されない
- `php -l` で構文エラーなし

---

## P2: 発注確定待ちモーダル

### 目的

`order-candidate-detail.blade.php` の入荷予定日行の下に、算出理由をステップ形式で表示する。

### 修正方針

#### Step 1: viewData追加

`WmsOrderConfirmationWaitingTable.php` の `viewDetail` アクション内の `viewData` に追加:

```php
'leadTimeDays' => $log?->lead_time_days ?? 0,
'originalArrivalDate' => $record->original_arrival_date
    ? \Carbon\Carbon::parse($record->original_arrival_date)->format('Y/m/d（D）')
    : null,
'shiftedDays' => (int) ($details['到着日調整'] ?? 0),
'shiftReasons' => $details['調整理由'] ?? '',
```

**注意**: 発注候補テーブルでは `$record` が直接 `WmsOrderCandidate` なので `$record->original_arrival_date` で取得可能。

#### Step 2: Blade テンプレート修正

`order-candidate-detail.blade.php` の左カラム基本情報テーブル内、入荷予定日行の下に算出理由を追加。P1と同じステップ形式のデザインを適用。

### 修正対象ファイル

1. `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsOrderConfirmationWaitingTable.php`
2. `resources/views/filament/components/order-candidate-detail.blade.php`

### 完了条件

- `admin/wms-order-confirmation-waiting?tab=order` の詳細モーダルで入荷予定日の下に算出理由が表示される
- `php -l` で構文エラーなし

---

## P3: 移動確定待ちモーダル

### 目的

`transfer-candidate-detail.blade.php` の移動出荷日行の下に、算出理由をステップ形式で表示する。

### 修正方針

#### Step 1: viewData追加

`WmsTransferConfirmationWaitingTable.php` の `viewDetail` アクション内の `viewData` に追加:

```php
'leadTimeDays' => $log?->lead_time_days ?? 0,
'originalArrivalDate' => $record->original_arrival_date
    ? \Carbon\Carbon::parse($record->original_arrival_date)->format('Y/m/d（D）')
    : null,
'shiftedDays' => (int) ($details['到着日調整'] ?? 0),
'shiftReasons' => $details['調整理由'] ?? '',
```

#### Step 2: Blade テンプレート修正

`transfer-candidate-detail.blade.php` の左カラム基本情報テーブル内、移動出荷日行の下に算出理由を追加。P1と同じステップ形式のデザインを適用。

### 修正対象ファイル

1. `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsTransferConfirmationWaitingTable.php`
2. `resources/views/filament/components/transfer-candidate-detail.blade.php`

### 完了条件

- `admin/wms-order-confirmation-waiting?tab=transfer` の詳細モーダルで移動出荷日の下に算出理由が表示される
- `php -l` で構文エラーなし

---

## 制約（厳守）

1. DB破壊コマンド禁止（migrate:fresh / refresh / reset / db:wipe）
2. FK使用禁止
3. `calculateArrivalDate()` の計算ロジックは変更しない
4. DB変更なし（既存データのみ利用）
5. モーダルデザインは既存パターンに準拠

## 全体完了条件

1. 4つの詳細モーダル全てで予定日の算出理由がステップ形式で表示される
2. 計算ログがないレコードでは算出理由が非表示
3. `php -l` で全修正ファイルの構文エラーなし
4. `./vendor/bin/pint` でコードスタイル準拠
