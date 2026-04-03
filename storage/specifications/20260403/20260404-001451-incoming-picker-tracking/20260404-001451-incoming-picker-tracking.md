# 入荷確定時のピッカー追跡機能追加

- **作成日**: 2026-04-04
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260403/20260404-001451-incoming-picker-tracking/`

## 背景・目的

### 現状の問題

`wms_order_incoming_schedules.confirmed_by` カラムに、2種類の異なるIDが混在している:

| 操作元 | `confirmed_by` に入る値 | 実際の参照先 |
|--------|----------------------|-------------|
| Web UI (Filament) | `auth()->id()` | `sakemaru.users.id` |
| Android API | `$workItem->picker_id` | `wms_pickers.id` |

モデルのリレーション `confirmedByUser()` は `User::class` のみを参照しているため、API経由で確定されたレコードは担当者名が正しく表示されない。また、入荷確定が「管理者（Web）」によるものか「ピッカー（Android）」によるものか区別できない。

### 出荷（Picking）との比較

出荷側では `wms_picking_tasks.picker_id` カラムで `wms_pickers` を明確に参照しており、Web/APIの区別が可能。入荷側にも同等の追跡機能が必要。

## 現状の実装

### DB: `wms_order_incoming_schedules`

```
confirmed_at    DATETIME    nullable  -- 入庫確定日時
confirmed_by    BIGINT      nullable  -- 入庫確定者ID（User IDまたはPicker IDが混在）
```

### モデル: `WmsOrderIncomingSchedule`

```php
// app/Models/WmsOrderIncomingSchedule.php:128-131
public function confirmedByUser(): BelongsTo
{
    return $this->belongsTo(User::class, 'confirmed_by');
}
```

### サービス: `IncomingConfirmationService`

```php
// app/Services/AutoOrder/IncomingConfirmationService.php:30-37
public function confirmIncoming(
    WmsOrderIncomingSchedule $schedule,
    int $confirmedBy,           // ← User IDまたはPicker IDが混在
    ?int $receivedQuantity = null,
    ?string $actualDate = null,
    ?string $expirationDate = null,
    ?int $locationId = null
): WmsOrderIncomingSchedule
```

### API: `IncomingController`

```php
// app/Http/Controllers/Api/IncomingController.php:748
$this->confirmationService->confirmIncoming(
    $schedule,
    $workItem->picker_id,  // ← Picker IDをconfirmed_byとして渡している
    $totalReceived,
    ...
);
```

### Web UI: `WmsOrderIncomingSchedulesTable`

```php
// app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php:622
$service->confirmIncoming(
    $record,
    auth()->id(),  // ← User IDをconfirmed_byとして渡している
    ...
);
```

### 参考パターン: Picking（出荷）

```php
// app/Models/WmsPickingTask.php:233-236
public function picker(): BelongsTo
{
    return $this->belongsTo(WmsPicker::class, 'picker_id');
}
```

## 変更内容

### 概要

`wms_order_incoming_schedules` に `confirmed_picker_id` カラムを追加し、API（Android）経由の入荷確定時にピッカーIDを記録する。Web UIでは従来通り `confirmed_by` に User ID を記録する。リスト画面で両方の情報を表示可能にする。

### 詳細設計

#### DB変更

**マイグレーション**: `wms_order_incoming_schedules` にカラム追加

```php
$table->unsignedBigInteger('confirmed_picker_id')->nullable()->after('confirmed_by')
    ->comment('入庫確定ピッカーID（API経由の場合）');
```

**変更後のカラム構成**:

| カラム | 型 | 用途 |
|--------|---|------|
| `confirmed_by` | BIGINT nullable | Web UI確定者（`sakemaru.users.id`）。API経由の場合はnull |
| `confirmed_picker_id` | BIGINT nullable | API確定ピッカー（`wms_pickers.id`）。Web経由の場合はnull |
| `confirmed_at` | DATETIME nullable | 確定日時（変更なし） |

#### モデル変更

**`WmsOrderIncomingSchedule`**:

```php
// fillableに追加
'confirmed_picker_id',

// 新規リレーション追加
public function confirmedByPicker(): BelongsTo
{
    return $this->belongsTo(WmsPicker::class, 'confirmed_picker_id');
}

