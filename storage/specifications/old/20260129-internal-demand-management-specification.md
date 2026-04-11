# 社内商品需要管理機能 仕様書

> **対象:** Trade開発チーム
> **作成:** WMS開発チーム
> **日付:** 2026-01-29

---

## 背景

### 業務課題

各店舗が個別に商品を発注すると、以下の問題が発生：
- 発注ロットが小さく、仕入れコストが高い
- 店舗間で在庫の偏りが発生
- 人気商品の需要予測が困難

### 解決策

本部（商品企画部）が各店舗の需要を事前に集約し、一括発注後に各店舗へ分配する仕組みを構築する。

---

## 重要な設計原則

### WMSの独立性について

**WMSは店舗管理機能を一切持たない。**

WMSはTradeシステムなしでも単独で動作するシステムとして設計されている。

| WMSが管理するもの | WMSが管理しないもの |
|------------------|---------------------|
| 倉庫（warehouse） | 店舗としてのビジネスロジック |
| 在庫（stock） | 店舗担当者 |
| 入庫・出庫 | 需要管理 |
| ピッキング | 店舗固有の権限 |
| 在庫移動 | |

店舗がたまたま倉庫として登録されている場合があるが、WMSはそれを「倉庫」として扱う。店舗固有のビジネスロジック（需要管理、店舗担当者管理等）はすべて**Trade側で管理**する必要がある。

### システム間の責務分担

| システム | 責務 |
|----------|------|
| **Trade** | 需要管理UI、店舗管理、担当者管理、分配決定、移動依頼の作成指示 |
| **WMS** | 在庫管理、入庫処理、移動依頼の実行、ピッキング、Queue処理 |

---

## WMS側の現状と対応内容

### 既存機能

| 機能 | テーブル | 状態 |
|------|----------|------|
| 在庫移動依頼 | `wms_stock_transfer_requests` | 実装済み |
| 在庫移動明細 | `wms_stock_transfer_items` | 実装済み |
| 入庫予定 | `wms_order_incoming_schedules` | 実装済み |
| ピッキング | `wms_picking_tasks` | 実装済み |

### 今回WMS側で対応する内容

#### 1. 共通Queue管理機能（新規）

Trade側からの依頼を非同期で処理するためのQueue基盤を構築。

**テーブル:**
- `wms_queue_jobs` - ジョブ管理
- `wms_queue_job_logs` - 実行ログ

**画面:**
- Queue実行状況一覧（`/admin/queue-jobs`）
  - ジョブ一覧（ステータス別フィルタ）
  - 実行ログ表示
  - 失敗ジョブの再実行

#### 2. 在庫移動依頼テーブルの拡張

`wms_stock_transfer_requests` に以下カラムを追加：

| カラム | 型 | 説明 |
|--------|-----|------|
| source_type | varchar(20) | 依頼元種別（manual/demand_distribution/auto） |
| source_queue_job_id | bigint | FK: wms_queue_jobs.id |
| source_user_id | bigint | 依頼元ユーザーID（誰が実施したか） |

#### 3. 需要分配ジョブハンドラ

Trade側からQueueに投入されたジョブを処理し、在庫移動依頼を作成する。

---

## 業務フロー

### 望ましい需要処理の流れ

