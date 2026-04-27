# モーダルデザイン統一 作業計画

## 前提

- 仕様書 `20260422-024713-modal-design-audit.md` で34件の非準拠モーダルを特定済み
- ユーザー確認済み: HasExportAction統一OK、独自CSS維持、カテゴリ段階的、ヘッドレスブラウザテスト必須
- デザイン仕様: `~/.claude/design-knowledge/modal-design.md`

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | カテゴリA修正 | 新規作成の `toggleAutoOrder` 1件 | `php -l` PASS + モーダル属性追加確認 |
| P2 | カテゴリB修正 | 表示専用モーダル8件 | `php -l` 全PASS + 修正リスト出力 |
| P3 | カテゴリC修正 | フォームモーダル18件 | `php -l` 全PASS + 修正リスト出力 |
| P4 | カテゴリD修正 | 欠品・横持ち出荷系7件 | `php -l` 全PASS + 修正リスト出力 |
| P5 | 最終チェックリスト | 全34件の修正確認リスト出力 | チェックリストMD出力 |

---

## P1: カテゴリA — 新規作成モーダル修正（1件）

### 目的

今回新規作成した `toggleAutoOrder` アクション（`requiresConfirmation()` 付き確認モーダル）にデザイン属性を追加。

### 修正対象

| # | ファイル | アクション | 修正内容 |
|---|---------|----------|---------|
| A1 | `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php:541` | `toggleAutoOrder` | `extraModalWindowAttributes` 追加 |

### 修正方針

`requiresConfirmation()` モーダルなので、`extraModalWindowAttributes(['class' => 'incoming-detail-modal'])` のみ追加。`modalFooterActionsAlignment` やsubmit/cancelラベル変更は不要。

### 修正パターン

```php
->requiresConfirmation()
->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
// 既存の requiresConfirmation() はそのまま保持
```

### 完了条件

1. `php -l` で構文エラーなし
2. `extraModalWindowAttributes` が追加されていること

---

## P2: カテゴリB — 表示専用モーダル修正（8件）

### 目的

表示専用（view/infolist）モーダルにデザイン仕様を適用。submitボタンなし、閉じるボタンのみ。

### 修正対象

| # | ファイル | アクション | メニューパス | 不足 |
|---|---------|----------|-------------|------|
| B1 | `WmsAutoOrderExecutionLogsTable.php:126` | `viewTransmission` | ログ > 自動発注実行ログ | both |
| B2 | `WmsAutoOrderExecutionLogsTable.php:150` | `viewError` | ログ > 自動発注実行ログ | both |
| B3 | `WmsAutoOrderJobControlsTable.php:147` | `viewResult` | 発注処理 > 発注・移動候補生成 | both |
| B4 | `WmsOrderConfirmedTable.php:175` | `viewDetail` | 発注履歴 > 発注確定済み | extra のみ |
| B5 | `WmsIncomingReceivedDataTable.php:130` | `viewSlips` | 入荷管理 > 入荷データ受信 | both |
| B6 | `WmsQueueJobsTable.php:134` | `viewDetail` | ログ > Queueジョブ | both |
| B7 | `DeliveryCourseChangeResource.php:364` | `viewDetails` | 出荷管理 > 配送コース変更 | both |
| B8 | `RealStocksTable.php:104` | `view` | 在庫管理 > 在庫管理 | both |

### 修正パターン（表示専用）

```php
->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
->modalSubmitAction(false)
->modalCancelActionLabel('閉じる')
```

**B4 (`WmsOrderConfirmedTable.php`) は `extraModalWindowAttributes` のみ追加**（`modalFooterActionsAlignment` は設定済み）。

### 修正手順

1. 仕様書の行番号を参考に各ファイルの該当アクションを特定
2. 既存の属性を確認（一部は `modalSubmitAction(false)` や `modalCancelActionLabel('閉じる')` が設定済みの可能性）
3. 不足している属性のみ追加（重複追加しない）
4. `php -l` で各ファイルの構文チェック

### 完了条件

1. 8ファイルすべて `php -l` PASS
2. 修正リスト（メニューパス + アクション名）を出力

---

## P3: カテゴリC — フォームモーダル修正（18件）

### 目的

フォーム付き操作モーダルにデザイン仕様を適用。送信ボタンを赤色 + 具体的動詞ラベル、キャンセルは「〜せず閉じる」形式。

### 修正対象

