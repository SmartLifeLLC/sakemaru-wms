# 入荷確定時のピッカー追跡機能 作業計画

## 前提

- `wms_order_incoming_schedules.confirmed_by` に User ID と Picker ID が混在している
- 出荷側は `wms_picking_tasks.picker_id` で明確にピッカーを追跡済み
- 仕様書: `20260404-001451-incoming-picker-tracking.md`
- 確認事項回答: 既存データ移行しない / API時 confirmed_by=NULL / 入荷送信済みページにもカラム追加

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | マイグレーション＋モデル | カラム追加、fillable、リレーション、confirm()変更 | マイグレーション実行成功、php -l 通過 |
| P2 | サービス＋API変更 | IncomingConfirmationService + IncomingController 変更 | php -l 通過、API後方互換維持 |
| P3 | UI（3ページ） | 入荷予定・入荷完了・入荷送信済みにピッカーカラム追加 | php -l 通過、Pint OK |
| P4 | テスト・検証 | php artisan test + Pint | テストパス、Pint OK |

---

## P1: マイグレーション＋モデル

### 目的

`wms_order_incoming_schedules` に `confirmed_picker_id` カラムを追加し、モデルにリレーションとロジックを実装する。

### 修正方針

#### 1. マイグレーション作成

```bash
php artisan make:migration add_confirmed_picker_id_to_wms_order_incoming_schedules_table
```

内容:
```php
$table->unsignedBigInteger('confirmed_picker_id')->nullable()->after('confirmed_by')
    ->comment('入庫確定ピッカーID（API経由の場合）');
```

down():
```php
$table->dropColumn('confirmed_picker_id');
```

#### 2. マイグレーション実行

```bash
php artisan migrate
```

#### 3. モデル変更: `app/Models/WmsOrderIncomingSchedule.php`

- `$fillable` に `'confirmed_picker_id'` 追加
- 新規リレーション追加:
```php
public function confirmedByPicker(): BelongsTo
{
    return $this->belongsTo(WmsPicker::class, 'confirmed_picker_id');
}
```
- `confirm()` メソッドに `?int $pickerId = null` パラメータ追加:
  - `$pickerId` がある場合: `confirmed_picker_id = $pickerId`, `confirmed_by = null`
  - `$pickerId` がない場合: `confirmed_by = $confirmedBy`, `confirmed_picker_id = null`

### 修正対象ファイル

- `database/migrations/XXXX_add_confirmed_picker_id_to_wms_order_incoming_schedules_table.php`（新規）
- `app/Models/WmsOrderIncomingSchedule.php`

### 完了条件

- `php artisan migrate` 成功
- `php -l app/Models/WmsOrderIncomingSchedule.php` エラーなし
- 既存テストに影響なし

---

## P2: サービス＋API変更

### 目的

サービス層とAPIコントローラーで `confirmed_picker_id` を正しくセットするように変更する。

### 修正方針

#### 1. `IncomingConfirmationService` 変更

ファイル: `app/Services/AutoOrder/IncomingConfirmationService.php`

対象メソッド（全て `?int $pickerId = null` パラメータ追加）:
- `confirmIncoming()` — L30: パラメータ追加、内部で `confirmed_picker_id` セット
- `recordPartialIncoming()` — パラメータ追加、内部で `confirmed_picker_id` セット
- `confirmMultiple()` — パラメータ追加、内部で `confirmed_picker_id` セット

ロジック:
```php
// $pickerId がある場合（API経由）
$updateData['confirmed_picker_id'] = $pickerId;
$updateData['confirmed_by'] = null;

// $pickerId がない場合（Web UI経由）
$updateData['confirmed_by'] = $confirmedBy;
$updateData['confirmed_picker_id'] = null;
```

#### 2. `IncomingController` 変更

ファイル: `app/Http/Controllers/Api/IncomingController.php`

`completeWork()` メソッド内:
- L748付近: `confirmIncoming()` 呼び出しに `pickerId: $workItem->picker_id` を追加
- L759付近: `recordPartialIncoming()` 呼び出しに `pickerId: $workItem->picker_id` を追加
- `$confirmedBy` パラメータには `$workItem->picker_id` をそのまま渡す（サービス側で無視される）

