# Work Plan: modal-design-audit

- **ID**: modal-design-audit
- **作成日**: 2026-04-22
- **最終更新**: 2026-04-22
- **ステータス**: 完了
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/20260422/20260422-024713-modal-design-audit/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260422-024713-modal-design-audit-boot.md）
2. 20260422-024713-modal-design-audit-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

`~/.claude/design-knowledge/modal-design.md` のモーダルデザイン仕様に非準拠の34件のモーダルを、カテゴリ（A→B→C→D）ごとに段階的に修正する。各カテゴリ完了後にヘッドレスブラウザテスト + 手動チェック用リスト出力。

## 重要な設計制約

1. **機能変更禁止** — UIデザイン修正のみ。ロジック・DB変更なし
2. **独自CSSクラス維持** — `picker-assign-modal`、`proxy-shipment-modal` は `incoming-detail-modal` に変更しない（独自スタイル維持）
3. **requiresConfirmation() モーダルはFilamentデフォルト維持** — ただし `extraModalWindowAttributes` は追加可
4. **テストデータ系は対象外** — `TestDataGenerator.php`, `JxTestData.php`

## 対象ファイル

### 既存変更（25ファイル・34モーダル）

| # | ファイル | 修正数 | メニューパス |
|---|---------|--------|-------------|
| 1 | `app/Filament/Concerns/HasExportAction.php` | 1 | （全テーブル共通） |
| 2 | `app/Filament/Resources/DeliveryCourseChangeResource.php` | 1 | 出荷管理 > 配送コース変更 |
| 3 | `app/Filament/Resources/RealStocks/Tables/RealStocksTable.php` | 1 | 在庫管理 > 在庫管理 |
| 4 | `app/Filament/Resources/WarehouseStockTransferDeliveryCourses/Pages/ListWarehouseStockTransferDeliveryCourses.php` | 2 | 倉庫マスタ > 移動配送コース設定 |
| 5 | `app/Filament/Resources/Waves/Pages/ListWaves.php` | 2 | 出荷管理 > 出荷波動管理 |
| 6 | `app/Filament/Resources/WmsAutoOrderExecutionLogs/Tables/WmsAutoOrderExecutionLogsTable.php` | 2 | ログ > 自動発注実行ログ |
| 7 | `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` | 1 | 発注処理 > 発注・移動候補生成 |
| 8 | `app/Filament/Resources/WmsAutoOrderJobControls/Tables/WmsAutoOrderJobControlsTable.php` | 1 | 発注処理 > 発注・移動候補生成 |
| 9 | `app/Filament/Resources/WmsIncomingReceivedData/Pages/ListWmsIncomingReceivedData.php` | 2 | 入荷管理 > 入荷データ受信 |
| 10 | `app/Filament/Resources/WmsIncomingReceivedData/Tables/WmsIncomingReceivedDataTable.php` | 1 | 入荷管理 > 入荷データ受信 |
| 11 | `app/Filament/Resources/WmsIncomingTransmitted/Tables/WmsIncomingTransmittedTable.php` | 1 | 入荷管理 > 仕入連携済み |
| 12 | `app/Filament/Resources/WmsMonthlySafetyStocks/Pages/ListWmsMonthlySafetyStocks.php` | 2 | 発注マスタ > 月別発注点 |
| 13 | `app/Filament/Resources/WmsOrderConfirmed/Tables/WmsOrderConfirmedTable.php` | 1 | 発注履歴 > 発注確定済み |
| 14 | `app/Filament/Resources/WmsOrderDataFiles/Tables/WmsOrderDataFilesTable.php` | 1 | 発注履歴 > 発注データファイル |
| 15 | `app/Filament/Resources/WmsOrderIncomingSchedules/Pages/ListWmsOrderIncomingSchedules.php` | 1 | 入荷管理 > 入荷予定 |
| 16 | `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` | 1 | 入荷管理 > 入荷予定 |
| 17 | `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingItemEdits.php` | 1 | 出荷管理 > ピッキングタスク |
| 18 | `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingWaitings.php` | 2 | 出荷管理 > ピッキングタスク |
| 19 | `app/Filament/Resources/WmsQueueJobs/Tables/WmsQueueJobsTable.php` | 1 | ログ > Queueジョブ |
| 20 | `app/Filament/Resources/WmsShortageAllocations/Pages/ListFinishedWmsShortageAllocations.php` | 1 | 倉庫移動 > 横持ち出荷依頼 |
| 21 | `app/Filament/Resources/WmsShortageAllocations/Tables/WmsShortageAllocationsTable.php` | 1 | 倉庫移動 > 横持ち出荷依頼 |
| 22 | `app/Filament/Resources/WmsShortages/Tables/WmsShortagesTable.php` | 2 | 欠品管理 > 欠品一覧 |
| 23 | `app/Filament/Resources/WmsShortagesApproved/Tables/WmsShortagesApprovedTable.php` | 1 | 欠品管理 > 欠品承認済み |
| 24 | `app/Filament/Resources/WmsShortagesWaitingApprovals/Tables/WmsShortagesWaitingApprovalsTable.php` | 2 | 欠品管理 > 承認待ち欠品 |
| 25 | `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php` | 2 | 発注処理 > 移動候補一覧 |