```
[Trade側]                                    [WMS側]
    │                                            │
    ▼                                            │
1. 商品企画部が需要依頼を作成                      │
   （対象商品・店舗・締切日を指定）                │
    │                                            │
    ▼                                            │
2. 各店舗担当者に依頼通知                         │
    │                                            │
    ▼                                            │
3. 店舗担当者が需要数を入力・提出                  │
   （店舗別の希望数はあくまで参考）                │
    │                                            │
    ▼                                            │
4. 商品企画部がリアルタイムで入力状況を確認         │
   （未入力店舗、入力済み店舗、合計数）            │
    │                                            │
    ▼                                            │
5. 締切実施（入力受付終了）                       │
    │                                            │
    ▼                                            │
┌───────────────────────────────────────────────────────────────────┐
│ Step 6: 発注クリック                                              │
│   商品別の発注数量を設定                                          │
│   （店舗別の要求はあくまでも参考）                                 │
└───────────────────────────────────────────────────────────────────┘
    │                                            │
    ▼                                            │
┌───────────────────────────────────────────────────────────────────┐
│ Step 7: 発注データ生成依頼                                        │
│   wms_queue_jobs に job_type='order_create' を投入                │
└───────────────────────────────────────────────────────────────────┘
    │                                            │
    │                                            ▼
    │                                   ┌────────────────────────┐
    │                                   │ 8. Queueジョブ処理      │
    │                                   │   発注候補を作成        │
    │                                   │   (wms_order_candidates)│
    │                                   └────────────────────────┘
    │                                            │
    │                                            ▼
    │                                   9. 発注候補の承認・確定
    │                                   10. 発注送信（JX等）
    │                                   11. 入庫処理
    │                                            │
    ▼                                            │
┌───────────────────────────────────────────────────────────────────┐
│ Step 12: 分配機能                                                 │
│   入庫後、各店舗別に分配する数量を設定                             │
│   （発注完了後に実施）                                            │
└───────────────────────────────────────────────────────────────────┘
    │                                            │
    ▼                                            │
┌───────────────────────────────────────────────────────────────────┐
│ Step 13: 分配確定                                                 │
│   wms_queue_jobs に job_type='demand_distribution' を投入         │
└───────────────────────────────────────────────────────────────────┘
    │                                            │
    │                                            ▼
    │                                   ┌────────────────────────┐
    │                                   │ 14. Queueジョブ処理     │
    │                                   │   在庫移動依頼を作成    │
    │                                   │ (stock_transfer_requests)│
    │                                   └────────────────────────┘
    │                                            │
    │                                            ▼
    │                                   15. ピッキング・出庫処理
    │                                            │
    ▼                                            │
16. 完了状況確認（DB参照） ◀─────────────────────┘
```

### ポイント

1. **発注クリック時**: 商品別の発注数量を設定できる（店舗別の要求はあくまでも参考）
2. **発注データ生成**: WMS側のQueueテーブル（`wms_queue_jobs`）を利用
3. **分配機能**: 入庫完了後、各店舗別に分配する数量を設定可能
4. **分配確定**: WMS側のQueueテーブルを利用して移動依頼データを生成

---

## システム連携方式

### Queue方式を採用（API不使用）

```
Trade                          共有DB                           WMS
  │                              │                              │
  │  1. 移動依頼データ登録        │                              │
  │─────────────────────────────▶│ ret_demand_distribution_     │
  │                              │ requests                     │
  │                              │                              │
  │  2. Queueジョブ登録          │                              │
  │─────────────────────────────▶│ wms_queue_jobs               │
  │                              │                              │
  │                              │  3. ジョブ取得・実行          │
  │                              │◀─────────────────────────────│
  │                              │                              │
  │                              │  4. 移動依頼作成              │
  │                              │◀─────────────────────────────│
  │                              │ wms_stock_transfer_requests  │
  │                              │                              │
  │                              │  5. 実行ログ記録              │
  │                              │◀─────────────────────────────│
  │                              │ wms_queue_job_logs           │
  │                              │                              │
  │  6. 結果確認（DB参照）        │                              │
  │◀─────────────────────────────│                              │
```

**採用理由:**
1. APIを使わない（システム間の依存を最小化）
2. ロジックはWMS側が管理（在庫移動の作成ロジックはWMSの責務）
3. 既存の発注バッチ処理に倉庫移動を追加する形で実装可能
4. 実行ログがWMS側で一元管理できる

---

## Trade側で実装が必要な内容

### データベーステーブル（ret_プレフィックス）

#### ret_demand_requests（需要依頼）

| カラム | 型 | 説明 |
|--------|-----|------|
| id | bigint | PK |
| code | varchar(20) | 依頼コード（自動採番） |
| title | varchar(255) | 依頼タイトル |
| description | text | 説明・備考 |
| status | enum | draft/open/closed/ordered/distributing/completed |
| created_by | bigint | 作成者ID |
| deadline_at | datetime | 入力締切日時 |
| closed_at | datetime | 実際の締切日時 |
| ordered_at | datetime | 発注日時 |
| completed_at | datetime | 完了日時 |
| created_at | datetime | 作成日時 |
| updated_at | datetime | 更新日時 |

#### ret_demand_request_items（需要依頼商品）

