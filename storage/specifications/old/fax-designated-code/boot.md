# Work Plan: fax-designated-code

- **ID**: fax-designated-code
- **作成日**: 2026-02-13
- **最終更新**: 2026-02-13
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/fax-designated-code/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

FAX発注書PDFに「納入先指定コード」を追加する。発注先が倉庫ごとに指定する独自コードを表示する機能。
`wms_contractor_warehouse_settings`テーブルを新設し、warehouse_id × contractor_id ごとに指定コードを保持。

## 重要な設計制約

- FK禁止（index対応のみ）
- DB接続は`sakemaru`を使用
- テーブル名は`wms_`プレフィックス
- TCPDF座標描画のみ（HTML禁止）
- `migrate:fresh` / `migrate:refresh` 禁止

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_create_wms_contractor_warehouse_settings_table.php` - マイグレーション
- `app/Models/WmsContractorWarehouseSetting.php` - モデル

### 既存変更
- `app/Services/AutoOrder/PurchaseOrderPdfService.php` - PDF描画に「納入先指定コード」行を追加

### 参照のみ（変更禁止）
- `app/Models/WmsContractorSetting.php` - 既存パターン参考
- `database/migrations/2025_12_27_171601_create_wms_contractor_settings_table.php` - マイグレーションパターン参考

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: マイグレーション＆モデル作成 | 完了 | 2026-02-13 | マイグレーション実行成功 |
| P2: PDF描画更新 | 完了 | 2026-02-13 | renderContractorInfo()に指定コード行追加 |
| P3: 動作確認 | 完了 | 2026-02-13 | 両パターン確認済み |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### DB設計
- テーブル: wms_contractor_warehouse_settings
- カラム: id, warehouse_id, contractor_id, designated_code, timestamps
- インデックス: (warehouse_id, contractor_id) のユニークインデックス

### PDF描画位置
- `renderContractorInfo()` メソッド内、「納入場所」行 (L269) の直後に挿入
- レコードなし or NULLの場合は「[ - ]」を表示

### Git ブランチ
- 作業ブランチ: feature/fax-designated-code
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: マイグレーション＆モデル作成
- 完了日: 2026-02-13
- 実績:
  - `database/migrations/2026_02_13_013034_create_wms_contractor_warehouse_settings_table.php` 作成・実行済み
  - `app/Models/WmsContractorWarehouseSetting.php` 作成済み（getDesignatedCode()静的メソッド実装）
  - テーブル: wms_contractor_warehouse_settings (warehouse_id, contractor_id, designated_code)
  - ユニークインデックス: uniq_wcws_warehouse_contractor

### P2: PDF描画更新
- 完了日: 2026-02-13
- 実績:
  - `app/Services/AutoOrder/PurchaseOrderPdfService.php` の `renderContractorInfo()` メソッド更新
  - 「納入場所」行の直後に「納入先指定コード」行を追加
  - データあり: `納入先指定コード: {code}` / データなし: `納入先指定コード:  - `

### P3: 動作確認
- 完了日: 2026-02-13
- 実績:
  - 指定コードあり（UPDATED-CODE-002）→ PDF上で正しく表示確認
  - 指定コードなし（レコード未登録）→ 「 - 」表示確認
  - 既存レイアウト（タイトル、通信欄、明細テーブル）に影響なし
  - テストデータクリーンアップ済み
