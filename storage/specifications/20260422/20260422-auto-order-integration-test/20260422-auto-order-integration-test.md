# 発注候補生成 統合テスト仕様書

- **作成日**: 2026-04-22
- **ステータス**: ドラフト
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/20260422/20260422-auto-order-integration-test/

## 背景・目的

発注候補生成を安全在庫ベースと実績ベースに分離した（`auto-order-split-strategy`）。分離後の2つのサービスが正しく動作するか、各種パターンで統合テストを実施し、結果を分析表としてまとめる。

### テスト対象サービス

| サービス | 対象商品 | しきい値 |
|---------|---------|---------|
| `OrderCandidateCalculationService` | `is_auto_order = true` + `safety_stock > 0` | `safety_stock` |
| `SalesBasedOrderCandidateService` | `is_auto_order = false` + `last_3d_qty > 0` | `last_3d_qty` |

### テストの目的

1. 各サービスが正しい対象商品のみを候補生成すること
2. 重複候補が生成されないこと
3. 数量計算（不足分、切り上げ、ケース変換）が正しいこと
4. `origin_type` が正しく設定されること（`MANUAL_SAFETY_STOCK` / `MANUAL_SALES_BASED`）
5. `batch_code` 共有が正しく機能すること
6. 発注CD（`ordering_code`）が `item_search_information.is_used_for_ordering = true` のもののみ使用されること
7. INTERNAL/EXTERNAL の分岐が正しいこと
8. 到着日計算（リードタイム + 納品曜日 + 休日）が正しいこと

---

## 現状の実装

### 対象サービス

| ファイル | 役割 |
|---------|------|
| `app/Services/AutoOrder/OrderCandidateCalculationService.php` | 安全在庫ベース計算（`safety_stock > 0` + `is_auto_order = true`） |
| `app/Services/AutoOrder/SalesBasedOrderCandidateService.php` | 実績ベース計算（`is_auto_order = false` + `last_3d_qty > 0`） |
| `app/Jobs/ProcessOrderCandidateGenerationJob.php` | 安全在庫ベースジョブ |
| `app/Jobs/ProcessSalesBasedOrderCandidateJob.php` | 実績ベースジョブ |

### 関連テーブル

**候補出力テーブル（truncate可）:**
- `wms_stock_transfer_candidates` — INTERNAL移動候補
- `wms_order_candidates` — EXTERNAL発注候補
- `wms_order_calculation_logs` — 計算ログ
- `wms_auto_order_job_controls` — ジョブ実行履歴
- `wms_queue_progress` — Queue進捗

**マスタテーブル（truncate禁止、readのみ / update可）:**
- `item_contractors` — 商品×発注先（`is_auto_order`, `safety_stock`, `purchase_unit`）
- `items` — 商品マスタ
- `contractors` — 発注先マスタ
- `warehouses` — 倉庫マスタ
- `item_search_information` — 発注CD（`is_used_for_ordering`, `search_string`）
- `stats_item_warehouse_sales_summaries` — 3日間販売実績（`last_3d_qty`）
- `wms_contractor_settings` — INTERNAL/EXTERNAL設定
- `wms_warehouse_auto_order_settings` — 倉庫自動発注有効設定
- `wms_v_stock_available` — 在庫ビュー
- `wms_order_incoming_schedules` — 入荷予定

---

## テストケース設計

### ケースマトリクス

#### A. 対象判定テスト（どの商品が候補に含まれるか）

| # | ケース | is_auto_order | safety_stock | last_3d_qty | 安全在庫ベース | 実績ベース | 備考 |
|---|--------|---------------|-------------|-------------|-------------|-----------|------|
| A1 | 安全在庫ON・在庫不足 | true | 100 | any | 候補生成 | 対象外 | 基本ケース |
| A2 | 安全在庫ON・在庫十分 | true | 100 | any | スキップ | 対象外 | 在庫 >= safety_stock |
| A3 | 発注OFF・実績あり | false | 0 | 50 | 対象外 | 候補生成 | 基本ケース |
| A4 | 発注OFF・実績なし | false | 0 | 0 | 対象外 | スキップ | 実績ゼロ |
| A5 | 発注OFF・安全在庫あり・実績あり | false | 100 | 50 | 対象外 | 候補生成 | safety_stock無視 |
| A6 | 発注ON・安全在庫ゼロ | true | 0 | 50 | スキップ | 対象外 | 設定不備ギャップ（許容） |
| A7 | 販売終了品 | true | 100 | 50 | スキップ | スキップ | `is_ended=true` or `end_of_sale_type != NORMAL` |
| A8 | 販売開始前 | true | 100 | 50 | スキップ | スキップ | `start_of_sale_date > today` |

#### B. 数量計算テスト

| # | ケース | safety_stock / last_3d_qty | 有効在庫 | 入庫予定 | 期待不足数 | purchase_unit | 期待発注数 |
|---|--------|---------------------------|---------|---------|-----------|--------------|-----------|
| B1 | 基本不足 | 100 | 30 | 0 | 70 | 1 | 70 |
| B2 | 入庫予定考慮 | 100 | 30 | 20 | 50 | 1 | 50 |
| B3 | 仕入単位切り上げ | 100 | 30 | 0 | 70 | 12 | 72 |
| B4 | ケース変換 | 100 | 30 | 0 | 70 | 1 | 70バラ → Nケース（capacity_case で割り切り上げ） |
| B5 | 実績ベース・在庫十分 | last_3d=50 | 60 | 0 | 0 | 1 | スキップ |
| B6 | 実績ベース・不足 | last_3d=50 | 20 | 0 | 30 | 1 | 30 |

#### C. INTERNAL/EXTERNAL分岐テスト