| # | ファイル | アクション | メニューパス |
|---|---------|----------|-------------|
| C1 | `HasExportAction.php:19` | `export` | （全テーブル共通） |
| C2 | `ListWarehouseStockTransferDeliveryCourses.php:25` | `create` | 倉庫マスタ > 移動配送コース設定 |
| C3 | `ListWarehouseStockTransferDeliveryCourses.php:139` | `edit` | 倉庫マスタ > 移動配送コース設定 |
| C4 | `ListWaves.php:97` | `printPickingList` | 出荷管理 > 出荷波動管理 |
| C5 | `ListWaves.php:258` | `generateWave` | 出荷管理 > 出荷波動管理 |
| C6 | `ListWmsAutoOrderJobControls.php:393` | `orderGenerationWizard` | 発注処理 > 発注・移動候補生成 |
| C7 | `ListWmsIncomingReceivedData.php:32` | `uploadJxFile` | 入荷管理 > 入荷データ受信 |
| C8 | `ListWmsIncomingReceivedData.php:88` | `uploadCsvFile` | 入荷管理 > 入荷データ受信 |
| C9 | `ListWmsOrderIncomingSchedules.php:199` | `uploadCsv` | 入荷管理 > 入荷予定 |
| C10 | `WmsOrderIncomingSchedulesTable.php:733` | `bulkUpdateDates` | 入荷管理 > 入荷予定 |
| C11 | `WmsOrderDataFilesTable.php:317` | `sendMail` | 発注履歴 > 発注データファイル |
| C12 | `ListWmsMonthlySafetyStocks.php:36` | `importCsv` | 発注マスタ > 月別発注点 |
| C13 | `ListWmsMonthlySafetyStocks.php:54` | `importAnalysisCsv` | 発注マスタ > 月別発注点 |
| C14 | `ListWmsPickingWaitings.php:45` | `assignPickers` | 出荷管理 > ピッキングタスク |
| C15 | `ListWmsPickingWaitings.php:232` | `unassignPickers` | 出荷管理 > ピッキングタスク |
| C16 | `WmsStockTransferCandidatesTable.php:676` | `bulkUpdateCourseAndDate` | 発注処理 > 移動候補一覧 |
| C17 | `WmsIncomingTransmittedTable.php:222` | `viewDetail` | 入荷管理 > 仕入連携済み |
| C18 | `ListWmsPickingItemEdits.php:118` | `picking_ready` | 出荷管理 > ピッキングタスク |

### 修正パターン（フォームモーダル）

```php
->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('具体的動詞')->color('danger'))
->modalCancelActionLabel('〜せず閉じる')
```

### 各アクションのラベル設計

| # | アクション | submitラベル | cancelラベル |
|---|----------|-------------|-------------|
| C1 | export | `エクスポート` | `エクスポートせず閉じる` |
| C2 | create | `作成` | `作成せず閉じる` |
| C3 | edit | `更新` | `更新せず閉じる` |
| C4 | printPickingList | `印刷` | `印刷せず閉じる` |
| C5 | generateWave | `生成` | `生成せず閉じる` |
| C6 | orderGenerationWizard | ※ウィザード形式 — 既存submit構造を維持、extra+alignment追加のみ | - |
| C7 | uploadJxFile | `アップロード` | `アップロードせず閉じる` |
| C8 | uploadCsvFile | `アップロード` | `アップロードせず閉じる` |
| C9 | uploadCsv | `アップロード` | `アップロードせず閉じる` |
| C10 | bulkUpdateDates | `一括更新` | `更新せず閉じる` |
| C11 | sendMail | `送信` | `送信せず閉じる` |
| C12 | importCsv | `インポート` | `インポートせず閉じる` |
| C13 | importAnalysisCsv | `インポート` | `インポートせず閉じる` |
| C14 | assignPickers | `割当` | `割当せず閉じる` |
| C15 | unassignPickers | `解除` | `解除せず閉じる` |
| C16 | bulkUpdateCourseAndDate | `一括更新` | `更新せず閉じる` |
| C17 | viewDetail | ※表示系 — `modalSubmitAction(false)` + `閉じる` | `閉じる` |
| C18 | picking_ready | `出庫準備完了` | `出庫準備せず閉じる` |

### 特記事項

- **C1 (HasExportAction)**: 全テーブル共通トレイト。`incoming-detail-modal` クラスを追加する（ユーザー確認済み）
- **C6 (orderGenerationWizard)**: ウィザード形式のため、`modalSubmitAction` のカスタマイズは避ける。`extraModalWindowAttributes` と `modalFooterActionsAlignment` のみ追加
- **C14/C15 (picker)**: `picker-assign-modal` 独自クラスを維持。`incoming-detail-modal` には変更しない。`modalFooterActionsAlignment` のみ追加
- **C17 (viewDetail)**: カテゴリCに分類されているが実際は表示専用。パターンBの修正パターンを適用

### 修正手順

1. 各ファイルの該当行を確認し、既存属性を把握
2. 不足属性を追加（独自CSSクラスのファイルは `incoming-detail-modal` を追加しない）
3. ラベルは上記テーブルに従う
4. `php -l` で各ファイルの構文チェック

### 完了条件

1. 全ファイル `php -l` PASS
2. 修正リスト（メニューパス + アクション名 + ラベル）を出力

