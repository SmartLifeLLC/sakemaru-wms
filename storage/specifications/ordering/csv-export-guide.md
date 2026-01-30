# 月別発注点CSVエクスポート作業指示書

分析システムからWMSへインポートする月別発注点CSVの作成ガイド

**作成日**: 2026-01-15

---

## 1. 概要

WMSの「月別発注点」機能では、商品ごと・倉庫ごと・発注先ごとに月別の発注点（安全在庫）を管理できます。
分析システムで算出した月別発注点データをCSVファイルでエクスポートし、WMSにインポートすることで一括登録できます。

---

## 2. CSVフォーマット仕様

### 2.1 基本情報

| 項目 | 値 |
|------|-----|
| 文字コード | UTF-8（BOM付き推奨）またはShift-JIS |
| 区切り文字 | カンマ（,） |
| 改行コード | CRLF または LF |
| ヘッダー行 | 必須（1行目） |

### 2.2 カラム定義

| No | カラム名 | 型 | 必須 | 説明 | 例 |
|----|----------|-----|------|------|-----|
| 1 | item_code | 文字列 | ○ | 商品コード | 10001 |
| 2 | warehouse_code | 文字列 | ○ | 倉庫コード | WH001 |
| 3 | contractor_code | 文字列 | ○ | 発注先コード | CNT001 |
| 4 | month | 整数 | ○ | 月（1〜12） | 1 |
| 5 | safety_stock | 整数 | ○ | 発注点（バラ単位、0以上） | 100 |

### 2.3 サンプルCSV

```csv
item_code,warehouse_code,contractor_code,month,safety_stock
10001,WH001,CNT001,1,100
10001,WH001,CNT001,2,120
10001,WH001,CNT001,3,150
10001,WH001,CNT001,4,180
10001,WH001,CNT001,5,200
10001,WH001,CNT001,6,220
10001,WH001,CNT001,7,250
10001,WH001,CNT001,8,230
10001,WH001,CNT001,9,180
10001,WH001,CNT001,10,150
10001,WH001,CNT001,11,120
10001,WH001,CNT001,12,100
```

---

## 3. 分析システム側のSQLクエリ例

### 3.1 Oracle（分析システム）からのエクスポート

```sql
-- 月別発注点データのエクスポート
SELECT
    i.item_code AS item_code,
    w.warehouse_code AS warehouse_code,
    c.contractor_code AS contractor_code,
    ms.month AS month,
    ms.safety_stock AS safety_stock
FROM
    monthly_safety_stocks ms
    INNER JOIN items i ON ms.item_id = i.id
    INNER JOIN warehouses w ON ms.warehouse_id = w.id
    INNER JOIN contractors c ON ms.contractor_id = c.id
WHERE
    ms.is_active = 1
ORDER BY
    w.warehouse_code,
    i.item_code,
    c.contractor_code,
    ms.month;
```

### 3.2 売上データから発注点を算出する例

```sql
-- 過去3年間の月別平均売上から発注点を算出
-- 発注点 = 月別平均売上 × 安全係数(1.5) × リードタイム日数(3日) / 30日
SELECT
    i.item_code AS item_code,
    w.warehouse_code AS warehouse_code,
    c.contractor_code AS contractor_code,
    EXTRACT(MONTH FROM s.sale_date) AS month,
    CEIL(
        AVG(s.quantity) * 1.5 * 3 / 30
    ) AS safety_stock
FROM
    sales s
    INNER JOIN items i ON s.item_id = i.id
    INNER JOIN warehouses w ON s.warehouse_id = w.id
    INNER JOIN item_contractors ic ON s.item_id = ic.item_id AND s.warehouse_id = ic.warehouse_id
    INNER JOIN contractors c ON ic.contractor_id = c.id
WHERE
    s.sale_date >= ADD_MONTHS(TRUNC(SYSDATE), -36)
    AND s.is_active = 1
GROUP BY
    i.item_code,
    w.warehouse_code,
    c.contractor_code,
    EXTRACT(MONTH FROM s.sale_date)
ORDER BY
    w.warehouse_code,
    i.item_code,
    c.contractor_code,
    month;
```

---

## 4. 自動更新フラグ

`item_contractors`テーブルには`use_safety_stock_auto_update`フラグがあり、このフラグが`true`の商品のみが月別発注点の自動同期対象となります。

| フラグ値 | 動作 |
|---------|------|
| `true` | 月別発注点データで`safety_stock`を自動更新 |
| `false` | 自動更新対象外（手動設定値を維持） |

**注意**: CSVインポート後、対象商品の`use_safety_stock_auto_update`を`true`に設定する必要があります。

---

## 5. バリデーションルール

WMSインポート時に以下のバリデーションが行われます:

| ルール | エラー時の動作 |
|--------|---------------|
| 商品コードが存在しない | その行をスキップ |
| 倉庫コードが存在しない | その行をスキップ |
| 発注先コードが存在しない | その行をスキップ |
| 月が1〜12の範囲外 | その行をスキップ |
| 発注点が負の値 | その行をスキップ |
| 重複データ | 既存データを上書き（Upsert） |

---

## 6. インポート手順

1. **WMS管理画面にログイン**
   - URL: `https://wms.sakemaru.com/admin`

2. **月別発注点画面を開く**
   - メニュー: マスタ管理 > 発注マスタ > 月別発注点

3. **CSVインポートを実行**
   - 「CSVインポート」ボタンをクリック
   - ファイルを選択してアップロード

4. **結果確認**
   - インポート件数とエラー件数が表示されます
   - エラーはログに記録されます

---

## 7. 日次同期処理

インポートしたデータは、毎日04:30に `item_contractors.safety_stock` に自動同期されます。

**重要**: `use_safety_stock_auto_update = true` の商品のみが同期対象です。

```
04:30 → wms:sync-monthly-safety-stocks （月別発注点同期）
05:00 → wms:snapshot-stocks            （在庫スナップショット）
06:00 → wms:auto-order-calculate       （発注計算）
```

手動で同期する場合:
```bash
php artisan wms:sync-monthly-safety-stocks
```

---

## 8. 注意事項

1. **月をまたぐタイミング**
   - 月初1日の00:00〜04:30は前月の発注点で計算される可能性があります
   - 月初に手動同期を実行することを推奨

2. **データがない月の扱い**
   - 月別データがない場合は、`item_contractors`の既存値がそのまま使用されます
   - 全月のデータを登録することを推奨

3. **文字コード**
   - UTF-8（BOM付き）を推奨
   - Excelで編集する場合はShift-JISで保存しても問題ありません

4. **大量データのインポート**
   - 数万件以上の場合はタイムアウトの可能性があります
   - 倉庫別にファイルを分割してインポートすることを推奨

---

## 9. お問い合わせ

インポートに関する問題が発生した場合は、システム管理者にお問い合わせください。

ログファイル: `storage/logs/laravel.log`
