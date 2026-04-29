# モーダルデザイン統一 監査・修正仕様書

- **作成日**: 2026-04-22
- **ステータス**: ドラフト
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/20260422/20260422-024713-modal-design-audit/

## 背景・目的

`~/.claude/design-knowledge/modal-design.md` にモーダルデザイン統一仕様が定義されているが、多くのモーダルが仕様に準拠していない。今回新規作成した「発注OFF」モーダル（`WmsStockTransferCandidatesTable.php` の `toggleAutoOrder`）も含め、全34件の非準拠モーダルを特定し、順次修正する。

## デザイン仕様（要約）

`~/.claude/design-knowledge/modal-design.md` より:

### 必須設定

1. **`extraModalWindowAttributes(['class' => 'incoming-detail-modal'])`** — 紺色ヘッダー
2. **`modalFooterActionsAlignment(Alignment::End)`** — フッターボタン右寄せ
3. **フォームモーダル**: `modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('具体的動詞')->color('danger'))` + `modalCancelActionLabel('〜せず閉じる')`
4. **表示モーダル**: `modalSubmitAction(false)` + `modalCancelActionLabel('閉じる')`

### 例外（修正不要）

- `requiresConfirmation()` モーダル — Filamentデフォルトでよい
- テストデータ系（`TestDataGenerator.php`, `JxTestData.php`）— 本番外

---

## 非準拠モーダル一覧（34件）

### カテゴリA: 今回新規作成で未対応（1件）

| # | ファイル | アクション名 | タイプ | 不足 |
|---|---------|------------|--------|------|
| A1 | `WmsStockTransferCandidatesTable.php:541` | `toggleAutoOrder` | 確認 | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |

### カテゴリB: 表示専用モーダル（view/infolist）（8件）

| # | ファイル | アクション名 | 不足 |
|---|---------|------------|------|
| B1 | `WmsAutoOrderExecutionLogsTable.php:126` | `viewTransmission` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| B2 | `WmsAutoOrderExecutionLogsTable.php:150` | `viewError` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| B3 | `WmsAutoOrderJobControlsTable.php:147` | `viewResult` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| B4 | `WmsOrderConfirmedTable.php:175` | `viewDetail` | `extraModalWindowAttributes` のみ |
| B5 | `WmsIncomingReceivedDataTable.php:130` | `viewSlips` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| B6 | `WmsQueueJobsTable.php:134` | `viewDetail` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| B7 | `DeliveryCourseChangeResource.php:364` | `viewDetails` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| B8 | `RealStocksTable.php:104` | `view` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |

### カテゴリC: フォームモーダル（操作あり）（18件）

| # | ファイル | アクション名 | 不足 |
|---|---------|------------|------|
| C1 | `HasExportAction.php:19` | `export` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C2 | `ListWarehouseStockTransferDeliveryCourses.php:25` | `create` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C3 | `ListWarehouseStockTransferDeliveryCourses.php:139` | `edit` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C4 | `ListWaves.php:97` | `printPickingList` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C5 | `ListWaves.php:258` | `generateWave` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C6 | `ListWmsAutoOrderJobControls.php:393` | `orderGenerationWizard` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C7 | `ListWmsIncomingReceivedData.php:32` | `uploadJxFile` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C8 | `ListWmsIncomingReceivedData.php:88` | `uploadCsvFile` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C9 | `ListWmsOrderIncomingSchedules.php:199` | `uploadCsv` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C10 | `WmsOrderIncomingSchedulesTable.php:733` | `bulkUpdateDates` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C11 | `WmsOrderDataFilesTable.php:317` | `sendMail` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C12 | `ListWmsMonthlySafetyStocks.php:36` | `importCsv` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C13 | `ListWmsMonthlySafetyStocks.php:54` | `importAnalysisCsv` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C14 | `ListWmsPickingWaitings.php:45` | `assignPickers` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C15 | `ListWmsPickingWaitings.php:232` | `unassignPickers` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C16 | `WmsStockTransferCandidatesTable.php:676` | `bulkUpdateCourseAndDate` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C17 | `WmsIncomingTransmittedTable.php:222` | `viewDetail` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| C18 | `ListWmsPickingItemEdits.php:118` | `picking_ready` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |

### カテゴリD: 欠品・横持ち出荷系モーダル（7件）

| # | ファイル | アクション名 | 不足 |
|---|---------|------------|------|
| D1 | `WmsShortagesTable.php:315` | `createProxyShipment` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| D2 | `WmsShortagesTable.php:698` | `viewProxyShipment` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| D3 | `WmsShortagesApprovedTable.php:211` | `viewProxyShipment` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| D4 | `WmsShortagesWaitingApprovalsTable.php:232` | `editProxyShipment` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| D5 | `WmsShortagesWaitingApprovalsTable.php:587` | `viewProxyShipment` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| D6 | `WmsShortageAllocationsTable.php:279` | `addPartialShipment` | `extraModalWindowAttributes`, `modalFooterActionsAlignment` |
| D7 | `ListFinishedWmsShortageAllocations.php:242` | `syncAllocations` | `modalFooterActionsAlignment` のみ |

---

## 変更内容

### 概要

34件の非準拠モーダルに対し、モーダルデザイン仕様に基づく統一修正を適用する。DB変更・モデル変更・サービス変更なし。UIのみの修正。