---

## P4: カテゴリD — 欠品・横持ち出荷系モーダル修正（7件）

### 目的

欠品管理・横持ち出荷関連モーダルにデザイン仕様を適用。独自CSSクラスを維持しつつ、不足属性を追加。

### 修正対象

| # | ファイル | アクション | メニューパス | 独自CSS |
|---|---------|----------|-------------|---------|
| D1 | `WmsShortagesTable.php:315` | `createProxyShipment` | 欠品管理 > 欠品一覧 | `proxy-shipment-modal` → 維持 |
| D2 | `WmsShortagesTable.php:698` | `viewProxyShipment` | 欠品管理 > 欠品一覧 | `proxy-shipment-modal` → 維持 |
| D3 | `WmsShortagesApprovedTable.php:211` | `viewProxyShipment` | 欠品管理 > 欠品承認済み | — |
| D4 | `WmsShortagesWaitingApprovalsTable.php:232` | `editProxyShipment` | 欠品管理 > 承認待ち欠品 | `proxy-shipment-modal` → 維持 |
| D5 | `WmsShortagesWaitingApprovalsTable.php:587` | `viewProxyShipment` | 欠品管理 > 承認待ち欠品 | `proxy-shipment-modal` → 維持 |
| D6 | `WmsShortageAllocationsTable.php:279` | `addPartialShipment` | 倉庫移動 > 横持ち出荷依頼 | `proxy-shipment-modal` → 維持 |
| D7 | `ListFinishedWmsShortageAllocations.php:242` | `syncAllocations` | 倉庫移動 > 横持ち出荷依頼 | — |

### 修正パターン

**独自CSSクラスのファイル（D1,D2,D4,D5,D6）:**
- `extraModalWindowAttributes` は変更しない（既存の `proxy-shipment-modal` を維持）
- `modalFooterActionsAlignment(Alignment::End)` を追加

**独自CSSなしのファイル（D3,D7）:**

D3 (viewProxyShipment — 表示専用):
```php
->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
->modalSubmitAction(false)
->modalCancelActionLabel('閉じる')
```

D7 (syncAllocations — フォーム):
```php
// D7は `modalFooterActionsAlignment` のみ不足
->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
```

### 各アクションのラベル設計

| # | アクション | タイプ | submitラベル | cancelラベル |
|---|----------|--------|-------------|-------------|
| D1 | createProxyShipment | フォーム | `作成` | `作成せず閉じる` |
| D2 | viewProxyShipment | 表示 | `false` | `閉じる` |
| D3 | viewProxyShipment | 表示 | `false` | `閉じる` |
| D4 | editProxyShipment | フォーム | `更新` | `更新せず閉じる` |
| D5 | viewProxyShipment | 表示 | `false` | `閉じる` |
| D6 | addPartialShipment | フォーム | `追加` | `追加せず閉じる` |
| D7 | syncAllocations | フォーム | 既存維持 | 既存維持 |

### 完了条件

1. 全ファイル `php -l` PASS
2. 修正リスト（メニューパス + アクション名）を出力

---

## P5: 最終確認・チェックリスト出力

### 目的

全34件の修正を確認し、ユーザーの手動チェック用にメニューパス付きチェックリストを出力。

### 手順

1. 全25ファイルに対し `php -l` を実行（最終構文チェック）
2. 修正済みモーダルのチェックリストをMarkdown形式で出力:
   - メニューパス
   - アクション名
   - モーダルタイプ（表示/フォーム/確認）
   - 適用CSSクラス
   - submitラベル / cancelラベル

### 出力フォーマット

```markdown
## 修正済みモーダル チェックリスト

| # | メニューパス | アクション | タイプ | CSSクラス | submit | cancel | チェック |
|---|-------------|----------|--------|----------|--------|--------|---------|
| 1 | 発注処理 > 移動候補一覧 | toggleAutoOrder | 確認 | incoming-detail-modal | (default) | (default) | [ ] |
| 2 | ... | ... | ... | ... | ... | ... | [ ] |
```

### 完了条件

1. 全25ファイル `php -l` PASS
2. チェックリストMD出力
3. ユーザーに手動チェック依頼

---

## 制約（厳守）

1. **機能変更禁止** — UIデザイン修正のみ
2. **独自CSSクラス維持** — `picker-assign-modal`, `proxy-shipment-modal` は変更しない
3. **requiresConfirmation() デフォルト維持** — `extraModalWindowAttributes` のみ追加
4. **テストデータ系は対象外** — `TestDataGenerator.php`, `JxTestData.php`
5. **ウィザード形式のsubmitは変更しない** — `orderGenerationWizard` は `extra` + `alignment` のみ

## 全体完了条件

1. 全34件のモーダルが仕様準拠
2. 全25ファイル `php -l` PASS
3. 手動チェック用チェックリスト出力済み
