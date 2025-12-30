
## 0. Design Principles (Core Concept)
本システムの核となる設計思想。
各倉庫の在庫状況、安全在庫、LT、およびLot制約に基づき、最適な発注・移動数を算出する。


## 1. System Overview
各倉庫の在庫状況、安全在庫設定、および入荷リードタイムに基づき、最適な発注数を自動計算するシステム。
拠点倉庫（Hub）と非拠点倉庫（Satellite）の階層構造に対応する。

---

## 2. Business Flow & Timeline


### Phase 0: create order process  and calculate all stocks
- 発注計算管理テーブル(wms_auto_order_job_controls)
- 全ての倉庫に対して、倉庫x商品別の合計在庫数 (total piece)をwms_warehouse_item_total_stocks計算し保存する。

- これをベースに発注データの計算をおこなう。


### **Phase 1: Satellite Warehouse Calculation (Default 10:00)
Target: 非拠点倉庫 (Satellite).
Input: 在庫情報, wms_item_warehouse_params.
Process:　安全在庫不足分（理論値）を算出。
Lot Rule Application: 拠点倉庫（供給元）に対する移動ルール（ケース単位など）を適用。
Output: wms_stock_transfer_candidates (移動候補) を作成。
Note: このデータは拠点倉庫計算の入力となる。

### **Phase 2: Hub Warehouse Calculation (Default 10:30)
Target: 拠点倉庫 (Hub).
Input:自倉庫の在庫不足分。
Phase 1で作成された wms_stock_transfer_candidates の合計数量（需要）。
Process:合計必要量を算出。
Lot Rule Application: 外部発注先（Contractor）に対するLot・混載ルールを適用。
Output: wms_order_candidates (発注候補) を作成。

### Phase 3: Review & Modification (Until 12:00)
State: 候補テーブルには「Lot適用済」の推奨値が格納されている。
User Action: 担当者は例外的な状況（特売対応など）がある場合のみ、数量を修正する。
また、Lot Ruleによる変更が発生したものやLotルールを満たしてないものの確認ができるように（絞りができる）
Validation: 手動修正時も、システムはLot違反がないか警告/修正を行うことが望ましいが、ユーザの修正分を正として取り込む


### Phase 4: Execution & Transmission (Default 12:00)
Target: 全ての有効な候補データ（除外フラグ EXCLUDED 以外）。

Process:Satellite分: wms_stock_transfer_candidates を確定させ、実データ stock_transfers へ変換。
stock_tranfersの生成は既存のstock_transfer_queueを利用した生成方法を活用する。その際にstock_transfer_queue.request_idにはorder-{wms_stock_transfer_candidates.id}を登録する。
Hub分: wms_order_candidates を確定させ、発注先設定に基づき JX送信 または CSV生成 を実行。

### **Phase 4: Data Transmission (12:00)**
* **Trigger:** 送信バッチコマンド。
* **Condition:** `status = APPROVED` AND `lot_status != BLOCKED`.
* **Process:** JX手順またはCSV生成による送信。

---
## 2.2 休日管理


各倉庫の「定休日（毎週決まった休み）」および「臨時休業（年末年始、棚卸日など）」を管理する機能。
発注計算バッチにおける「入荷予定日（Arrival Date）」の算出時に使用され、休日に入荷予定日が重なった場合、自動的に翌営業日へシフトさせるために利用する。

**設計方針：展開済みカレンダー方式**
計算実行時のパフォーマンスを最優先するため、複雑なルール判定（曜日判定や祝日判定）を計算中に行わず、あらかじめ「営業日か否か」を展開したカレンダーテーブルを参照する方式を採用する。

### 2.1. 休日ルール設定 (Master Setting)

カレンダーを生成するための「種（ルール）」を管理するテーブル。