| カラム | 型 | 説明 |
|--------|-----|------|
| id | bigint | PK |
| demand_request_id | bigint | FK: 需要依頼ID |
| item_id | bigint | 商品ID |
| order_quantity | int | 発注数量（確定後） |
| received_quantity | int | 入庫数量（WMS参照） |
| created_at | datetime | 作成日時 |
| updated_at | datetime | 更新日時 |

#### ret_demand_request_stores（需要依頼対象店舗）

| カラム | 型 | 説明 |
|--------|-----|------|
| id | bigint | PK |
| demand_request_id | bigint | FK: 需要依頼ID |
| warehouse_id | bigint | 倉庫ID（＝店舗） |
| status | enum | pending/submitted |
| submitted_at | datetime | 提出日時 |
| submitted_by | bigint | 提出者ID |
| created_at | datetime | 作成日時 |
| updated_at | datetime | 更新日時 |

#### ret_demand_entries（需要入力）

| カラム | 型 | 説明 |
|--------|-----|------|
| id | bigint | PK |
| demand_request_id | bigint | FK: 需要依頼ID |
| demand_request_item_id | bigint | FK: 需要依頼商品ID |
| warehouse_id | bigint | 倉庫ID（＝店舗） |
| requested_quantity | int | 要望数量（店舗入力） |
| allocated_quantity | int | 分配数量（確定後） |
| note | text | 備考 |
| created_at | datetime | 作成日時 |
| updated_at | datetime | 更新日時 |

#### ret_demand_distribution_requests（分配依頼）

| カラム | 型 | 説明 |
|--------|-----|------|
| id | bigint | PK |
| demand_request_id | bigint | FK: 需要依頼ID |
| source_warehouse_id | bigint | 出庫元倉庫ID |
| target_warehouse_id | bigint | 出庫先倉庫ID（店舗） |
| item_id | bigint | 商品ID |
| quantity | int | 移動数量 |
| status | enum | pending/queued/processing/completed/failed |
| queue_job_id | bigint | FK: wms_queue_jobs.id |
| transfer_request_id | bigint | FK: wms_stock_transfer_requests.id（WMS側で作成後に更新） |
| created_by | bigint | 作成者ID |
| created_at | datetime | 作成日時 |
| updated_at | datetime | 更新日時 |

### 画面

#### 1. 需要依頼一覧（商品企画部向け）
- パス: `/admin/demand-requests`
- 依頼の一覧・作成・管理

#### 2. 需要依頼詳細（商品企画部向け）
- パス: `/admin/demand-requests/{id}`
- 入力状況確認（リアルタイム）
- 締切・発注操作

#### 3. 需要入力（店舗向け）
- パス: `/admin/demand-entries/{demand_request_id}`
- 自店舗の需要数入力・提出

#### 4. 分配設定（商品企画部向け）
- パス: `/admin/demand-requests/{id}/distribute`
- 入庫確認後、手動で店舗別分配数を決定
- WMS Queueへジョブ投入

### Queueジョブ投入処理

Trade側からWMS側のQueueにジョブを投入する処理。共有DBに直接書き込む。

```php
// Trade側のサービスクラス例
class DemandDistributionService
{
    public function submitDistribution(DemandRequest $demandRequest, array $distributions): void
    {
        DB::transaction(function () use ($demandRequest, $distributions) {
            // 1. ret_demand_distribution_requests に登録
            $distributionRequests = [];
            foreach ($distributions as $dist) {
                $distributionRequests[] = DemandDistributionRequest::create([
                    'demand_request_id' => $demandRequest->id,
                    'source_warehouse_id' => $dist['source_warehouse_id'],
                    'target_warehouse_id' => $dist['target_warehouse_id'],
                    'item_id' => $dist['item_id'],
                    'quantity' => $dist['quantity'],
                    'status' => 'pending',
                    'created_by' => auth()->id(),
                ]);
            }

            // 2. wms_queue_jobs にジョブ登録（共有DB）
            $queueJobId = DB::connection('sakemaru')->table('wms_queue_jobs')->insertGetId([
                'job_type' => 'demand_distribution',
                'payload' => json_encode([
                    'demand_request_id' => $demandRequest->id,
                    'distributions' => collect($distributionRequests)->map(fn ($r) => [
                        'distribution_request_id' => $r->id,
                        'source_warehouse_id' => $r->source_warehouse_id,
                        'target_warehouse_id' => $r->target_warehouse_id,
                        'item_id' => $r->item_id,
                        'quantity' => $r->quantity,
                    ])->toArray(),
                    'scheduled_date' => now()->addDay()->format('Y-m-d'),
                ]),
                'status' => 'pending',
                'priority' => 10,
                'attempts' => 0,
                'max_attempts' => 3,
                'source_system' => 'trade',
                'source_user_id' => auth()->id(),
                'source_reference_type' => 'ret_demand_requests',
                'source_reference_id' => $demandRequest->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. distribution_requestsにqueue_job_idを設定
            foreach ($distributionRequests as $r) {
                $r->update([
                    'queue_job_id' => $queueJobId,
                    'status' => 'queued',
                ]);
            }

            // 4. 依頼ステータス更新
            $demandRequest->update(['status' => 'distributing']);
        });
    }
}
```

