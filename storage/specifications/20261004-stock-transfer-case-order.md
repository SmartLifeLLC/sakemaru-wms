# 店間発注: コード検索の拡張とケース発注（2026-10-04）

対象画面: 店間発注（`/admin/wms-stock-transfer-candidates`）

## 1. 個別発注を追加: コード検索

- 検索欄のラベルを「JANコード・自社コード等」、プレースホルダーを「単品CD・自社CDも検索可」に変更（（新）外部発注と同じ）。
- 探し方は（新）外部発注の発注候補検索と同じ（`App\Services\AutoOrder\ItemCodeSearchService`）。
  - スペース・カンマ・読点・スラッシュ・改行区切りで複数コードを指定できる。全角は半角に直す。
  - 完全一致する商品があればそれを優先し、完全一致が無いコードだけ部分一致で探す。
  - 対象: 検索コード（`item_search_information.search_string`）、入数情報（`item_quantity_information`）の単品CD・自社CD・入数CD（入数CD は4桁以上のみ完全一致の対象）。
  - 13桁コードは先頭の 0 を除いた形でも探す。
- （新）外部発注側のコードは変更していない（同じ処理を別クラスに移植）。

## 2. ケース発注

### 入力

- ケースかバラのどちらか一方だけ入力できる。片方に数量を入れると、もう片方は空になる。
- 総バラ数（ケース数 × 入数、バラはそのまま）を入力欄の隣に表示する。
- 対象の入力箇所
  - 個別発注を追加
  - 物流発注候補リスト（販売実績からの候補作成）
  - 発注数量編集
  - 一覧の「詳細」（数量と単位を変更できる）
- サーバー側でも「ケースとバラはどちらか一方だけ入力してください」をチェックする。
- 入数が 0 の商品はケース発注できない（ケース欄を無効化し、サーバー側でも拒否する）。基幹側は入数をそのまま掛けるため、入数 0 のケース移動は総バラ数が 0 になる。判定は `WmsStockTransferCandidate::canOrderByCase()`。

### 保存する値

- `wms_stock_transfer_candidates.transfer_quantity` は発注した単位の数量、`quantity_type` は `CASE` / `PIECE`。
  - 例: 入数 12 の商品を 3 ケース → `transfer_quantity = 3`, `quantity_type = CASE`（総バラ 36）。
- バラ発注の保存内容は従来と同じ。
- 計算ログ（`wms_order_calculation_logs`）の数量は総バラ数。ケース発注のときだけ、発注単位の数量・入数・総バラ数を詳細に追記する。

### 移動伝票への連携（変更なし）

- 承認 → 発注確定で `stock_transfer_queue.items` に `{item_code, quantity, quantity_type, ...}` を渡す処理（`TransferCandidateExecutionService`）は変更していない。ケース発注は `quantity = ケース数`, `quantity_type = "CASE"` で渡る。
- 基幹側（`ProcessStockTransfer`）がケース数 × 入数で総バラ数を計算し、移動伝票を作る。
- 入荷予定（`wms_order_incoming_schedules`）は `expected_quantity = ケース数`, `quantity_type = CASE`。

### 表示

- 一覧: 「発注数」を「N ケース / N バラ」で表示し、「総バラ数」列を追加。
- 発注確定待ち（移動タブ）: 「移動数」に単位を付け、「総バラ数」列を追加。

### バラ換算して合計する箇所

ケースの候補・入荷予定が混ざっても数量が狂わないよう、合計する箇所はバラに換算する。

- 入荷予定数（個別発注の検索結果、物流発注候補リストの見込在庫）
- サテライト需要の合計（`TransferOrderRecalculationService`）
- 自動発注計算が読み込む移動候補の数量（`OrderCandidateCalculationService`, `SalesBasedOrderCandidateService`）
- 承認時のハブ在庫チェック（`TransferCandidateApprovalService`）

換算は `WmsStockTransferCandidate::toPieceQuantity()` / `pieceQuantitySql()` にまとめている。入数が未設定（0 以下）のときは 1 として扱う。

## 3. テスト

- `tests/Unit/Models/WmsStockTransferCandidatePieceQuantityTest.php`
- `tests/Unit/Services/AutoOrder/ItemCodeSearchServiceTest.php`
- `tests/Unit/Filament/StockTransferCaseOrderViewTest.php`
