# Work Plan: warehouse-dashboard

- **ID**: warehouse-dashboard
- **作成日**: 2026-03-14
- **最終更新**: 2026-03-14
- **ステータス**: 進行中
- **ディレクトリ**: `storage/specifications/20260314/20260314-083822-warehouse-dashboard/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260314-083822-warehouse-dashboard-boot.md）
2. 20260314-083822-warehouse-dashboard-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

`/admin` トップページを倉庫別ダッシュボードにリニューアル。ユーザーの `default_warehouse_id` で自動フィルタし、横持ち出荷依頼・入荷予定・サマリーカード・ピッキング待ちを当日データで表示する。

## 重要な設計制約

- **FK禁止**: 全リレーションはアプリケーションレベル
- **`migrate:fresh` / `migrate:refresh` 禁止**: 共有DB
- **DB変更なし**: 新規テーブル/カラム追加不要（既存データのみ参照）
- **既存ダッシュボード温存**: 出荷/入荷ダッシュボードはナビゲーションに残す
- **Filament 4パターン準拠**: `Filament\Schemas\Components\Section` 等の正しいインポートパス

## 対象ファイル

### 新規作成
- `app/Filament/Pages/Dashboard.php` — 新ダッシュボードページ（Filament Page + Livewire）
- `resources/views/filament/pages/dashboard.blade.php` — ダッシュボードBlade

### 既存変更
- `app/Providers/Filament/AdminPanelProvider.php` — デフォルトダッシュボードを自前Dashboardに差し替え

### 参照のみ（変更禁止）
- `app/Models/WmsShortageAllocation.php` — 横持ちデータ取得
- `app/Models/WmsOrderIncomingSchedule.php` — 入荷予定データ取得
- `app/Models/Sakemaru/User.php` — `default_warehouse_id` 取得
- `app/Models/Sakemaru/Warehouse.php` — 倉庫一覧取得
- `app/Enums/AutoOrder/IncomingScheduleStatus.php` — ステータスバッジ色
- `app/Enums/AutoOrder/OrderSource.php` — 発注元ラベル
- `app/Filament/Widgets/WmsOutboundOverview.php` — Stats集計パターン参考
- `app/Filament/Widgets/PendingTasksWidget.php` — 既にwarehouseId対応済み（変更不要）

## テストデータ

- 当日データが無い場合はダッシュボードに「データなし」が表示されるだけなので、テストデータ不要
- `php artisan wms:generate-test-data` で既存テストデータ生成可能

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: Dashboard Page + AdminPanel設定 | 未着手 | - | Filament Page作成、AdminPanel差し替え |
| P2: サマリーカード実装 | 未着手 | - | 5種のStatsカード |
| P3: 横持ち出荷依頼テーブル | 未着手 | - | 当日分テーブル + リンク |
| P4: 入荷予定テーブル | 未着手 | - | 当日分テーブル + リンク |
| P5: ピッキング待ちタスク統合 | 未着手 | - | PendingTasksWidget埋め込み |
| P6: UI調整・動作確認 | 未着手 | - | レスポンシブ、空データ表示 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### AdminPanel 現状（P1開始前に確認済み）
- `AdminPanelProvider.php` L46: `Dashboard::class`（Filamentデフォルト）が登録済み
- `PendingTasksWidget` は既に `$warehouseId` プロパティを持つ（L14）
- 既存ウィジェット: `AccountWidget`, `FilamentInfoWidget` がデフォルト登録

### Git ブランチ
- 作業ブランチ: (P1開始時に作成)
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: Dashboard Page + AdminPanel設定
- 完了日: -
- 実績:
  - (完了後に記入)

### P2: サマリーカード実装
- 完了日: -
- 実績:
  - (完了後に記入)

### P3: 横持ち出荷依頼テーブル
- 完了日: -
- 実績:
  - (完了後に記入)

### P4: 入荷予定テーブル
- 完了日: -
- 実績:
  - (完了後に記入)

### P5: ピッキング待ちタスク統合
- 完了日: -
- 実績:
  - (完了後に記入)

### P6: UI調整・動作確認
- 完了日: -
- 実績:
  - (完了後に記入)