---

## WMS側Queueテーブル仕様

### wms_queue_jobs

| カラム | 型 | 説明 |
|--------|-----|------|
| id | bigint | PK |
| job_type | varchar(50) | ジョブ種別（demand_distribution, auto_order, etc） |
| payload | json | ジョブパラメータ |
| status | enum | pending/processing/completed/failed |
| priority | int | 優先度（0=最高） |
| attempts | int | 試行回数 |
| max_attempts | int | 最大試行回数 |
| source_system | varchar(20) | 依頼元システム（trade/wms/batch） |
| source_user_id | bigint | 依頼元ユーザーID |
| source_reference_type | varchar(50) | 依頼元参照テーブル |
| source_reference_id | bigint | 依頼元参照ID |
| started_at | datetime | 処理開始日時 |
| completed_at | datetime | 処理完了日時 |
| created_at | datetime | 作成日時 |
| updated_at | datetime | 更新日時 |

### wms_queue_job_logs

| カラム | 型 | 説明 |
|--------|-----|------|
| id | bigint | PK |
| queue_job_id | bigint | FK: wms_queue_jobs.id |
| level | enum | info/warning/error |
| message | text | ログメッセージ |
| context | json | コンテキスト情報 |
| created_at | datetime | 作成日時 |

---

## ジョブタイプ定義

### job_type: order_create（発注候補作成）

外部システム（Trade等）から発注候補を作成する。
WMS管理画面の「発注追加」ボタンと同じ処理をQueue経由で実行。

#### Payload構造

```json
{
  "items": [
    {
      "warehouse_id": 1,
      "item_id": 100,
      "quantity": 24,
      "note": "需要依頼からの発注"
    },
    {
      "warehouse_id": 1,
      "item_id": 200,
      "quantity": 48,
      "note": "需要依頼からの発注"
    }
  ],
  "demand_request_id": 123
}
```

| フィールド | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| items | array | ○ | 発注商品リスト |
| items[].warehouse_id | int | ○ | 発注倉庫ID |
| items[].item_id | int | ○ | 商品ID |
| items[].quantity | int | ○ | 発注数量（バラ数） |
| items[].note | string | - | 備考 |
| demand_request_id | int | - | Trade側の需要依頼ID（参照用） |

#### 処理フロー

```
1. payloadからitemsを取得
2. 利用可能なbatch_code（実行CD）を取得または新規作成
   - WmsAutoOrderJobControl::findPendingSettlement() で確定待ちジョブを検索
   - なければ StockSnapshotService::generateAll() で新規作成
3. 各itemについてループ処理:
   a. 発注先（contractor）をitem_contractorsテーブルから取得
      ※ 仮想倉庫の場合はstock_warehouse_idを使用
   b. 入数（capacity_case）の倍数チェック
   c. 重複チェック（同一倉庫・商品・PENDING状態）
   d. リードタイムから入荷予定日を計算
   e. wms_order_candidates に INSERT（status: PENDING）
   f. wms_order_calculation_logs に記録
4. 結果をwms_queue_job_logsに記録
5. ジョブステータスをcompleted/failedに更新
```

#### エラーケース