### 参照のみ（変更禁止）

| ファイル | 参照理由 |
|---------|---------|
| `~/.claude/design-knowledge/modal-design.md` | デザイン仕様 |
| `resources/css/filament/admin/theme.css` | CSS定義確認 |

## 独自CSSクラスのマッピング

| CSSクラス | 使用ファイル | 決定 |
|----------|-------------|------|
| `picker-assign-modal` | `ListWmsPickingWaitings.php`, `WmsShortagesTable.php`, `WmsShortagesWaitingApprovalsTable.php` | **維持**（`incoming-detail-modal` に変更しない） |
| `proxy-shipment-modal` | `WmsShortageAllocationsTable.php`, `WmsShortagesWaitingApprovalsTable.php`, `WmsShortagesTable.php` | **維持**（`incoming-detail-modal` に変更しない） |

## テスト環境

- URL: https://wms.sakemaru.test
- 認証: `.env` の `TEST_ADMIN_NAME` / `TEST_ADMIN_PASS`
- テスト方法: 各カテゴリ完了後に修正モーダルのメニューパスを一覧出力 → ユーザーが手動チェック

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: カテゴリA — 新規作成モーダル修正 | 完了 | 2026-04-22 | toggleAutoOrder に extraModalWindowAttributes 追加 |
| P2: カテゴリB — 表示専用モーダル修正 | 完了 | 2026-04-22 | 8件修正 + Alignment import追加 |
| P3: カテゴリC — フォームモーダル修正 | 完了 | 2026-04-22 | 18件修正。独自CSS維持(picker/wave/bulk-update) |
| P4: カテゴリD — 欠品・横持ち出荷系モーダル修正 | 完了 | 2026-04-22 | 7件修正。proxy-shipment-modal維持 |
| P5: 最終確認・チェックリスト出力 | 完了 | 2026-04-22 | checklist.md出力。25ファイル全PASS |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 修正実績（各Phase完了後に記入）
- P1完了後: (実施後に記入)
- P2完了後: (実施後に記入)
- P3完了後: (実施後に記入)
- P4完了後: (実施後に記入)

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: main

---

## Phase完了記録

### P1: カテゴリA — 新規作成モーダル修正
- 完了日: -
- 対象: `WmsStockTransferCandidatesTable.php` の `toggleAutoOrder`
- 実績:
  - (完了後に記入)

### P2: カテゴリB — 表示専用モーダル修正
- 完了日: -
- 対象: 8件の表示専用モーダル
- 実績:
  - (完了後に記入)

### P3: カテゴリC — フォームモーダル修正
- 完了日: -
- 対象: 18件のフォームモーダル
- 実績:
  - (完了後に記入)

### P4: カテゴリD — 欠品・横持ち出荷系モーダル修正
- 完了日: -
- 対象: 7件の欠品・横持ち出荷系モーダル
- 実績:
  - (完了後に記入)

### P5: 最終確認・チェックリスト出力
- 完了日: -
- 成果物: 全34件の修正確認チェックリスト（メニューパス付き）
- 実績:
  - (完了後に記入)
