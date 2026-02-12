# Work Plan: fax-pdf-error-fix

- **ID**: fax-pdf-error-fix
- **作成日**: 2026-02-12
- **最終更新**: 2026-02-12
- **ステータス**: 進行中（エラー内容ヒアリング待ち）
- **ディレクトリ**: storage/specifications/fax-pdf-error-fix/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. ユーザーにエラーの具体的内容をヒアリングしてからPhase計画を確定する

## 概要

発注FAX PDF生成機能でエラーが発生している。具体的なエラー内容は未確認のため、まずシステム構成の調査を完了し、エラーヒアリング後に修正計画を策定する。

## 重要な設計制約

- データベース破壊的操作禁止（migrate:fresh, refresh, reset, db:wipe）
- 外部キー（FK）使用禁止
- PDFライブラリはTCPDF固定（HTML描画禁止、座標ベース描画のみ）
- Bladeテンプレート使用禁止（PDF生成）
- S3ストレージ使用（ローカル保存禁止）

## 対象ファイル

### PDF生成コア
- `app/Services/AutoOrder/PurchaseOrderPdfService.php` (589行) - PDF生成サービス本体
- `composer.json` - TCPDF依存: `tecnickcom/tcpdf: ^6.10`

### データベース・モデル
- `app/Models/WmsOrderDataFile.php` (146行) - データファイルモデル（fax_file_path管理）
- `app/Models/WmsOrderCandidate.php` - 発注候補モデル（PDF内容のデータソース）
- `database/migrations/2026_02_06_075854_add_fax_and_email_tracking_to_wms_order_data_files_table.php` - FAXトラッキングカラム追加

### UI・配信
- `app/Filament/Resources/WmsOrderDataFiles/Tables/WmsOrderDataFilesTable.php` (347行) - 管理画面テーブル（FAXダウンロード・メール送信アクション）
- `app/Mail/OrderDataMail.php` (111行) - メール送信（PDF添付）
- `resources/views/emails/order-data.blade.php` (26行) - メールテンプレート

### サービス
- `app/Services/AutoOrder/OrderDataFileService.php` (251行) - CSV生成・ダウンロードURL
- `app/Services/AutoOrder/OrderExecutionService.php` - 発注確定処理

### Enum
- `app/Enums/AutoOrder/OrderDataFileStatus.php` - GENERATED / DOWNLOADED
- `app/Enums/AutoOrder/CandidateStatus.php` - PENDING / APPROVED / CONFIRMED / EXCLUDED / EXECUTED

### 仕様書
- `storage/specifications/ordering/create-ordering-fax.md` (137行) - FAX仕様書

### 参照のみ（変更禁止）
- 発注計算ロジック関連ファイル（計算ロジック変更禁止）

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: システム構成調査 | 完了 | 2026-02-12 | 全体アーキテクチャ把握済み |
| P1: エラー内容ヒアリング | 進行中 | 2026-02-12 | ユーザーに具体的エラー内容を確認中 |
| P2: 原因調査・修正計画策定 | 未着手 | - | P1完了後に策定 |
| P3: 修正実装 | 未着手 | - | P2完了後に実施 |
| P4: 動作確認 | 未着手 | - | P3完了後に実施 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### システム構成（P0で確認済み）

#### PDFライブラリ
- TCPDF (`tecnickcom/tcpdf: ^6.10`)
- 座標ベース描画（HTML描画禁止）
- 日本語フォント: `kozminproregular` (TrueType)
- A4縦、白黒FAX対応

#### PDF生成フロー
```
FAXボタン押下 or メール送信
  → WmsOrderDataFilesTable のアクション
  → fax_file_path 未設定なら PurchaseOrderPdfService::generateAndStore() を呼ぶ
    → generateFromDataFile(WmsOrderDataFile) で CONFIRMED 候補を取得
    → generate(Collection, WmsOrderDataFile) でPDFバイナリ生成
    → S3 にアップロード: order-data-files/{date}/{batch}_{warehouse}_{contractor}.pdf
    → WmsOrderDataFile.fax_file_path を更新
  → S3一時URL生成（1時間有効）
  → ダウンロードリダイレクト
```

#### PDFレイアウト構成
1. **ヘッダー**（全ページ共通）: タイトル「発注書」(16pt)、発注日、発注番号、発注先情報、自社情報
2. **通信欄**: ヘッダーと明細テーブルの間
3. **明細テーブル**: 発注CD / 自社コード / 入数 / 商品名 / ケース / バラ
4. **フッター**: ページ番号 (X / Y)

#### テーブルカラム幅(mm)
- ordering_code: 28, item_code: 22, capacity_case: 12, item_name: 108, case_qty: 10, piece_qty: 10

#### フォントサイズ
- Title: 16pt, Large: 12pt, Normal: 9pt, Small: 8pt

#### 関連テーブル
- `wms_order_data_files` - ファイル管理（fax_file_path, csv_downloaded_at, fax_downloaded_at, mail_sent_at）
- `wms_order_candidates` - 発注候補データ（PDF内容のソース）

#### 最近のコミット
- `9fda1cc` (2026-02-06): feat: 発注書FAX PDF生成・メール送信・マルチチャネル配信機能（初期実装）
- `14134d8`: docs: 発注FAX仕様書とサンプルファイルを追加

### Git ブランチ
- 作業ブランチ: feature/purchase-order-document
- ベースブランチ: main

### 未確認事項（P1で確認必要）
- **具体的なエラーメッセージ/スタックトレース**
- **エラー発生タイミング**（PDF生成時? ダウンロード時? メール送信時?）
- **エラー発生条件**（特定の発注先? 特定のデータ量? 全件?）
- **再現手順**

---

## Phase完了記録

### P0: システム構成調査
- 完了日: 2026-02-12
- 実績:
  - PurchaseOrderPdfService.php (589行) の全体構造を把握
  - TCPDF座標ベース描画、A4白黒FAXレイアウト確認
  - PDF生成→S3保存→ダウンロードの全フローを追跡
  - 管理画面のFAXダウンロード・メール送信アクション構造を確認
  - メール送信（OrderDataMail）のPDF添付フロー確認
  - データモデル（WmsOrderDataFile, WmsOrderCandidate）の関係を把握
  - 仕様書 create-ordering-fax.md (137行) を確認

### P1: エラー内容ヒアリング
- 完了日: -
- 実績:
  - (ユーザーからの回答待ち)