| エラー | 処理 |
|--------|------|
| 発注先未設定 | 該当itemをスキップ、ログに記録、他のitemは処理継続 |
| 入数倍数エラー | 該当itemをスキップ、ログに記録 |
| 重複発注候補 | 該当itemをスキップ、ログに記録 |
| 商品未存在 | 該当itemをスキップ、ログに記録 |
| 倉庫未存在 | 該当itemをスキップ、ログに記録 |

#### 結果レスポンス（wms_queue_job_logs.context）

```json
{
  "batch_code": "20260130-001",
  "total_items": 10,
  "success_count": 8,
  "skip_count": 2,
  "results": [
    {
      "warehouse_id": 1,
      "item_id": 100,
      "status": "created",
      "candidate_id": 1234
    },
    {
      "warehouse_id": 1,
      "item_id": 200,
      "status": "skipped",
      "reason": "発注先が設定されていません"
    }
  ]
}
```

---

### job_type: transfer_create（移動候補作成）

外部システム（Trade等）から移動候補を作成する。
WMS管理画面の「移動追加」ボタンと同じ処理をQueue経由で実行。

#### Payload構造

```json
{
  "items": [
    {
      "satellite_warehouse_id": 10,
      "hub_warehouse_id": 1,
      "item_id": 100,
      "transfer_quantity": 24,
      "expected_arrival_date": "2026-01-31",
      "delivery_course_id": 5,
      "note": "需要依頼からの移動"
    }
  ],
  "expected_arrival_date": "2026-01-31",
  "demand_request_id": 123
}
```

| フィールド | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| items | array | ○ | 移動商品リスト |
| items[].satellite_warehouse_id | int | ○ | 依頼倉庫ID（移動先） |
| items[].hub_warehouse_id | int | ○ | 移動元倉庫ID |
| items[].item_id | int | ○ | 商品ID |
| items[].transfer_quantity | int | ○ | 移動数量（バラ数） |
| items[].expected_arrival_date | date | - | 移動出荷日（省略時はpayload.expected_arrival_dateまたは翌日） |
| items[].delivery_course_id | int | - | 配送コースID |
| items[].note | string | - | 備考 |
| expected_arrival_date | date | - | デフォルト移動出荷日 |
| demand_request_id | int | - | Trade側の需要依頼ID（参照用） |

#### 処理フロー

```
1. payloadからitemsを取得
2. 利用可能なbatch_code（実行CD）を取得または新規作成
   - WmsAutoOrderJobControl::findPendingSettlement() で確定待ちジョブを検索
   - なければ StockSnapshotService::generateAll() で新規作成
3. 各itemについてループ処理:
   a. 依頼倉庫と移動元倉庫が同じでないかチェック
   b. 倉庫・商品の存在確認
   c. 重複チェック（同一依頼倉庫・移動元倉庫・商品・PENDING状態）
   d. wms_stock_transfer_candidates に INSERT（status: PENDING）
   e. wms_order_calculation_logs に記録
4. 結果をwms_queue_job_logsに記録
5. ジョブステータスをcompleted/failedに更新
```

#### エラーケース

| エラー | 処理 |
|--------|------|
| 依頼倉庫=移動元倉庫 | 該当itemをスキップ、ログに記録 |
| 依頼倉庫未存在 | 該当itemをスキップ、ログに記録 |
| 移動元倉庫未存在 | 該当itemをスキップ、ログに記録 |
| 商品未存在 | 該当itemをスキップ、ログに記録 |
| 重複移動候補 | 該当itemをスキップ、ログに記録 |

#### 結果レスポンス（wms_queue_job_logs.context）

```json
{
  "batch_code": "20260130-001",
  "total_items": 10,
  "success_count": 8,
  "skip_count": 2,
  "results": [
    {
      "satellite_warehouse_id": 10,
      "hub_warehouse_id": 1,
      "item_id": 100,
      "status": "created",
      "candidate_id": 1234
    },
    {
      "satellite_warehouse_id": 10,
      "hub_warehouse_id": 1,
      "item_id": 200,
      "status": "skipped",
      "reason": "商品が存在しません"
    }
  ]
}
```

---

### job_type: demand_distribution（分配・移動依頼作成）

店舗への分配決定後、在庫移動依頼を作成する。

#### Payload構造

