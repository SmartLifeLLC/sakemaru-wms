# 入荷予定日の算出理由表示

- **作成日**: 2026-04-04
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260403/20260404-032920-arrival-date-reasoning/`

## 背景・目的

`admin/wms-order-incoming-schedules` の入荷予定詳細モーダルで「予定日」が表示されているが、なぜその日付になったのかの根拠がユーザーにわからない。

現在の `calculateArrivalDate()` は以下4ステップで予定日を算出しており、調整理由（`shift_reasons`）も計算しているが、**UIに表示されていない**。

1. リードタイム取得（`lead_times` テーブル、曜日別）
2. 発注日 + リードタイム = 仮到着日
3. 納品可能曜日チェック（`wms_contractor_warehouse_delivery_days`）→ 前倒し
4. 倉庫休日チェック（`wms_warehouse_calendars`）→ 前倒し

## 現状の実装

### データの保存状況

| データ | 保存先 | 備考 |
|--------|--------|------|
| 最終予定日 | `wms_order_candidates.expected_arrival_date` | 調整後の日付 |
| 調整前日付 | `wms_order_candidates.original_arrival_date` | リードタイムのみ加算 |
| リードタイム日数 | `wms_order_calculation_logs.lead_time_days` | 専用カラム |
| 到着日調整日数 | `wms_order_calculation_logs.calculation_details` JSON内 `到着日調整` | 整数値 |
| 調整理由 | `wms_order_calculation_logs.calculation_details` JSON内 `調整理由` | 例: `"納品可能曜日調整(+2日), 倉庫休日(+1日)"` |

**重要**: 調整理由は既に `calculation_details` JSONに保存済み。DB変更は不要。

### 現在のモーダル表示（incoming-schedule-detail.blade.php）

- 左カラム: 基本情報（商品CD、倉庫、発注先、発注日、**予定日**、入荷日時、担当者）
- 右カラム: 数量カード + 計算情報（計算式、有効在庫、入庫予定、発注点、不足数、仕入単位）

予定日は1行で日付のみ表示。算出理由の表示なし。

### 関連コード

- **算出ロジック**: `app/Services/AutoOrder/OrderCandidateCalculationService.php` L980-1036 `calculateArrivalDate()`
- **計算ログ保存（発注）**: 同ファイル L912-938（`calculation_details` JSON内に `到着日調整`, `調整理由` を保存）
- **計算ログ保存（移動）**: 同ファイル L666-688（同様）
- **モーダルデータ渡し**: `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` の `viewDetail` アクション
- **モーダルテンプレート**: `resources/views/filament/components/incoming-schedule-detail.blade.php`

## 変更内容

### 概要

既に `wms_order_calculation_logs.calculation_details` に保存されている到着日算出情報を、入荷予定詳細モーダルのUIに表示する。DB変更は不要。

### 詳細設計

#### DB変更

なし。既存の `calculation_details` JSON内の以下フィールドを利用:
- `到着日調整`（整数: シフト日数）
- `調整理由`（文字列: カンマ区切りの理由）

#### モデル変更

なし。

#### サービス変更

なし。算出ロジック・保存ロジックは変更不要。

#### UI変更

##### 1. モーダルデータ渡し（WmsOrderIncomingSchedulesTable.php）

`viewDetail` アクションの `viewData` に以下を追加:

```php
'leadTimeDays' => $log?->lead_time_days ?? 0,
'originalArrivalDate' => $candidate?->original_arrival_date
    ? Carbon::parse($candidate->original_arrival_date)->format('Y/m/d')
    : null,
'shiftedDays' => $details['到着日調整'] ?? 0,
'shiftReasons' => $details['調整理由'] ?? '',
```

##### 2. モーダルテンプレート（incoming-schedule-detail.blade.php）

予定日の行の下に、算出理由セクションを追加:

```
予定日    2026/04/07
          発注日(04/04) + リードタイム(1日) = 04/05(日)
          → 納品可能曜日調整(+2日) → 04/07(火)
```

**表示ルール:**
- `$shiftedDays > 0` の場合のみ算出理由を表示
- シフトなし（`$shiftedDays == 0`）の場合は「リードタイム{N}日」のみ表示
- 計算ログがない場合（手動登録等）は何も表示しない

**デザイン:**
- 予定日セルの下に小さいテキスト（`text-xs text-gray-400`）で表示
- 調整がある場合はアイコン（`heroicon-o-information-circle`）付きで目立たせる

### 影響範囲

- `admin/wms-order-incoming-schedules` 詳細モーダルのみ
- 既存データの表示追加のみ。データ保存ロジックへの影響なし
- 計算ログがないレコード（手動登録、旧データ）は従来通り表示

## 制約

- DB破壊コマンド禁止（CLAUDE.md準拠）
- FK使用禁止
- `calculateArrivalDate()` の計算ロジック自体は変更しない
- モーダルデザインは既存の `incoming-schedule-detail.blade.php` パターンに準拠

## 対象ファイル

### 新規作成
なし

### 既存変更
- `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` — viewData に算出情報追加
- `resources/views/filament/components/incoming-schedule-detail.blade.php` — 算出理由の表示追加

### 参照のみ
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — 算出ロジック確認
- `app/Models/WmsOrderCalculationLog.php` — calculation_details 構造確認
- `app/Models/WmsOrderCandidate.php` — original_arrival_date 確認

## 確認事項

1. **移動候補の詳細モーダルにも同様の表示を追加するか？** — 発注確定待ち（`order-candidate-detail.blade.php`）、移動確定待ち（`transfer-candidate-detail.blade.php`）にも同じ情報表示が必要か
追加
2. **入荷完了モーダルにも追加するか？** — `admin/wms-incoming-completed` の詳細モーダルでも算出理由を表示するか
追加
3. **表示フォーマットの詳細** — 「発注日 + LT = 仮日 → 調整理由 → 最終日」のステップ形式 vs シンプルな理由テキストのみ、どちらが望ましいか
ステップ形式
