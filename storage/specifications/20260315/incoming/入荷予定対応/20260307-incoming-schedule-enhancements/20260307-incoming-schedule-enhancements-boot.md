# Work Plan: incoming-schedule-enhancements

- **ID**: incoming-schedule-enhancements
- **作成日**: 2026-03-07
- **最終更新**: 2026-03-07
- **ステータス**: 進行中
- **ディレクトリ**: `storage/specifications/incoming/入荷予定対応/20260307-incoming-schedule-enhancements/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260307-incoming-schedule-enhancements-boot.md）
2. 20260307-incoming-schedule-enhancements-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

入荷予定に出荷実績数量（shipped_quantity）・単価管理（unit_price/partner_unit_price）を追加し、アクト中食CSV対応パーサーと取込エラー管理機能を実装する。

## 重要な設計制約

- **FK禁止**: すべてのリレーションはアプリケーション層で管理
- **migrate:fresh/refresh 禁止**: 新規マイグレーションの追加のみ許可
- **core側コードへの依存禁止**: 単価取得はDB直接参照（`PurchasePriceService`）
- **sakemaru コネクション使用**: すべてのWMSテーブルは `sakemaru` DB接続
- **N+1クエリ回避**: 商品マッピング・単価取得はプリロード必須
- **WmsModel 継承**: 新規WMSモデルは `WmsModel` を継承する

## 対象ファイル

### 新規作成

| ファイル | 内容 |
|---------|------|
| `database/migrations/xxxx_add_shipped_qty_and_prices_to_incoming_schedules.php` | カラム追加 |
| `database/migrations/xxxx_create_wms_incoming_import_errors.php` | エラーテーブル |
| `app/Models/WmsIncomingImportError.php` | エラーモデル |
| `app/Services/AutoOrder/PurchasePriceService.php` | 自社単価取得サービス |
| `app/Services/AutoOrder/IncomingParsers/ActCsvIncomingParser.php` | アクト中食CSVパーサー |
| `app/Filament/Resources/WmsIncomingImportErrorResource.php` | エラーリストResource |
| `app/Filament/Resources/WmsIncomingImportError/Pages/` | エラーリスト画面 |
| `app/Filament/Resources/WmsIncomingImportError/Tables/` | エラーリストテーブル |

### 既存変更

| ファイル | 変更内容 |
|---------|---------|
| `app/Models/WmsOrderIncomingSchedule.php` | fillable/casts に shipped_quantity + 5単価カラム追加 |
| `app/Services/AutoOrder/OrderExecutionService.php` | 発注確定時に unit_price/case_price 設定 |
| `app/Services/AutoOrder/IncomingReceiveService.php` | 照合時に shipped_quantity/partner_unit_price 書き戻し + エラー記録 |
| `app/Http/Controllers/Api/IncomingController.php` | `formatScheduleDetail()` にフィールド追加 |
| `app/Services/AutoOrder/OrderDataFileService.php` | CSVの伝票番号をDB上の`slip_number`に変更 |
| `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` | カラム追加 |
| `app/Filament/Resources/WmsIncomingReceivedData/Pages/ListWmsIncomingReceivedData.php` | CSVアップロードアクション追加 |

### 参照のみ（変更禁止）

| ファイル | 参照理由 |
|---------|---------|
| `app/Contracts/IncomingFormatParserInterface.php` | CSVパーサーが実装するインターフェース |
| `app/Services/AutoOrder/IncomingParsers/JxIncomingParser.php` | 実装パターン参照 |
| `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php` | 既存の単価取得ロジック参照 |
| `storage/specifications/incoming/入荷予定対応/1497アクト中食sample/` | CSVサンプルデータ |

## テストデータ

- CSVサンプル: `storage/specifications/incoming/入荷予定対応/1497アクト中食sample/` に3ファイル
  - `ny93420260218114750.csv` (3行), `ny93420260218133827.csv` (1行), `ny93420260218133858.csv` (12行)
  - Shift_JIS, 65カラム, カンマ区切り, ヘッダーあり

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: DB変更（マイグレーション） | 完了 | 2026-03-07 | |
| P2: モデル変更 | 完了 | 2026-03-07 | |
| P3: 自社単価取得サービス | 完了 | 2026-03-07 | |
| P4: 発注確定時の単価設定 | 完了 | 2026-03-07 | |
| P5: 出荷実績照合ロジック拡張 | 完了 | 2026-03-07 | |
| P6: アクト中食CSVパーサー | 完了 | 2026-03-07 | |
| P7: Handy API拡張 | 完了 | 2026-03-07 | |
| P8: 発注データCSV伝票番号修正 | 完了 | 2026-03-07 | |
| P9: Filament UI変更 | 完了 | 2026-03-07 | |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### マイグレーションファイル名（P1完了）
- カラム追加: `2026_03_07_010001_add_shipped_qty_and_prices_to_incoming_schedules.php`
- エラーテーブル: `2026_03_07_010002_create_wms_incoming_import_errors.php`

### 単価サービス検証結果（P3完了）
- 4段階フォールバック実装済み: PARTNER → PARTNER_PRICE_GROUP → PARTNER_PRICE_GROUP2 → ITEM_PRICE
- preloadPrices() でN+1回避

### CSVパーサー検証結果（P6完了）
- サンプルCSVカラム確定: col0=得意先CD, col1=伝票日付, col2=伝票No, col4=行No, col6=商品CD, col7=商品名, col11=入数, col13=ケース数, col15=数量, col19=売価単価, col29=JAN, col57=発注仕入先CD

### Git ブランチ
- 作業ブランチ: release/v1.0（直接）
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: DB変更
- 完了日: 2026-03-07
- 実績:
  - shipped_quantity, unit_price, case_price, partner_unit_price, partner_case_price, price_type 追加
  - wms_incoming_import_errors テーブル新規作成

### P2: モデル変更
- 完了日: 2026-03-07
- 実績:
  - WmsOrderIncomingSchedule: fillable/casts に6カラム追加
  - WmsIncomingImportError: 新規モデル作成（WmsModel継承）

### P3: 自社単価取得サービス
- 完了日: 2026-03-07
- 実績:
  - PurchasePriceService 新規作成（4段階フォールバック + プリロード対応）

### P4: 発注確定時の単価設定
- 完了日: 2026-03-07
- 実績:
  - OrderExecutionService: createIncomingSchedulesFromCandidate / createManualIncomingSchedule に unit_price/case_price 追加

### P5: 出荷実績照合ロジック拡張
- 完了日: 2026-03-07
- 実績:
  - 3段階商品マッピング実装（ordering_code → item_search_information → ITEM_NOT_FOUND）
  - shipped_quantity / partner_unit_price / partner_case_price / price_type 書き戻し
  - PRICE_MISMATCH / ITEM_NOT_FOUND / SLIP_NOT_FOUND エラー記録

### P6: アクト中食CSVパーサー
- 完了日: 2026-03-07
- 実績:
  - ActCsvIncomingParser 新規作成（IncomingFormatParserInterface実装）
  - item_connections によるpartner_item_code→item_idマッピング
  - ITEM_NOT_FOUND エラー記録対応

### P7: Handy API拡張
- 完了日: 2026-03-07
- 実績:
  - formatScheduleDetail() に shipped_quantity, unit_price, partner_unit_price 追加

### P8: 発注データCSV伝票番号修正
- 完了日: 2026-03-07
- 実績:
  - buildCsvContent() の伝票番号をDB上のslip_number（11桁）に変更
  - 明細行番号を伝票単位の連番に変更

### P9: Filament UI変更
- 完了日: 2026-03-07
- 実績:
  - WmsOrderIncomingSchedulesTable: shipped_quantity, unit_price, partner_unit_price, price_mismatch カラム追加
  - ListWmsIncomingReceivedData: CSVデータ取込アクション追加
  - WmsIncomingImportErrorResource: 新規Resource（エラーリスト画面）
  - EMenu: WMS_INCOMING_IMPORT_ERRORS 追加
