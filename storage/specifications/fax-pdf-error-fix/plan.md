# 発注FAX PDF エラー対応 作業計画

## 前提

- 2026-02-06 に発注書FAX PDF生成機能を初期実装済み（commit `9fda1cc`）
- TCPDF による座標ベース描画、S3保存、Filament管理画面からのダウンロード/メール送信
- 現在エラーが発生しているが、具体的な内容は未確認

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | システム構成調査 | PDF生成の全体アーキテクチャ把握 | 全関連ファイル・フロー把握 |
| P1 | エラー内容ヒアリング | ユーザーから具体的エラー情報を取得 | エラーメッセージ・再現条件の特定 |
| P2 | 原因調査・修正計画策定 | エラー原因の特定と修正方針決定 | 根本原因と修正箇所の特定 |
| P3 | 修正実装 | コード修正 | エラーが解消されること |
| P4 | 動作確認 | 修正後の動作テスト | PDF生成・ダウンロード・メール送信が正常動作 |

---

## P0: システム構成調査 ✅ 完了

### 目的

PDF生成に関わる全ファイル・フロー・依存関係を把握する。

### 調査結果

#### ファイル構成

| ファイル | 役割 | 行数 |
|---------|------|------|
| `app/Services/AutoOrder/PurchaseOrderPdfService.php` | PDF生成コア | 589 |
| `app/Models/WmsOrderDataFile.php` | データファイルモデル | 146 |
| `app/Filament/Resources/WmsOrderDataFiles/Tables/WmsOrderDataFilesTable.php` | 管理画面UI | 347 |
| `app/Mail/OrderDataMail.php` | メール送信 | 111 |
| `app/Services/AutoOrder/OrderDataFileService.php` | CSV生成・URL取得 | 251 |
| `storage/specifications/ordering/create-ordering-fax.md` | FAX仕様書 | 137 |

#### 技術スタック
- PDFライブラリ: TCPDF 6.10
- フォント: kozminproregular (TrueType日本語)
- 描画方式: 座標ベース（HTML禁止）
- ストレージ: S3
- フレームワーク: Laravel 12 + Filament 4

#### PDF生成フロー
1. 管理画面のFAXボタン or メール送信 → WmsOrderDataFilesTable のアクション
2. `PurchaseOrderPdfService::generateAndStore(WmsOrderDataFile)` 呼び出し
3. `generateFromDataFile()` で CONFIRMED 状態の候補を取得
4. `generate()` で TCPDF による PDF バイナリ生成
5. S3アップロード → `WmsOrderDataFile.fax_file_path` 更新
6. S3一時URL（1時間有効）でダウンロード

### 完了条件

全関連ファイルとフローを把握 → ✅

---

## P1: エラー内容ヒアリング 🔄 進行中

### 目的

ユーザーから具体的なエラー情報を取得し、調査対象を絞る。

### 確認事項

- [ ] 具体的なエラーメッセージ / スタックトレース
- [ ] エラー発生タイミング（PDF生成? ダウンロード? メール送信?）
- [ ] エラー発生条件（特定の発注先? データ量? 全件?）
- [ ] 再現手順
- [ ] ブラウザコンソールにエラーがあるか
- [ ] Laravelログ（storage/logs/laravel.log）にエラーがあるか

### 完了条件

エラーの再現条件と原因の方向性が特定できること

---

## P2: 原因調査・修正計画策定（P1完了後に詳細化）

### 目的

エラー原因を特定し、修正方針を決定する。

### 想定される問題パターン

1. **TCPDF フォント関連**: kozminproregular フォントファイルが見つからない / 壊れている
2. **メモリ不足**: 大量商品のPDF生成でメモリ上限超過
3. **S3アクセスエラー**: S3保存/読み取りの権限・パス問題
4. **データ不整合**: CONFIRMED候補が存在しない / リレーション欠落
5. **TCPDF描画エラー**: 座標計算ミス、ページ分割時のレイアウト崩れ
6. **文字エンコーディング**: 特定文字でTCPDFが例外
7. **Filament UIエラー**: ダウンロードアクションのリダイレクト問題

### 修正対象ファイル（P1結果に応じて特定）

- `PurchaseOrderPdfService.php` - PDF生成ロジック
- `WmsOrderDataFilesTable.php` - UIアクション
- `OrderDataMail.php` - メール送信
- その他（原因に応じて）

### 完了条件

根本原因と修正箇所が特定され、修正方針がドキュメント化されていること

---

## P3: 修正実装（P2完了後に詳細化）

### 完了条件

エラーが解消され、コードが修正されていること

---

## P4: 動作確認（P3完了後に詳細化）

### 確認項目

- [ ] FAXダウンロードボタンでPDFが正常生成・ダウンロードされること
- [ ] メール送信でPDFが正常添付されること
- [ ] 複数ページの発注書が正しくレイアウトされること
- [ ] ページ番号が正しく表示されること
- [ ] 日本語文字が正しく描画されること

### 完了条件

全確認項目がパスすること

---

## 制約（厳守）

1. **データベース破壊的操作禁止**: migrate:fresh, refresh, reset, db:wipe は実行しない
2. **FK禁止**: 外部キーは使用しない
3. **TCPDF座標描画のみ**: HTML描画（mpdf/dompdf/snappy/puppeteer）禁止
4. **Bladeテンプレート禁止**: PDF生成にBladeを使わない
5. **S3ストレージ固定**: ローカル保存禁止
6. **計算ロジック変更禁止**: 発注計算ロジックは変更しない
7. **既存フローの維持**: FAXダウンロード→S3一時URL→ブラウザダウンロードの流れは維持

## 全体完了条件

- 発注FAX PDFの生成・ダウンロード・メール送信が全てエラーなく動作すること
- 既存の発注フロー（候補生成→承認→確定→送信）に影響がないこと