```sql
CREATE TABLE wms_warehouse_holiday_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    
    -- ▼ 定休日設定
    -- JSON形式で休みの曜日を保持 (例: [0, 6] = 日・土が休み)
    -- 0=Sun, 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat
    regular_holiday_days JSON NULL COMMENT '定休日の曜日配列',
    
    -- ▼ 祝日設定
    is_national_holiday_closed TINYINT(1) DEFAULT 1 NOT NULL COMMENT '祝日を休業とするか',
    
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    UNIQUE KEY uk_wh (warehouse_id)
) COMMENT '倉庫別休日生成ルール';
```

### 2.2. 展開済みカレンダー (Calculation Source)

計算バッチが実際に参照するテーブル。向こう1年分程度の日付データが倉庫ごとに保持される。

```sql
CREATE TABLE wms_warehouse_calendars (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    
    target_date DATE NOT NULL COMMENT '対象日付',
    
    -- ▼ 判定フラグ (計算時はここだけを見る)
    is_holiday TINYINT(1) DEFAULT 0 NOT NULL COMMENT '休日フラグ (0:営業日, 1:休日)',
    
    holiday_reason VARCHAR(255) NULL COMMENT '休日理由 (定休日, 臨時休業, 〇〇の日など)',
    
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    -- ▼ 高速化用インデックス
    -- 計算バッチは "特定倉庫" の "特定期間" を一括取得するため
    UNIQUE KEY uk_wh_date (warehouse_id, target_date),
    INDEX idx_calc_lookup (warehouse_id, target_date, is_holiday)
) COMMENT '計算用・倉庫別営業日カレンダー';
```

-----

##  ロジック仕様 (Logic Specification)

###  カレンダー生成・更新ロジック (Command)

管理者がルールを変更した際、または定期バッチ（毎日深夜）で実行される。

1.  **対象期間:** `Today` から `Today + 12ヶ月` (365日分)。
2.  **生成プロセス:**
    * `wms_warehouse_holiday_settings` を取得。
    * 対象期間の日付をループ処理。
    * 判定:
        1.  **曜日判定:** `regular_holiday_days` に含まれる曜日か？
        2.  **祝日判定:** `is_national_holiday_closed` = 1 かつ、その日が祝日（システム共通の祝日マスタ参照）か？
    * 結果に基づき `wms_warehouse_calendars` を `UPSERT` (更新または挿入)。
    * *Note:* ユーザーが手動で設定した「臨時休業/臨時営業」の上書きを消さないよう、更新時は注意が必要（手動変更フラグを持たせるか、運用でカバー）。

###  発注計算時の利用ロジック (Calculation Batch)

計算負荷を下げるため、SQLの発行回数を抑制する。

**Step 1: メモリへのプリロード (Pre-loading)**
バッチ開始時、計算対象となる全倉庫の、計算に必要な期間（最大リードタイム分、例: 向こう1ヶ月）のカレンダーを一括取得する。

```php
// バッチ冒頭で実行
$calendarCache = WmsWarehouseCalendar::whereIn('warehouse_id', $targetIds)
    ->whereBetween('target_date', [$today, $maxLeadTimeDate])
    ->get()
    ->groupBy('warehouse_id');
// 構造: $calendarCache[warehouse_id][date_string] = Object;
```

**Step 2: 入荷予定日の決定 (Arrival Date Calculation)**
ある商品の発注計算中、LTを加算した日付が休日だった場合の処理。

1.  **仮日付算出:** `$tempDate = $today->addDays($leadTimeDays);`
2.  **休日チェック:**
    * `$calendarCache[$whId][$tempDate]->is_holiday` が `1` (True) の場合:
    * ループで `$tempDate` を1日ずつ進め、`is_holiday == 0` となる最短の日を探す。
3.  **確定:** 見つかった日を `$finalArrivalDate` とする。
4.  **消費補正:**
    * 日付がずれた日数分（`$finalArrivalDate - $originalTempDate`）の消費予測量を、必要発注数に加算する。

-----

## 4\. UI/UX要件 (Operational Requirements)

### 4.1. 休日設定画面

