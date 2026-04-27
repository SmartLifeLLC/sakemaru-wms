# Work Plan: update-ordering-features

- **ID**: update-ordering-features
- **作成日**: 2026-04-23
- **最終更新**: 2026-04-23
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260423/20260423-072155-update-ordering-features/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260423-072155-update-ordering-features-boot.md）
2. 20260423-072155-update-ordering-features-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

発注候補・移動候補・発注データファイル画面の6つのサブタスク（ラベル修正、発注点編集、発注CD変更、バルク操作）を段階的に実装する。

## 重要な設計制約

- **FK禁止**: 外部キー制約を作成しない
- **migrate:fresh/refresh 禁止**: `php artisan migrate` のみ使用
- **二重送信禁止**: `mail_sent_at` が非nullのレコードへの再送信をブロック
- **マスタ変更範囲限定**: 発注点変更は `wms_monthly_safety_stocks` の当月レコードのみ。`item_contractors.safety_stock` は直接変更しない
- **発注CD変更はスナップショットのみ**: `item_search_information.is_used_for_ordering` フラグは変更しない
- **JX送信先は is_mail_order=false**: JX/FTP送信タイプはデフォルト手動送信扱い
- **Filament 4 パターン**: `Filament\Actions\Action`、`Filament\Schemas\Components\Section/Grid` を使用

## 対象ファイル

### 新規作成
- `database/migrations/2026_04_23_141544_add_ordering_code_to_wms_stock_transfer_candidates_table.php`
- `database/migrations/2026_04_23_141544_add_is_mail_order_to_wms_order_data_files_table.php`

### 既存変更
- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php`
- `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php`
- `app/Filament/Resources/WmsOrderDataFiles/Tables/WmsOrderDataFilesTable.php`
- `app/Filament/Resources/Contractors/Schemas/ContractorForm.php`
- `app/Models/WmsOrderDataFile.php`
- `app/Models/WmsStockTransferCandidate.php`
- `app/Services/AutoOrder/OrderTransmissionService.php`
- `app/Services/AutoOrder/TransferCreateJobHandler.php`
- `app/Services/AutoOrder/OrderCreateJobHandler.php`
- `app/Services/AutoOrder/PurchaseOrderPdfService.php`

### 参照のみ（変更禁止）
- `app/Enums/AutoOrder/OriginType.php`
- `app/Models/WmsMonthlySafetyStock.php`
- `app/Models/Sakemaru/ItemSearchInformation.php`
- `app/Models/WmsContractorSetting.php`
- `app/Services/AutoOrder/OrderCandidateCalculationService.php`
- `resources/views/filament/components/order-candidate-detail.blade.php`
- `resources/views/filament/components/transfer-candidate-detail.blade.php`

## テストデータ

```bash
php artisan wms:generate-test-data  # テストデータ生成
```

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: マイグレーション | 完了 | 2026-04-23 | ordering_code + is_mail_order + バックフィル |
| P1: ラベル修正 | 完了 | 2026-04-23 | origin_type formatStateUsing追加 + 検索CD→発注CD |
| P2: 発注点編集機能 | 完了 | 2026-04-23 | 両テーブルに発注点フィールド + wms_monthly_safety_stocks更新 |
| P3: 発注CD変更機能 | 完了 | 2026-04-23 | 両テーブルにセレクト + ordering_code/search_code更新 |
| P4: 発注データファイルバルク操作 | 完了 | 2026-04-23 | 送信方式カラム + バルクアクション3種 + helperText |
| P5: データ生成ロジック更新 | 完了 | 2026-04-23 | is_mail_order設定 + ordering_code保存 |

---

## 作業中コンテキスト

### 確認済み仕様決定（2026-04-23）
- 発注点変更: 当月分のみ（`wms_monthly_safety_stocks` の `month=現在月`）
- FAX一括: 1つのPDFにマージ（PurchaseOrderPdfService.generateBulk）
- CSV一括: 1つのファイルにまとめる（ヘッダー行は最初のファイルのみ）
- 一括送信時の通信欄: 空（何も書かない）
- 発注CD変更時の再計算: 不要
- is_mail_order: 既存レコードは更新しない（データ生成時のみ設定）

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P0: マイグレーション
- 完了日: 2026-04-23
- 実績:
  - `2026_04_23_141544_add_ordering_code_to_wms_stock_transfer_candidates_table.php` 作成・実行
  - `2026_04_23_141544_add_is_mail_order_to_wms_order_data_files_table.php` 作成・実行
  - WmsStockTransferCandidate モデルに `ordering_code` fillable追加
  - WmsOrderDataFile モデルに `is_mail_order` fillable/casts追加
  - バックフィル完了

### P1: ラベル修正
- 完了日: 2026-04-23
- 実績:
  - WmsOrderCandidatesTable: origin_type に `formatStateUsing(fn(OriginType $state): string => $state->label())` 追加
  - origin_type フィルターを明示的な日本語ラベルオプションに変更
  - WmsStockTransferCandidatesTable: search_code ラベルを「検索CD」→「発注CD」に変更

### P2: 発注点編集機能
- 完了日: 2026-04-23
- 実績:
  - 発注候補: viewCalculation モーダルに safety_stock フィールド追加（Grid 4カラム化）
  - 発注候補: 保存時に wms_monthly_safety_stocks を updateOrCreate で更新
  - 移動候補: edit モーダルに safety_stock フィールド追加（Grid 4カラム化）
  - 移動候補: contractor_id が null の場合はマスタ更新スキップ
  - 両テーブルに WmsMonthlySafetyStock import追加

### P3: 発注CD変更機能
- 完了日: 2026-04-23
- 実績:
  - 発注候補: viewCalculation モーダルに Select::make('ordering_code') 追加
  - 発注候補: 保存時に search_code + ordering_code (13桁ゼロパディング) を更新
  - 移動候補: edit モーダルに Select::make('ordering_code') 追加
  - 移動候補: 保存時に search_code + ordering_code を更新
  - Select import追加、DB facade import追加

### P4: 発注データファイルバルク操作
- 完了日: 2026-04-23
- 実績:
  - WmsOrderDataFilesTable: is_mail_order 送信方式カラム追加（バッジ表示）
  - bulkSendMail: 二重送信防止 + is_mail_order チェック + テンプレート自動生成
  - bulkDownloadCsv: S3からCSV取得 → ヘッダー行統合 → StreamedResponse
  - bulkDownloadFax: PurchaseOrderPdfService.generateBulk で1PDF化 → StreamedResponse
  - PurchaseOrderPdfService: generateBulk メソッド追加
  - ContractorForm: wms_order_mail に helperText追加

### P5: データ生成ロジック更新
- 完了日: 2026-04-23
- 実績:
  - OrderTransmissionService: WmsOrderDataFile 生成時に is_mail_order を設定（テスト/本番両方）
  - TransferCreateJobHandler: 候補生成時に ordering_code を保存
  - OrderCreateJobHandler: 候補生成時に ordering_code を保存
  - テスト: 16件失敗は全て既存の未関連テスト（PDOException, ExampleTest等）