// confirm() メソッド — pickerIdパラメータ追加
public function confirm(int $confirmedBy, ?string $actualDate = null, ?int $pickerId = null): void
{
    $this->update([
        'status' => IncomingScheduleStatus::CONFIRMED,
        'confirmed_at' => now(),
        'confirmed_by' => $pickerId ? null : $confirmedBy,
        'confirmed_picker_id' => $pickerId,
        'actual_arrival_date' => $actualDate ?? now()->format('Y-m-d'),
        'received_quantity' => $this->expected_quantity,
    ]);
}
```

#### サービス変更

**`IncomingConfirmationService`**:
- `confirmIncoming()` に `?int $pickerId = null` パラメータ追加
- `recordPartialIncoming()` に `?int $pickerId = null` パラメータ追加
- `confirmMultiple()` に `?int $pickerId = null` パラメータ追加
- 内部で `confirmed_by` と `confirmed_picker_id` を排他的にセット:
  - `$pickerId` がある場合: `confirmed_picker_id = $pickerId`, `confirmed_by = null`
  - `$pickerId` がない場合: `confirmed_by = $confirmedBy`, `confirmed_picker_id = null`

#### API変更

**`IncomingController`**:
- `completeWork()` メソッド:
  - `confirmIncoming()` 呼び出し時に `pickerId: $workItem->picker_id` を渡す
  - `recordPartialIncoming()` 呼び出し時に `pickerId: $workItem->picker_id` を渡す
  - `confirmed_by` パラメータには `0`（システム）または適切なデフォルト値を渡す

#### UI変更

**入荷予定テーブル (`WmsOrderIncomingSchedulesTable`)**:
- `confirmedByPicker.name` カラム追加（入荷ピッカー）— toggleable hidden
- Web UI確定時: 従来通り `auth()->id()` を `confirmedBy` に、`pickerId` は null

**入荷完了テーブル (`WmsIncomingCompletedTable`)**:
- `confirmedByPicker.name` カラム追加（入荷ピッカー）— toggleable hidden
- 詳細モーダルに入荷ピッカー名を表示追加

**入荷予定/入荷完了の ListPage**:
- eager load に `confirmedByPicker` 追加

### 確定者表示ロジック

テーブル・モーダルでの表示:

| カラム | 表示内容 |
|--------|---------|
| 入荷担当者 (`confirmedByUser.name`) | Web UIで確定した管理者名。API経由はnull（`-`表示） |
| 入荷ピッカー (`confirmedByPicker.name`) | APIで確定したピッカー名。Web経由はnull（`-`表示） |

### 影響範囲

- 入荷予定ページ (`/admin/wms-order-incoming-schedules`) — テーブル・確定アクション
- 入荷完了ページ (`/admin/wms-incoming-completed`) — テーブル・詳細モーダル
- 入荷送信済みページ (`/admin/wms-incoming-transmitted`) — 影響なし（入荷確定前のため）
- 入荷API (`/api/incoming/*`) — completeWork エンドポイント
- 既存データ: `confirmed_by` に入っているPicker IDは区別不能のまま残る（移行不要、新規データから正しくセットされる）

## 制約

- **FK禁止**: `confirmed_picker_id` にFKを張らない
- **migrate:fresh/refresh 禁止**: カラム追加のみ
- **既存データ不変**: 既存レコードの `confirmed_by` は変更しない
- **表示互換**: 既存の `confirmedByUser.name` 表示は維持する
- **API後方互換**: APIのリクエスト/レスポンス形式は変更しない

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_add_confirmed_picker_id_to_wms_order_incoming_schedules_table.php`

### 既存変更
- `app/Models/WmsOrderIncomingSchedule.php` — fillable, リレーション追加, confirm()メソッド変更
- `app/Services/AutoOrder/IncomingConfirmationService.php` — pickerId パラメータ追加
- `app/Http/Controllers/Api/IncomingController.php` — completeWork で pickerId を渡す
- `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` — ピッカーカラム追加
- `app/Filament/Resources/WmsOrderIncomingSchedules/Pages/ListWmsOrderIncomingSchedules.php` — eager load追加
- `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php` — ピッカーカラム追加, モーダル変更
- `app/Filament/Resources/WmsIncomingCompleted/Pages/ListWmsIncomingCompleted.php` — eager load追加

### 参照のみ
- `app/Models/WmsPicker.php` — リレーション先
- `app/Models/WmsPickingTask.php` — picker_id パターンの参考
- `app/Models/WmsIncomingWorkItem.php` — picker_id の流れの参考

## 確認事項

1. **既存データの移行**: `confirmed_by` に既にPicker IDが入っているレコードを `confirmed_picker_id` に移行する必要はあるか？（`wms_incoming_work_items` テーブルから逆引き可能だが、データ量とリスクを考慮）
移行しない。
2. **confirmed_by の扱い**: API経由の場合、`confirmed_by` を null にするか、システムユーザーID（0等）を入れるか？
NULLでよい。
3. **入荷送信済みページ**: ピッカーカラムの追加は不要か？（TRANSMITTED状態では入荷確定前のため通常不要）
必要。
