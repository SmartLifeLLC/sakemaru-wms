# 発注候補生成 統合テスト 作業計画

## 前提

- `auto-order-split-strategy` で安全在庫ベースと実績ベースに分離済み
- 安全在庫ベース: `OrderCandidateCalculationService`（`is_auto_order=true` + `safety_stock>0`）
- 実績ベース: `SalesBasedOrderCandidateService`（`is_auto_order=false` + `last_3d_qty>0`）
- OriginType: `MANUAL_SAFETY_STOCK` / `MANUAL_SALES_BASED` / `AUTO_SAFETY_STOCK` / `AUTO_SALES_BASED` / `USER` / `DIST`
- 本番データがそのまま入っている環境でテスト

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | テストコマンド雛形作成 | Artisanコマンド `wms:test-auto-order` を作成 | `php artisan wms:test-auto-order --help` が動作 |
| P2 | マスタデータ分析 | 既存データからテストケースに該当する商品を自動検出・分類 | 各カテゴリ（A1-A8）の商品数が出力される |
| P3 | 安全在庫ベーステスト | 候補テーブルtruncate → サービス実行 → DB検証 | ケースA1,A2,B1-B4,C1-C3 の結果テーブル出力 |
| P4 | 実績ベーステスト | サービス実行 → DB検証 | ケースA3-A6,B5-B6 の結果テーブル出力 |
| P5 | batch_code共有テスト | 安全在庫→実績の連続実行でbatch_code共有を検証 | ケースD1-D3 の結果テーブル出力 |
| P6 | origin_type・発注CD・重複チェック | E1-E2, F1-F3, G1-G2 の検証 | 全ケースの結果テーブル出力 |
| P7 | 最終分析レポート | 全結果を分析表にまとめる | `test-results.md` 作成・全ケースPASS/FAIL判定 |

---

## P1: テストコマンド雛形作成

### 目的

テスト実行の起点となるArtisanコマンド `wms:test-auto-order` を作成する。各テストケースをメソッド単位で実行し、結果をコンソール出力する。

### 実装方針

`app/Console/Commands/AutoOrder/TestAutoOrderCommand.php` を新規作成:

```
php artisan wms:test-auto-order           # 全テスト実行
php artisan wms:test-auto-order --phase=2 # 特定Phase実行
php artisan wms:test-auto-order --analyze # マスタデータ分析のみ
```

### 実装内容