* **定休日設定:** チェックボックス（月〜日、祝日）で一括設定。保存時にカレンダー再生成を実行。
* **カレンダー表示:**
    * カレンダーUIで営業日/休日を表示。
    * 日付をクリックすることで「休日 ⇔ 営業日」を個別に反転（Toggle）できる機能。
    * これが「臨時休業」「臨時営業」の登録機能となる。

-----

## 3. Database Schema Design

### 3.1. Settings & Master Data

#### `wms_client_settings` (Global Settings)
* `calc_logic_type`: 計算ロジックタイプ。
* `satellite_calc_time`: 非拠点計算開始時刻。
* `hub_calc_time`: 拠点計算開始時刻。


#### `item_contractors`  (すでに存在）
* `item_id`, `warehouse_id`
* `safety_stock`: 安全在庫数。
* `max_stock`: 最大在庫数。
* `is_auto_order`: 自動発注フラグ。(default = true)


#### `wms_warehouse_contractor_settings` (Connection Settings)
* `warehouse_id`, `contractor_id`
* `transmission_type`: `JX_FINET`, `MANUAL_CSV`, `FTP`.
* `wms_order_jx_setting_id`: `wms_order_jx_settings` への参照。
* `wms_order_ftp_setting_id`: `wms_order_ftp_settings` への参照。
* `format_strategy_class`: データ生成クラス名。

#### `wms_order_jx_settings` (JX Connection Settings)
* `van_center`, `client_id`, `server_id`: 接続ID。
* `endpoint_url`: 接続先URL。
* `is_basic_auth`, `basic_user_id`, `basic_user_pw`.
* `from`, `to`: JXエンベロープ宛先。
* `ssl_certification_file`.

### 3.2. Lot & Mixed Load Rules
### wms_auto_order_job_control
このテーブルは、計算バッチが実行されるたびにレコードを作成し、その状態を追跡します。これにより、処理の進捗把握、失敗時の原因特定、そして二重起動の防止（排他制御）が可能になります。

| カラム名,データ型,NULL,説明                                                                                      | 
| ---------------------------------------------------------------------------------------------------------------- | 
| id,BIGINT UNSIGNED,NOT NULL,プライマリキー                                                                       | 
| process_name,VARCHAR(50),NOT NULL,"実行されたプロセスの名称 (SATELLITE_CALC, HUB_CALC, ORDER_TRANSMISSION など)" | 
| batch_code,CHAR(14),NOT NULL,バッチ実行ID (例: YYYYMMDDHHMMSS)                                                   | 
| status,ENUM,NOT NULL,"現在のステータス: PENDING, RUNNING, SUCCESS, FAILED"                                       | 
| started_at,DATETIME,NOT NULL,処理開始日時                                                                        | 
| finished_at,DATETIME,NULL,処理終了日時                                                                           | 
| target_scope,JSON,NULL,"対象倉庫や期間など、実行時のパラメータ (例: {""warehouse_id"": [1, 2, 3]})"              | 
| total_records,INT,NULL,処理対象の総レコード数（進捗管理の分母）                                                  | 
| processed_records,INT,NULL,処理が完了したレコード数（進捗の分子）                                                | 
| error_details,TEXT,NULL,失敗時のエラーメッセージやスタックトレース                                               | 
wms_auto_order_job_control

### wms_warehouse_item_total_stocks
このテーブルは、発注計算の直前に実行される集計バッチによって更新され、「発注計算バッチ (Phase 1 & 2)」が在庫情報を高速に参照するための中間データストアとして機能します。