```json
{
  "demand_request_id": 123,
  "distributions": [
    {
      "distribution_request_id": 1,
      "source_warehouse_id": 1,
      "target_warehouse_id": 10,
      "item_id": 100,
      "quantity": 12
    }
  ],
  "scheduled_date": "2026-01-31"
}
```

| フィールド | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| demand_request_id | int | ○ | Trade側の需要依頼ID |
| distributions | array | ○ | 分配リスト |
| distributions[].distribution_request_id | int | ○ | ret_demand_distribution_requests.id |
| distributions[].source_warehouse_id | int | ○ | 出庫元倉庫ID |
| distributions[].target_warehouse_id | int | ○ | 出庫先倉庫ID（店舗） |
| distributions[].item_id | int | ○ | 商品ID |
| distributions[].quantity | int | ○ | 移動数量 |
| scheduled_date | date | - | 予定日 |

#### 処理フロー

```
1. payloadからdistributionsを取得
2. 出庫元倉庫ごとにグルーピング
3. 各グループでwms_stock_transfer_requestsを作成
4. 明細をwms_stock_transfer_itemsに作成
5. Trade側のret_demand_distribution_requestsを更新
   - transfer_request_id を設定
   - status を completed に更新
6. 結果をログに記録
```

---

## ステータス遷移

### 需要依頼ステータス（ret_demand_requests.status）

```
draft（下書き）
  │
  ▼ [公開]
open（入力受付中）
  │
  ▼ [締切]
closed（締切済み）
  │
  ▼ [発注登録]
ordered（発注済み）
  │
  ▼ [入庫確認・分配開始]
distributing（分配中）
  │
  ▼ [移動完了]
completed（完了）
```

### 分配依頼ステータス（ret_demand_distribution_requests.status）

```
pending（作成済み）
  │
  ▼ [Queue投入]
queued（Queue待機中）
  │
  ▼ [WMS処理開始]
processing（処理中）
  │
  ├──▶ completed（完了）
  │
  └──▶ failed（失敗）
```

---

## 権限管理（将来対応）

現段階では権限区分は実装しない。今後、以下の機能を追加予定：

- 商品企画部ロールの定義
- 店舗担当者ロールの定義
- ロールに基づくアクセス制御

---

## 分配ルール

**基本は手動分配。**

管理者が入庫を確認後、以下の情報を見ながら手動で分配数を決定：
- 各店舗の希望数
- 実際の入庫数
- 在庫状況

自動按分機能は将来的に追加検討。

---

## 開発フェーズ案

### Phase 1: 基盤整備（WMS側）
- [ ] wms_queue_jobs, wms_queue_job_logs テーブル作成
- [ ] Queue実行状況確認画面
- [ ] Queueジョブ処理コマンド
- [ ] wms_stock_transfer_requests のカラム追加

### Phase 2: 需要管理（Trade側）
- [ ] ret_demand_* テーブル作成
- [ ] 需要依頼一覧・作成画面
- [ ] 需要入力画面
- [ ] 入力状況確認画面

### Phase 3: 分配機能（両システム）
- [ ] WMS: demand_distribution ジョブハンドラ
- [ ] Trade: 分配設定画面
- [ ] Trade: Queueジョブ投入処理
- [ ] 結合テスト

### Phase 4: 運用機能
- [ ] Trade: 依頼完了処理
- [ ] 通知機能（将来）
- [ ] 権限管理（将来）

---

## 確認・検討事項

1. **テーブル設計**: `ret_demand_*` テーブルの設計で問題ないか？追加カラムは？
2. **画面フロー**: 需要依頼の作成〜分配までのUI/UXイメージ
3. **発注処理**: 発注はどのように行うか？既存機能との連携方法
4. **入庫確認**: WMS側の入庫データをどのように参照するか

---

## 参考：関連テーブル

### 基幹システム（プレフィックスなし）
- `warehouses` - 倉庫マスタ（店舗も含む）
- `items` - 商品マスタ
- `users` - ユーザーマスタ

### WMSシステム（wms_プレフィックス）
- `wms_stock_transfer_requests` - 在庫移動依頼
- `wms_stock_transfer_items` - 在庫移動明細
- `wms_order_incoming_schedules` - 入庫予定