| # | ケース | 発注先タイプ | 期待出力テーブル | 検証ポイント |
|---|--------|------------|----------------|-------------|
| C1 | INTERNAL発注先 | INTERNAL | `wms_stock_transfer_candidates` | `hub_warehouse_id` が supply_warehouse_id |
| C2 | EXTERNAL発注先 | EXTERNAL | `wms_order_candidates` | 移動候補の入出庫が反映 |
| C3 | INTERNAL+EXTERNAL混在 | 両方 | 両テーブル | 移動候補がEXTERNAL計算に影響 |

#### D. batch_code共有テスト

| # | ケース | 手順 | 検証ポイント |
|---|--------|------|-------------|
| D1 | 安全在庫→実績の順 | 安全在庫ベース実行 → 実績ベース実行 | 同一batch_code |
| D2 | 実績のみ | 実績ベースのみ実行 | 新規batch_code |
| D3 | 2回連続実績 | 安全在庫 → 実績 → 実績 | 2回目も同じbatch_code |

#### E. origin_type テスト

| # | ケース | 期待origin_type |
|---|--------|----------------|
| E1 | ウェブ安全在庫ベース | MANUAL_SAFETY_STOCK |
| E2 | ウェブ実績ベース | MANUAL_SALES_BASED |

#### F. 発注CD検証テスト

| # | ケース | 検証ポイント |
|---|--------|-------------|
| F1 | is_used_for_ordering=true | 候補の`ordering_code`がsearch_stringと一致 |
| F2 | is_used_for_ordering=false | 候補の`ordering_code`がnull |
| F3 | 13桁ゼロパディング | search_string が短い場合13桁にパディング |

#### G. 重複チェックテスト

| # | ケース | 検証ポイント |
|---|--------|-------------|
| G1 | 同一商品×倉庫×発注先 | 1レコードのみ |
| G2 | 安全在庫+実績で同一商品 | 両方に出ない（is_auto_orderで排他） |

---

## テスト実行方針

### テストデータ生成

本番データ（`item_contractors`, `stats_item_warehouse_sales_summaries` 等）がそのまま入っているため、マスタの変更・追加は不要。テスト用Artisanコマンド `wms:test-auto-order` を新規作成し、以下を実行：

1. **候補テーブルのtruncate**（`wms_stock_transfer_candidates`, `wms_order_candidates`, `wms_order_calculation_logs`, `wms_auto_order_job_controls`）
2. **既存マスタデータの分析**（テストケースに該当する商品を自動検出・分類）
3. **各サービスの直接呼び出し**（Queue経由ではなく同期実行）
4. **結果検証**（DB直接クエリで候補を取得し、期待値と比較）
5. **全32倉庫**（自動発注有効倉庫すべて）を対象に実行

### テスト結果の出力

テスト結果を以下のMarkdownテーブルで出力：

```markdown
| ケース | 結果 | 生成数 | 期待数 | 詳細 |
|--------|------|--------|--------|------|
| A1 | PASS/FAIL | N | N | エラー内容 |
```

### エラーハンドリング

- **致命的エラー**（サービス起動不可、DB接続エラー）→ テスト中断
- **ケースエラー**（期待値不一致、重複検出）→ 記録して次のケースに進む

---

## エージェントチーム構成

| 役割 | モデル | 責務 |
|------|--------|------|
| **テスト設計・監視** | Opus | テストケース設計、結果分析、最終レポート作成 |
| **テストコマンド実装** | Sonnet | Artisanコマンド作成、テストデータ生成ロジック |
| **テスト実行・検証** | Sonnet | テスト実行、DB検証クエリ、結果収集 |

### 実行フロー

```
Opus: テスト計画確定・ケース設計
  ↓
Sonnet: テストコマンド実装 + テストデータ生成
  ↓
Sonnet: テスト実行 + 結果収集
  ↓
Opus: 結果分析 + レポート作成
```

---

## 制約

1. **`migrate:fresh` / `migrate:refresh` 禁止** — 本番データ保護
2. **マスタテーブルのtruncate禁止** — `item_contractors`, `items`, `contractors` 等
3. **候補テーブルのtruncateはOK** — `wms_stock_transfer_candidates`, `wms_order_candidates`, `wms_order_calculation_logs`, `wms_auto_order_job_controls`
4. **テスト後のデータ復元** — `item_contractors` を一時変更した場合は必ず元に戻す
5. **DB接続** — `sakemaru` コネクション使用（`config/database.php`）

---

## 対象ファイル

### 新規作成

| ファイル | 説明 |
|---------|------|
| `app/Console/Commands/AutoOrder/TestAutoOrderCommand.php` | テスト実行Artisanコマンド |
| `storage/specifications/20260422/20260422-auto-order-integration-test/test-results.md` | テスト結果レポート |

### 参照のみ

| ファイル | 参照理由 |
|---------|---------|
| `app/Services/AutoOrder/OrderCandidateCalculationService.php` | 安全在庫ベーステスト対象 |
| `app/Services/AutoOrder/SalesBasedOrderCandidateService.php` | 実績ベーステスト対象 |
| `app/Enums/AutoOrder/OriginType.php` | origin_type検証 |
| `app/Enums/AutoOrder/CandidateStatus.php` | ステータス検証 |

---

## 確認済み事項

| # | 項目 | 回答 |
|---|------|------|
| 1 | テスト用倉庫 | 自動発注有効な全32倉庫を使用（IDs: 1-11,21,22,63,71-75,80,89-98,100,101） |
| 2 | item_contractors | 本番データがそのまま入っている。既存データをそのまま利用してテストする（一時変更・追加不要） |
| 3 | automator@sakemaru.ai | DB上に存在（ID: 9900000003, Name: Automator） |