1. コマンドクラスを作成（`signature`, `description`）
2. `--phase` オプション（1-7）、`--analyze` オプション
3. 候補テーブルの truncate メソッド（`wms_stock_transfer_candidates`, `wms_order_candidates`, `wms_order_calculation_logs`, `wms_auto_order_job_controls`, `wms_queue_progress`）
4. 結果出力用のテーブルフォーマッタ（`$this->table()` ヘルパー）
5. 各Phaseのテストメソッドスタブ（`runPhase2()` 〜 `runPhase6()`）
6. エラーハンドリング: 致命的エラーは中断、ケースエラーは記録して続行

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Console/Commands/AutoOrder/TestAutoOrderCommand.php` | 新規作成 |

### 完了条件

- `php artisan wms:test-auto-order --help` が正常に表示
- `php -l app/Console/Commands/AutoOrder/TestAutoOrderCommand.php` でシンタックスエラーなし

---

## P2: マスタデータ分析・テストケース自動分類

### 目的

既存の本番データから、テストケースマトリクス（A1-A8）に該当する商品を自動検出し、テストに使える商品のサンプルを特定する。

### 実装内容

`runPhase2()` を実装。以下のクエリで分析:

1. **A1: 安全在庫ON・在庫不足**
   ```sql
   SELECT COUNT(*) FROM item_contractors ic
   WHERE ic.is_auto_order = true AND ic.safety_stock > 0
   AND EXISTS (SELECT 1 FROM wms_v_stock_available v 
     WHERE v.item_id = ic.item_id AND v.effective_stock < ic.safety_stock)
   ```

2. **A2: 安全在庫ON・在庫十分** — 同上、`effective_stock >= safety_stock`

3. **A3: 発注OFF・実績あり**
   ```sql
   SELECT COUNT(*) FROM item_contractors ic
   JOIN stats_item_warehouse_sales_summaries s ON s.item_id = ic.item_id
   WHERE ic.is_auto_order = false AND s.last_3d_qty > 0
   ```

4. **A4: 発注OFF・実績なし** — `is_auto_order=false AND last_3d_qty=0`

5. **A5: 発注OFF・安全在庫あり・実績あり** — `is_auto_order=false AND safety_stock>0 AND last_3d_qty>0`

6. **A6: 発注ON・安全在庫ゼロ** — `is_auto_order=true AND safety_stock=0`

7. **A7: 販売終了品** — `is_ended=true OR end_of_sale_type != 'NORMAL'`

8. **A8: 販売開始前** — `start_of_sale_date > today`

9. **INTERNAL/EXTERNAL分類**
   ```sql
   SELECT type, COUNT(*) FROM wms_contractor_settings GROUP BY type
   ```

10. **発注CD分析**
    ```sql
    SELECT COUNT(*) FROM item_search_information WHERE is_used_for_ordering = true
    SELECT COUNT(*) FROM item_search_information WHERE is_used_for_ordering = false
    ```

### 出力

各カテゴリの商品数、サンプルitem_id（各3件）をテーブル出力。

### 完了条件

- `php artisan wms:test-auto-order --analyze` で分析テーブルが出力される
- 各カテゴリに0件以上のデータが確認される

---

## P3: 安全在庫ベーステスト実行・検証

### 目的

`OrderCandidateCalculationService` を直接呼び出し、候補生成結果をDB上で検証する。

### 実装内容

`runPhase3()` を実装:

1. **準備**: 候補テーブルtruncate
2. **実行**: `OrderCandidateCalculationService::calculate()` を同期実行（全32倉庫）
3. **検証**:

#### ケースA1: 安全在庫ON・在庫不足 → 候補生成
```sql
-- 候補が生成されていること
SELECT COUNT(*) FROM wms_order_candidates WHERE batch_code = ?
UNION ALL
SELECT COUNT(*) FROM wms_stock_transfer_candidates WHERE batch_code = ?
```

#### ケースA2: 安全在庫ON・在庫十分 → スキップ
```sql
-- 在庫十分な商品が候補に含まれていないこと
SELECT oc.item_id FROM wms_order_candidates oc
JOIN item_contractors ic ON ic.item_id = oc.item_id AND ic.contractor_id = oc.contractor_id
JOIN wms_v_stock_available v ON v.item_id = oc.item_id AND v.warehouse_id = oc.warehouse_id
WHERE oc.batch_code = ? AND v.effective_stock >= ic.safety_stock
-- → 0件であること
```

#### ケースB1-B3: 数量計算
```sql
-- サンプル10件の不足数・発注数を確認
SELECT oc.item_id, oc.warehouse_id, oc.shortage_quantity, oc.order_quantity,
       oc.purchase_unit, ic.safety_stock
FROM wms_order_candidates oc
JOIN item_contractors ic ON ic.item_id = oc.item_id AND ic.contractor_id = oc.contractor_id
WHERE oc.batch_code = ?
LIMIT 10
```
- `shortage_quantity = safety_stock - effective_stock - incoming`
- `order_quantity = ceil(shortage_quantity / purchase_unit) * purchase_unit`

#### ケースC1-C3: INTERNAL/EXTERNAL分岐
```sql
-- INTERNAL候補は wms_stock_transfer_candidates に存在
SELECT stc.contractor_id, cs.type FROM wms_stock_transfer_candidates stc
JOIN wms_contractor_settings cs ON cs.contractor_id = stc.contractor_id
WHERE stc.batch_code = ? AND cs.type != 'INTERNAL'
-- → 0件であること（INTERNALのみ）

-- EXTERNAL候補は wms_order_candidates に存在
SELECT oc.contractor_id, cs.type FROM wms_order_candidates oc
JOIN wms_contractor_settings cs ON cs.contractor_id = oc.contractor_id
WHERE oc.batch_code = ? AND cs.type != 'EXTERNAL'
-- → 0件であること（EXTERNALのみ）
```

### 結果出力

```
| ケース | 結果 | 生成数 | 期待数 | 詳細 |
```

### 完了条件

- ケースA1, A2, B1-B3, C1-C3 の結果テーブルが出力される
- 致命的エラーなし（ケースエラーは記録のみ）

---

## P4: 実績ベーステスト実行・検証

### 目的

`SalesBasedOrderCandidateService` を直接呼び出し、候補生成結果をDB上で検証する。

### 実装内容

`runPhase4()` を実装:

1. **準備**: 候補テーブルtruncate（P3の結果をクリア）
2. **実行**: `SalesBasedOrderCandidateService::calculate()` を同期実行
3. **検証**:

#### ケースA3: 発注OFF・実績あり → 候補生成
```sql
-- is_auto_order=false の商品のみ候補に含まれること
SELECT COUNT(*) FROM wms_order_candidates oc
JOIN item_contractors ic ON ic.item_id = oc.item_id AND ic.contractor_id = oc.contractor_id
WHERE oc.batch_code = ? AND ic.is_auto_order = true
-- → 0件であること
```

#### ケースA4: 発注OFF・実績なし → スキップ
```sql
-- last_3d_qty=0 の商品が候補に含まれていないこと
SELECT COUNT(*) FROM wms_order_candidates oc
JOIN stats_item_warehouse_sales_summaries s 
  ON s.item_id = oc.item_id AND s.warehouse_id = oc.warehouse_id
