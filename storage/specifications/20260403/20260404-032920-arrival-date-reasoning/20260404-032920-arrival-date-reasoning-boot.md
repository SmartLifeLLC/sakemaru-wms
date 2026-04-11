# Work Plan: arrival-date-reasoning

- **ID**: arrival-date-reasoning
- **作成日**: 2026-04-04
- **最終更新**: 2026-04-04
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260403/20260404-032920-arrival-date-reasoning/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260404-032920-arrival-date-reasoning-boot.md）
2. 20260404-032920-arrival-date-reasoning-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

入荷予定日の算出理由（リードタイム、納品曜日調整、倉庫休日シフト）を全詳細モーダル（入荷予定・入荷完了・発注確定待ち・移動確定待ち）にステップ形式で表示する。DB変更なし。

## 重要な設計制約

- DB破壊コマンド禁止（migrate:fresh / refresh / reset / db:wipe）
- FK使用禁止
- `calculateArrivalDate()` の計算ロジック自体は変更しない
- 既存の `calculation_details` JSON内データ（`到着日調整`, `調整理由`）を利用する
- モーダルデザインは既存パターンに準拠

## 対象ファイル

### 新規作成
なし

### 既存変更
1. `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` — viewData追加
2. `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php` — viewData追加
3. `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsOrderConfirmationWaitingTable.php` — viewData追加
4. `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsTransferConfirmationWaitingTable.php` — viewData追加
5. `resources/views/filament/components/incoming-schedule-detail.blade.php` — 算出理由UI追加
6. `resources/views/filament/components/order-candidate-detail.blade.php` — 算出理由UI追加
7. `resources/views/filament/components/transfer-candidate-detail.blade.php` — 算出理由UI追加

### 参照のみ（変更禁止）
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — 算出ロジック確認
- `app/Models/WmsOrderCalculationLog.php` — calculation_details構造確認

## テストデータ

既存データで確認可能:
- `admin/wms-order-incoming-schedules` — 入荷予定詳細モーダル
- `admin/wms-incoming-completed` — 入荷完了詳細モーダル
- `admin/wms-order-confirmation-waiting?tab=order` — 発注確定待ち詳細モーダル
- `admin/wms-order-confirmation-waiting?tab=transfer` — 移動確定待ち詳細モーダル

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: 入荷予定・入荷完了モーダル | 完了 | 2026-04-04 | viewData追加+Blade更新、transferCandidate対応も追加 |
| P2: 発注確定待ちモーダル | 完了 | 2026-04-04 | viewData追加+Blade更新、リードタイム行を統合 |
| P3: 移動確定待ちモーダル | 完了 | 2026-04-04 | viewData追加+Blade更新 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### calculation_details JSON構造
- `到着日調整`: 整数（シフト日数、0=調整なし）
- `調整理由`: 文字列（カンマ区切り、例: "納品可能曜日調整(+2日), 倉庫休日(+1日)"）

### 既存viewData（入荷予定/入荷完了で共通）
- 同じBladeテンプレート `incoming-schedule-detail.blade.php` を使用
- 現時点で `leadTimeDays`, `originalArrivalDate`, `shiftedDays`, `shiftReasons` は渡していない
- `$log` (WmsOrderCalculationLog) と `$details` (calculation_details) は既にロード済み

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: main

---

## Phase完了記録

### P1: 入荷予定・入荷完了モーダル
- 完了日: 2026-04-04
- 実績:
  - WmsOrderIncomingSchedulesTable.php: transferCandidate対応追加、viewData 4項目追加
  - WmsIncomingCompletedTable.php: 同上
  - incoming-schedule-detail.blade.php: 予定日行にステップ形式の算出理由表示追加

### P2: 発注確定待ちモーダル
- 完了日: 2026-04-04
- 実績:
  - WmsOrderConfirmationWaitingTable.php: viewData 4項目追加（orderDate, originalArrivalDate, shiftedDays, shiftReasons）
  - order-candidate-detail.blade.php: 入荷予定日行にステップ形式表示、リードタイム行を統合

### P3: 移動確定待ちモーダル
- 完了日: 2026-04-04
- 実績:
  - WmsTransferConfirmationWaitingTable.php: viewData 5項目追加
  - transfer-candidate-detail.blade.php: 移動出荷日行にステップ形式表示追加
