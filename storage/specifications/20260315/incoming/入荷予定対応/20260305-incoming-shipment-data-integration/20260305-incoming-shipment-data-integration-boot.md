# Work Plan: incoming-shipment-data-integration

- **ID**: incoming-shipment-data-integration
- **作成日**: 2026-03-05
- **最終更新**: 2026-03-05
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/incoming/入荷予定対応/20260305-incoming-shipment-data-integration/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260305-incoming-shipment-data-integration-boot.md）
2. 20260305-incoming-shipment-data-integration-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

入荷予定データ受信・照合機能の構築。2段階で実装:
1. **Phase 1（P1-P4）**: 伝票番号（`slip_number`）を入荷予定に必須化し、JXファイル生成と連携
2. **Phase 2（P5-P10）**: JX/CSV受信データの取り込み、伝票番号ベースの照合、欠品判定、確認画面

## 重要な設計制約

- FK禁止（アプリケーション層でリレーション管理）
- `migrate:fresh` / `migrate:refresh` 禁止
- 既存の入庫フロー（Handy/Web手動）に影響を与えない
- Filament 4パターン準拠
- `slip_number` はユニーク制約
- 受信データは自動適用しない（担当者確認必須）
- 受信データは全項目を保存
- 欠品判定は×マークではなく伝票番号照合+数量比較

## JX納品伝票仕様（照合の基盤知識）

- 128バイト固定長、Shift_JIS
- FINETラッパー: 先頭`1`行 + 末尾`8`行（`JxDataWrapper`で付与/除去）
- **Bレコード[4-14]: 伝票番号（11桁）** = 発注時にWMSが生成した番号と同一 → 照合キー
- Dレコード[51-56]: 自社コード = items.code → 商品照合
- Dレコード[63-69]/[70-76]: ケース数/バラ数 → 出荷数量（0なら欠品）
- 伝票区分: 01=発注, 02=納品
- サンプルデータ: `incoming-samples/jx-data/` に4社分+新システムreal_data.txt

## 対象ファイル

### 新規作成（Phase 1: P1-P4）
- `database/migrations/xxxx_add_slip_number_to_wms_order_incoming_schedules.php`

### 既存変更（Phase 1: P1-P4）
- `app/Models/WmsOrderIncomingSchedule.php` — fillable追加、`generateSlipNumber()` 追加
- `app/Services/AutoOrder/OrderExecutionService.php` — 3箇所の入荷予定生成に `slip_number` セット
- `app/Services/AutoOrder/TransferCandidateExecutionService.php` — 移動確定に `slip_number` セット
- `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php` — 確定後はDB値参照
- `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` — カラム追加
- `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php` — カラム追加

### 新規作成（Phase 2: P5-P10）
- `database/migrations/xxxx_create_wms_incoming_received_tables.php`（3テーブル）
- `database/migrations/xxxx_add_receive_columns_to_wms_contractor_settings.php`
- `app/Models/WmsIncomingReceivedFile.php`
- `app/Models/WmsIncomingReceivedSlip.php`
- `app/Models/WmsIncomingReceivedDetail.php`
- `app/Contracts/IncomingFormatParserInterface.php`
- `app/Services/AutoOrder/IncomingParsers/JxIncomingParser.php`
- `app/Services/AutoOrder/IncomingReceiveService.php`
- `app/Console/Commands/AutoOrder/IncomingReceiveScheduledCommand.php`
- `app/Filament/Resources/WmsIncomingReceivedData/` — リソース一式

### 参照のみ（変更禁止）
- `app/Services/AutoOrder/IncomingConfirmationService.php`
- `app/Services/AutoOrder/IncomingTransmissionService.php`
- `app/Services/JX/JxDataWrapper.php`（参照。受信時にhasHeader/hasFooter使用）

## テストデータ

- 既存入荷予定データは全クリア済み（バックフィル不要）
- JXサンプル: `incoming-samples/jx-data/real_data.txt`（新システム、FINETラッパーあり）
- JXサンプル: `incoming-samples/jx-data/1330-mitsubishi-samples/` 等（旧システム、ラッパーなし）
- `php artisan tinker` で採番ロジック確認

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: マイグレーション＆モデル | ✅完了 | 2026-03-05 | slip_numberカラム追加、採番メソッド |
| P2: 全生成箇所に slip_number 適用 | ✅完了 | 2026-03-05 | OrderExecutionService, TransferCandidateExecutionService |
| P3: JXファイル生成で保存済み伝票番号使用 | ✅完了 | 2026-03-05 | HanaOrderJXFileGenerator |
| P4: UI表示＆検証 | ✅完了 | 2026-03-05 | Filamentテーブル、手動入荷予定フォーム |
| P5: 受信データテーブル＆モデル | ✅完了 | 2026-03-05 | 3層テーブル（files/slips/details） |
| P6: JX受信パーサー | ✅完了 | 2026-03-05 | 128バイト固定長パース |
| P7: 照合サービス | ✅完了 | 2026-03-05 | slip_numberベース照合、欠品判定 |
| P8: 受信データ確認画面 | ✅完了 | 2026-03-05 | Filamentリソース、照合・適用アクション |
| P9: 発注先受信設定 | ✅完了 | 2026-03-05 | 受信カラム追加、フォームUI |
| P10: スケジューラー連携 | ✅完了 | 2026-03-05 | 自動受信コマンド、スケジューラー登録 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 伝票番号フォーマット（P1完了後に確定）
- フォーマット: `{YYYYMMDD}-{連番5桁}` 例: `20260305-00001`
- DB: VARCHAR(20), UNIQUE