| カラム名,データ型,NULL,説明                                                           | 
| ------------------------------------------------------------------------------------- | 
| id,BIGINT UNSIGNED,NOT NULL,プライマリキー                                            | 
| warehouse_id,BIGINT UNSIGNED,NOT NULL,倉庫ID                                          | 
| item_id,BIGINT UNSIGNED,NOT NULL,商品ID                                               | 
| snapshot_at,DATETIME,NOT NULL,集計日時（どの時点の在庫かを示す）                      | 
| total_effective_piece,INT,NOT NULL,有効在庫の合計バラ数 (発注計算で利用)              | 
| total_non_effective_piece,INT,NOT NULL,無効在庫（期限切れなど）の合計バラ数（監査用） | 
| total_incoming_piece,INT,NOT NULL,入荷予定の合計バラ数（発注残）                      | 
| last_updated_at,TIMESTAMP,NOT NULL,レコードの最終更新日時                             | 


#### `wms_warehouse_contractor_order_rules`
* `allows_case` (Bool), `allows_piece` (Bool).
* `piece_to_case_rounding`: `CEIL` (Fixed).
* `allows_mixed` (Bool): 混載許可。
* `mixed_unit`: `CASE`/`PIECE`/`NONE`.
* `mixed_limit_qty`: 混載時最低合計数。
* `min_case_qty`, `case_multiple_qty`.
* `min_piece_qty`, `piece_multiple_qty`.
* `below_lot_action`: `ALLOW`, `BLOCK`, `ADD_FEE`, `ADD_SHIPPING`, `NEED_APPROVAL`.
* `handling_fee`, `shipping_fee`.

#### `wms_order_rule_exceptions`
* `target_type`: `ITEM`, `CATEGORY`, `TEMPERATURE`, `BRAND`.
* `priority`: 優先順位。
* (Override columns corresponding to base rules).

### 3.3. Transactional Data

#### `wms_order_candidates` (Extended)
* `suggested_quantity`: 理論値。
* `order_quantity`: 最終値（ロット適用後）。
* `status`: `PENDING`, `APPROVED`, `EXCLUDED`.
* `lot_status`: `RAW`, `ADJUSTED`, `BLOCKED`, `NEED_APPROVAL`.
* `lot_rule_id`, `lot_exception_id`.
* `lot_before_qty`, `lot_after_qty`.
* `lot_fee_type`, `lot_fee_amount`.

#### `wms_order_calculation_logs` (Calc Logs)
* `batch_code`.
* `current_effective_stock`.
* `incoming_quantity`.
* `safety_stock_setting`.
* `lead_time_days`.
* `calculated_order_quantity`.
* `calculation_details` (JSON):
    * `{ "is_sunday_excluded": true, "is_holiday_delivery_available": false, ... }`

#### `wms_order_jx_documents` (JX 送信時に必要。時間になったらこちらをもとにデータを送信)
* `wms_order_jx_setting_id`.
* `status`: `ready`, `sent`, `failed`.
* `file_url`, `log`.





## 4. Calculation Logic (Phase 1 & 2)

### 4.1. Required Quantity Formula
> `Required Qty = (Safety Stock + Consumption during LT) - (Effective Inventory + Incoming Orders)`

### 4.2. Sunday/Holiday Arrival Logic
`Arrival Date` = `Today` + `lead_time_days`.
If `Arrival Date` is Sunday/Holiday:

1.  **Check Warehouse:** Is `wms_warehouse_settings.exclude_sunday_arrival` TRUE?
2.  **Check Supplier:** Is `item_contractors.is_holiday_delivery_available` FALSE?

* If **either** is met: Shift to next business day & Add consumption for shifted days.
* Otherwise: Keep original date.

---

## 5. Lot / Mixed Load Application (Phase 3.5)

### 5.1. Logic Flow
For `APPROVED` candidates:
1.  **Rule Retrieval:** Get `wms_warehouse_contractor_order_rules` & Exceptions.
2.  **Unit Conversion:** Convert Piece to Case (Ceil) if pieces not allowed.
3.  **Mixed Load Check:**
    * `allows_mixed=True`: Aggregate quantities per contractor.
    * `allows_mixed=False`: Check per SKU.
4.  **Lot Validation:** Check Min/Multiple constraints.
5.  **Action:** Apply `below_lot_action` (Block, Fee, etc.).

---


