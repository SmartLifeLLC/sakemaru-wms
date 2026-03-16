# FAX発注書「納入先指定コード」追加 作業計画

## 前提

- FAX発注書PDF生成機能は `PurchaseOrderPdfService.php` で実装済み
- 現在の「納入場所」は倉庫名を表示（仮想倉庫の場合は実倉庫名）
- 発注先×倉庫ごとの設定テーブルは未存在（`wms_contractor_settings`は発注先単位のみ）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | マイグレーション＆モデル作成 | `wms_contractor_warehouse_settings`テーブルとモデル | マイグレーション実行成功、モデルでCRUD可能 |
| P2 | PDF描画更新 | 「納入先指定コード」をFAX PDFに追加 | PDF上で納入場所の次行に指定コードが表示される |
| P3 | 動作確認 | FAXダウンロードでPDF確認 | 指定コードあり/なし両パターンで正しく表示 |

---

## P1: マイグレーション＆モデル作成

### 目的

発注先が倉庫ごとに指定するコードを保持するテーブルを新設する。

### マイグレーション

```php
// wms_contractor_warehouse_settings
- id (bigint, PK)
- warehouse_id (unsignedBigInteger, index)
- contractor_id (unsignedBigInteger, index)
- designated_code (string, nullable) — 納入先指定コード
- timestamps

// ユニークインデックス: (warehouse_id, contractor_id)
// FK は作成しない（プロジェクトルール）
```

接続: `sakemaru`

### モデル

```
app/Models/WmsContractorWarehouseSetting.php
- extends WmsModel（sakemaru接続）
- fillable: warehouse_id, contractor_id, designated_code
- relationships: warehouse(), contractor()
- static method: getDesignatedCode(int $warehouseId, int $contractorId): ?string
```

### 完了条件

- `php artisan migrate` が成功する
- モデルクラスが正しく定義されている

---

## P2: PDF描画更新

### 目的

FAX発注書PDFの「納入場所」の次に「納入先指定コード」行を追加する。

### 修正対象ファイル

- `app/Services/AutoOrder/PurchaseOrderPdfService.php`
  - `renderContractorInfo()` メソッド

### 修正方針

1. `renderDocument()` で `WmsContractorWarehouseSetting::getDesignatedCode()` を呼び出してコードを取得
2. `renderContractorInfo()` の「納入場所」描画直後（L270付近）に以下を追加:

```
納入先指定コード: {designated_code} （レコードなし or NULLなら「 - 」）
```

3. 表示フォーマット:
   - コードあり: `納入先指定コード: ABC123`
   - コードなし: `納入先指定コード:  - `

### 完了条件

- PDF上で「納入場所」の次行に「納入先指定コード」が表示される
- データなしの場合は「 - 」が表示される

---

## P3: 動作確認

### 目的

実際のFAXダウンロード操作で正しいPDF出力を確認する。

### 確認手順

1. `wms_contractor_warehouse_settings` にテストデータがないケース → 「 - 」表示
2. テストデータを挿入して再生成 → 指定コードが表示

### 完了条件

- 両パターンで正しく表示される
- 既存のPDFレイアウト（タイトル、通信欄、明細テーブル等）が崩れていない

---

## 制約（厳守）

- FK作成禁止（index対応のみ）
- `migrate:fresh` / `migrate:refresh` / `migrate:reset` 実行禁止
- TCPDF座標描画のみ（writeHTML禁止）
- 既存のPDFレイアウトを崩さない
- DB接続は`sakemaru`を使用

## 全体完了条件

- マイグレーションが正常に適用されている
- FAX PDF上で「納入先指定コード」が正しく表示される
- 指定コードなしの場合は「 - 」が表示される
- 既存機能（CSV、メール送信）に影響がない