### 入荷予定生成箇所（調査済み）
1. `OrderExecutionService::createIncomingSchedulesFromCandidate()` L166, L187 — 自動発注
2. `OrderExecutionService::createManualIncomingSchedule()` L259 — 手動発注
3. `TransferCandidateExecutionService::createIncomingSchedule()` L205 — 移動確定

### JX伝票番号の現在の生成ロジック
- `HanaOrderJXFileGenerator::generateBRecord()` L337: 動的生成 `YYYYMMDD + seq3桁`
- 確定前=従来通り動的、確定後=DB保存値を使用

### JX受信データ構造（解析済み）
- 1レコード(FINET header) → Aレコード → B+Dレコード群 → 8レコード(FINET footer)
- Bレコード[4-14]: 伝票番号11桁（照合キー）
- Dレコード: 品名[6-37], JAN[38-50], 自社CD[51-56], 入数[57-62], ケース[63-69], バラ[70-76]
- 欠品: ケース0+バラ0（×マークでは判定しない）

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: マイグレーション＆モデル
- 完了日: 2026-03-05
- 実績:
  - `2026_03_05_010001_add_slip_number_to_wms_order_incoming_schedules.php` 作成・実行完了
  - `WmsOrderIncomingSchedule.php` に fillable `slip_number` 追加、`generateSlipNumber()` メソッド追加

### P2: 全生成箇所に slip_number 適用
- 完了日: 2026-03-05
- 実績:
  - `OrderExecutionService::createIncomingSchedulesFromCandidate()` L166, L187 に slip_number 追加
  - `OrderExecutionService::createManualIncomingSchedule()` L259 に slip_number 追加
  - `TransferCandidateExecutionService::createIncomingSchedule()` L205 に slip_number 追加

### P3: JXファイル生成で保存済み伝票番号使用
- 完了日: 2026-03-05
- 実績:
  - `HanaOrderJXFileGenerator::generateBRecord()` に `?string $slipNumber` 引数追加
  - `generateFileContent()` 内で order_candidate_id → incoming_schedule.slip_number をDB取得して渡す
  - DB値がある場合はハイフン除去して11桁に変換

### P4: UI表示＆検証
- 完了日: 2026-03-05
- 実績:
  - `WmsOrderIncomingSchedulesTable.php` に `slip_number` カラム追加（searchable, copyable）
  - `WmsIncomingCompletedTable.php` に `slip_number` カラム追加（searchable, copyable）
  - 全ファイル構文チェックOK

### P5: 受信データテーブル＆モデル
- 完了日: 2026-03-05
- 実績:
  - 3テーブル作成: `wms_incoming_received_files`, `slips`, `details`
  - 3モデル作成: `WmsIncomingReceivedFile`, `Slip`, `Detail`
  - a_created_date カラム幅修正（6→8桁）

### P6: JX受信パーサー
- 完了日: 2026-03-05
- 実績:
  - `IncomingFormatParserInterface` 作成
  - `JxIncomingParser` 作成（128バイト固定長SJIS→UTF8パース）
  - A/B/Dレコード共に送信レイアウトと同一と判明（受信仕様書の位置情報は不正確）
  - D record: 品名64バイト（仕様書の32バイトは誤り）
  - A record: processing_date 8桁（仕様書の6桁send_receive_type+date は誤り）
  - real_data.txt（FINETあり）: 394伝票・2360明細パース成功
  - 旧システムサンプル（FINETなし）: 34伝票・55明細パース成功

### P7: 照合サービス
- 完了日: 2026-03-05
- 実績:
  - `IncomingReceiveService` 作成: `parseJxData()`, `matchWithSchedules()`, `applyMatched()`
  - slip_numberベース照合、欠品判定、適用ロジック実装
  - 構文チェックOK

### P8: 受信データ確認画面
- 完了日: 2026-03-05
- 実績:
  - EMenu に `WMS_INCOMING_RECEIVED_DATA` 追加（入荷管理カテゴリ、sort=5）
  - `WmsIncomingReceivedDataResource.php` 作成（slug: `wms-incoming-received-data`）
  - `ListWmsIncomingReceivedData.php` 作成（AdvancedTables対応、プリセットビュー4種）
  - `WmsIncomingReceivedDataTable.php` 作成（ファイル一覧、照合・適用レコードアクション）
  - ヘッダーアクション: JXデータ取込（FileUpload → parseJxData）
  - レコードアクション: 伝票一覧（モーダル明細テーブル）、照合、適用
  - `incoming-received-detail-table.blade.php` 作成（明細テーブルビュー）
  - 全ファイル構文チェックOK

### P9: 発注先受信設定
- 完了日: 2026-03-05
- 実績:
  - `2026_03_05_030001_add_receive_columns_to_wms_contractor_settings.php` 作成・実行完了
  - `WmsContractorSetting.php` に fillable/casts 10項目追加、`shouldReceiveOn()` メソッド追加
  - `WmsContractorSettingForm.php` に「入荷データ受信設定」セクション追加（受信ON/OFF、形式、時刻、曜日）
  - 全ファイル構文チェックOK

### P10: スケジューラー連携
- 完了日: 2026-03-05
- 実績:
  - `IncomingReceiveScheduledCommand.php` 作成（`wms:incoming-receive-scheduled`）
  - JX GetDocument → パース → 照合 → ConfirmDocument のフロー実装
  - 当日受信済みチェック（重複受信防止）
  - `routes/console.php` にスケジューラー登録（5分間隔、onOneServer、withoutOverlapping）
  - スケジュール一覧表に追記
  - 全ファイル構文チェックOK