WHERE oc.batch_code = ? AND s.last_3d_qty = 0
-- → 0件であること
```

#### ケースA5: 発注OFF・安全在庫あり・実績あり → 候補生成（safety_stock無視）
```sql
-- is_auto_order=false かつ safety_stock>0 の商品が候補に含まれること
SELECT COUNT(*) FROM wms_order_candidates oc
JOIN item_contractors ic ON ic.item_id = oc.item_id AND ic.contractor_id = oc.contractor_id
WHERE oc.batch_code = ? AND ic.is_auto_order = false AND ic.safety_stock > 0
-- → 0件以上（safety_stockを無視してlast_3d_qtyで計算）
```

#### ケースA6: 発注ON・安全在庫ゼロ → 対象外（許容ギャップ）
```sql
-- is_auto_order=true の商品が実績ベース候補に含まれていないこと
SELECT COUNT(*) FROM wms_order_candidates oc
JOIN item_contractors ic ON ic.item_id = oc.item_id AND ic.contractor_id = oc.contractor_id
WHERE oc.batch_code = ? AND ic.is_auto_order = true
-- → 0件であること
```

#### ケースB5-B6: 実績ベース数量計算
```sql
-- 不足数 = last_3d_qty - effective_stock - incoming（0以上）
SELECT oc.item_id, oc.shortage_quantity, oc.order_quantity, s.last_3d_qty
FROM wms_order_candidates oc
JOIN stats_item_warehouse_sales_summaries s 
  ON s.item_id = oc.item_id AND s.warehouse_id = oc.warehouse_id
WHERE oc.batch_code = ?
LIMIT 10
```

### 完了条件

- ケースA3-A6, B5-B6 の結果テーブルが出力される

---

## P5: batch_code共有テスト

### 目的

安全在庫ベース → 実績ベースの連続実行で、同一batch_codeが共有されることを検証する。

### 実装内容

`runPhase5()` を実装:

#### ケースD1: 安全在庫→実績の順
1. 候補テーブルtruncate
2. `OrderCandidateCalculationService::calculate()` 実行 → `$batchCode1` 取得
3. `SalesBasedOrderCandidateService::calculate(batchCode: $batchCode1)` 実行 → `$batchCode2` 取得
4. 検証: `$batchCode1 === $batchCode2`

#### ケースD2: 実績のみ
1. 候補テーブルtruncate
2. `SalesBasedOrderCandidateService::calculate()` 実行（batchCode指定なし）
3. 検証: 新規batch_codeが生成されること

#### ケースD3: 2回連続実績
1. 候補テーブルtruncate
2. 安全在庫ベース実行 → `$batchCode`
3. 実績ベース1回目 → `$batchCode2`
4. 実績ベース2回目 → `$batchCode3`
5. 検証: `$batchCode === $batchCode2 === $batchCode3`

### 完了条件

- ケースD1-D3 の結果テーブルが出力される
- batch_code共有が正しく動作することを確認

---

## P6: origin_type・発注CD・重複チェック検証

### 目的

origin_type、発注CD（ordering_code）、重複生成の検証。

### 実装内容

`runPhase6()` を実装:

#### ケースE1-E2: origin_type
```sql
-- 安全在庫ベースの候補
SELECT DISTINCT origin_type FROM wms_order_candidates WHERE batch_code = ?
-- → MANUAL_SAFETY_STOCK のみ

-- 実績ベースの候補
SELECT DISTINCT origin_type FROM wms_order_candidates WHERE batch_code = ?
-- → MANUAL_SALES_BASED のみ
```

#### ケースF1-F3: 発注CD
```sql
-- F1: is_used_for_ordering=true の発注CD
SELECT oc.ordering_code, isi.search_string, isi.is_used_for_ordering
FROM wms_order_candidates oc
LEFT JOIN item_search_information isi ON isi.item_id = oc.item_id AND isi.is_used_for_ordering = true
WHERE oc.batch_code = ? AND oc.ordering_code IS NOT NULL
LIMIT 10
-- ordering_code が search_string と一致