### 修正対象ファイル

- `app/Services/AutoOrder/IncomingConfirmationService.php`
- `app/Http/Controllers/Api/IncomingController.php`

### 完了条件

- `php -l` 全ファイルエラーなし
- API後方互換: リクエスト/レスポンスの形式変更なし
- Web UIからの確定: `confirmed_by` にUser ID、`confirmed_picker_id` はnull
- API からの確定: `confirmed_picker_id` にPicker ID、`confirmed_by` はnull

---

## P3: UI（入荷予定・入荷完了・入荷送信済み）

### 目的

3つのリストページにピッカーカラムを追加し、Web/API両方の確定者情報を表示可能にする。

### 修正方針

#### 1. 入荷予定 (`WmsOrderIncomingSchedules`)

**ListPage** (`ListWmsOrderIncomingSchedules.php`):
- eager load に `'confirmedByPicker'` 追加

**Table** (`WmsOrderIncomingSchedulesTable.php`):
- `confirmedByUser.name`（入荷担当者）カラムの後に追加:
```php
TextColumn::make('confirmedByPicker.name')
    ->label('入荷ピッカー')
    ->placeholder('-')
    ->toggleable(isToggledHiddenByDefault: true)
    ->width('100px'),
```

#### 2. 入荷完了 (`WmsIncomingCompleted`)

**ListPage** (`ListWmsIncomingCompleted.php`):
- eager load に `'confirmedByPicker'` 追加

**Table** (`WmsIncomingCompletedTable.php`):
- テーブルカラム: `confirmedByPicker.name` 追加（toggleable hidden）
- 詳細モーダル: ピッカー名を `viewData` に追加
  ```php
  'confirmedByPickerName' => $record->confirmedByPicker?->name ?? '-',
  ```

#### 3. 入荷送信済み (`WmsIncomingTransmitted`)

**ListPage** (`ListWmsIncomingTransmitted.php`):
- eager load に `'confirmedByPicker'` 追加（現在 `modifyQueryUsing` で `->with()` が無い場合は追加）

**Table/Resource**: 入荷送信済みのテーブル定義を確認し、同様にカラム追加

### 修正対象ファイル

- `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php`
- `app/Filament/Resources/WmsOrderIncomingSchedules/Pages/ListWmsOrderIncomingSchedules.php`
- `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php`
- `app/Filament/Resources/WmsIncomingCompleted/Pages/ListWmsIncomingCompleted.php`
- `app/Filament/Resources/WmsIncomingTransmitted/Pages/ListWmsIncomingTransmitted.php`
- 入荷送信済みテーブルファイル（要確認）

### 完了条件

- `php -l` 全ファイルエラーなし
- Pint 通過
- 3ページ全てにピッカーカラムが表示される

---

## P4: テスト・検証

### 目的

全変更の整合性を確認する。

### 手順

```bash
# テスト実行
php artisan test

# コードフォーマット
./vendor/bin/pint

# 構文チェック（変更ファイル全て）
php -l app/Models/WmsOrderIncomingSchedule.php
php -l app/Services/AutoOrder/IncomingConfirmationService.php
php -l app/Http/Controllers/Api/IncomingController.php
php -l app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php
php -l app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php
```

### 完了条件

- `php artisan test` パス（JxServer関連の既存失敗は無関係）
- `./vendor/bin/pint --test` パス
- マイグレーション正常実行済み

---

## 制約（厳守）

1. **FK禁止**: `confirmed_picker_id` にFKを張らない
2. **migrate:fresh/refresh 禁止**: `php artisan migrate` のみ使用
3. **既存データ不変**: 既存レコードの `confirmed_by` は変更しない
4. **API後方互換**: リクエスト/レスポンスの形式を変更しない
5. **confirmed_by**: API経由の場合は NULL にする（システムユーザーIDは使わない）
6. **表示互換**: 既存の `confirmedByUser.name` 表示は維持する

## 全体完了条件

- マイグレーション実行済み
- API経由の入荷確定で `confirmed_picker_id` にPicker IDが入る
- Web UI経由の入荷確定で `confirmed_by` にUser IDが入る
- 入荷予定・入荷完了・入荷送信済みの3ページにピッカーカラムが表示される
- テスト・Pint パス