### 修正パターン

#### パターン1: 表示専用モーダル（カテゴリB）
```php
->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
->modalSubmitAction(false)
->modalCancelActionLabel('閉じる')
```

#### パターン2: フォームモーダル（カテゴリC,D）
```php
->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('具体的動詞')->color('danger'))
->modalCancelActionLabel('〜せず閉じる')
```

#### パターン3: 確認モーダル（カテゴリA — requiresConfirmation付き）
```php
->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
// requiresConfirmation() はそのまま保持
```

### 影響範囲

見た目のみの変更。機能・ロジックへの影響なし。

---

## 制約

1. **機能変更禁止** — デザイン修正のみ
2. **requiresConfirmation() モーダルはFilamentデフォルト維持** — ただし `extraModalWindowAttributes` は追加可
3. **テストデータ系は対象外** — `TestDataGenerator.php`, `JxTestData.php`
4. **HasExportAction は慎重に** — 全テーブルに影響するトレイト

## 対象ファイル

### 既存変更

| # | ファイル | 修正モーダル数 |
|---|---------|-------------|
| 1 | `app/Filament/Concerns/HasExportAction.php` | 1 |
| 2 | `app/Filament/Resources/DeliveryCourseChangeResource.php` | 1 |
| 3 | `app/Filament/Resources/RealStocks/Tables/RealStocksTable.php` | 1 |
| 4 | `app/Filament/Resources/WarehouseStockTransferDeliveryCourses/Pages/ListWarehouseStockTransferDeliveryCourses.php` | 2 |
| 5 | `app/Filament/Resources/Waves/Pages/ListWaves.php` | 2 |
| 6 | `app/Filament/Resources/WmsAutoOrderExecutionLogs/Tables/WmsAutoOrderExecutionLogsTable.php` | 2 |
| 7 | `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` | 1 |
| 8 | `app/Filament/Resources/WmsAutoOrderJobControls/Tables/WmsAutoOrderJobControlsTable.php` | 1 |
| 9 | `app/Filament/Resources/WmsIncomingReceivedData/Pages/ListWmsIncomingReceivedData.php` | 2 |
| 10 | `app/Filament/Resources/WmsIncomingReceivedData/Tables/WmsIncomingReceivedDataTable.php` | 1 |
| 11 | `app/Filament/Resources/WmsIncomingTransmitted/Tables/WmsIncomingTransmittedTable.php` | 1 |
| 12 | `app/Filament/Resources/WmsMonthlySafetyStocks/Pages/ListWmsMonthlySafetyStocks.php` | 2 |
| 13 | `app/Filament/Resources/WmsOrderConfirmed/Tables/WmsOrderConfirmedTable.php` | 1 |
| 14 | `app/Filament/Resources/WmsOrderDataFiles/Tables/WmsOrderDataFilesTable.php` | 1 |
| 15 | `app/Filament/Resources/WmsOrderIncomingSchedules/Pages/ListWmsOrderIncomingSchedules.php` | 1 |
| 16 | `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` | 1 |
| 17 | `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingItemEdits.php` | 1 |
| 18 | `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingWaitings.php` | 2 |
| 19 | `app/Filament/Resources/WmsQueueJobs/Tables/WmsQueueJobsTable.php` | 1 |
| 20 | `app/Filament/Resources/WmsShortageAllocations/Pages/ListFinishedWmsShortageAllocations.php` | 1 |
| 21 | `app/Filament/Resources/WmsShortageAllocations/Tables/WmsShortageAllocationsTable.php` | 1 |
| 22 | `app/Filament/Resources/WmsShortages/Tables/WmsShortagesTable.php` | 2 |
| 23 | `app/Filament/Resources/WmsShortagesApproved/Tables/WmsShortagesApprovedTable.php` | 1 |
| 24 | `app/Filament/Resources/WmsShortagesWaitingApprovals/Tables/WmsShortagesWaitingApprovalsTable.php` | 2 |
| 25 | `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php` | 2 |

### 参照のみ

| ファイル | 参照理由 |
|---------|---------|
| `~/.claude/design-knowledge/modal-design.md` | デザイン仕様 |
| `resources/css/filament/admin/theme.css` | CSS定義確認 |

## 確認事項（回答済み）

| # | 項目 | 決定 |
|---|------|------|
| 1 | HasExportAction | **統一する** — `incoming-detail-modal` クラスを追加。全テーブルに影響するが問題なし |
| 2 | ピッカー割当モーダル | **独自クラス維持** — `picker-assign-modal` をそのまま残し、`incoming-detail-modal` には変更しない |
| 3 | 横持ち出荷モーダル | **独自クラス維持** — `proxy-shipment-modal` をそのまま残し、`incoming-detail-modal` には変更しない |
| 4 | 優先順位 | **カテゴリごとに段階的** — A→B→C→D の順で修正。各カテゴリ完了後にヘッドレスブラウザテスト |

## テスト方針

- **ヘッドレスブラウザテスト**: https://wms.sakemaru.test を使用
- **認証情報**: `.env` の `TEST_ADMIN_NAME` / `TEST_ADMIN_PASS` を使用
- **各カテゴリ完了後**: 修正したモーダルのメニュー名・ナビゲーションパスを一覧表示（ユーザーが手動チェック予定）