-- F2: is_used_for_ordering=false → ordering_code が null
SELECT COUNT(*) FROM wms_order_candidates oc
WHERE oc.batch_code = ? AND oc.ordering_code IS NOT NULL
AND NOT EXISTS (
  SELECT 1 FROM item_search_information isi 
  WHERE isi.item_id = oc.item_id AND isi.is_used_for_ordering = true
)
-- → 0件

-- F3: 13桁ゼロパディング
SELECT oc.ordering_code FROM wms_order_candidates oc
WHERE oc.batch_code = ? AND oc.ordering_code IS NOT NULL AND LENGTH(oc.ordering_code) != 13
-- → 0件（全て13桁）
```

#### ケースG1-G2: 重複チェック
```sql
-- G1: 同一商品×倉庫×発注先で1レコードのみ
SELECT item_id, warehouse_id, contractor_id, COUNT(*) as cnt
FROM wms_order_candidates WHERE batch_code = ?
GROUP BY item_id, warehouse_id, contractor_id
HAVING cnt > 1
-- → 0件

-- G2: 安全在庫+実績で同一商品が両方に出ない
-- P5のD1テスト結果を利用（同一batch_code内）
SELECT oc1.item_id FROM wms_order_candidates oc1
WHERE oc1.batch_code = ? AND oc1.origin_type = 'MANUAL_SAFETY_STOCK'
AND EXISTS (
  SELECT 1 FROM wms_order_candidates oc2
  WHERE oc2.batch_code = oc1.batch_code
  AND oc2.item_id = oc1.item_id AND oc2.warehouse_id = oc1.warehouse_id
  AND oc2.origin_type = 'MANUAL_SALES_BASED'
)
-- → 0件（is_auto_orderで排他）
```

### 完了条件

- ケースE1-E2, F1-F3, G1-G2 の結果テーブルが出力される

---

## P7: 最終分析レポート作成

### 目的

全テスト結果を統合し、分析表としてまとめる。

### 実装内容

1. P2-P6 の全テスト結果を集約
2. `test-results.md` に以下を出力:
   - テスト実行日時
   - 環境情報（倉庫数、商品数、automator ID）
   - 全テストケースの結果サマリテーブル
   - 各カテゴリ（A-G）の詳細結果
   - PASS/FAIL 集計
   - 問題点・改善提案（FAILがある場合）

### 出力フォーマット

```markdown
# 発注候補生成 統合テスト結果

## サマリ

| カテゴリ | テスト数 | PASS | FAIL | SKIP |
|---------|---------|------|------|------|
| A: 対象判定 | 8 | ? | ? | ? |
| B: 数量計算 | 6 | ? | ? | ? |
| ...

## 詳細結果

### A: 対象判定テスト
| # | ケース | 結果 | 生成数 | 期待 | 詳細 |
...
```

### 完了条件

- `test-results.md` が作成される
- 全ケースにPASS/FAIL判定がある
- 分析コメントがある（特にFAILケース）

---

## 制約（厳守）

1. **`migrate:fresh` / `migrate:refresh` 禁止** — 本番データ保護
2. **マスタテーブルのtruncate禁止** — `item_contractors`, `items`, `contractors`, `warehouses`, `item_search_information`, `stats_item_warehouse_sales_summaries`, `wms_contractor_settings`, `wms_warehouse_auto_order_settings`, `wms_v_stock_available`, `wms_order_incoming_schedules`
3. **候補テーブルのtruncateはOK** — `wms_stock_transfer_candidates`, `wms_order_candidates`, `wms_order_calculation_logs`, `wms_auto_order_job_controls`, `wms_queue_progress`
4. **計算ロジックの変更禁止** — テスト対象の2サービスは参照のみ
5. **致命的エラーのみ中断** — ケースエラーは記録して次に進む
6. **DB接続** — `sakemaru` コネクション使用
7. **エージェント構成** — 設計・監視・分析: Opus / 実装・実行: Sonnet

## 全体完了条件

1. `wms:test-auto-order` コマンドが全テストを実行できる
2. `test-results.md` に全20+ケースの結果が記載されている
3. 各ケースにPASS/FAIL判定と詳細がある
4. 重大な不具合（重複生成、数量ミス等）が検出された場合は問題点として記載
