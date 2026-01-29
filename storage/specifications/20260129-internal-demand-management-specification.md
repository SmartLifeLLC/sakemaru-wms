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
    │                                            │
    ▼                                            │
4. 商品企画部がリアルタイムで入力状況を確認         │
   （未入力店舗、入力済み店舗、合計数）            │
    │                                            │
    ▼                                            │
5. 締切実施（入力受付終了）                       │
    │                                            │
    ▼                                            │
6. 商品企画部が必要在庫数を判断・発注              │
    │                                            │
    │                                            ▼
    │                                        7. 商品入庫（通常の入庫処理）
    │                                            │
    ▼                                            │
8. 管理者が入庫確認                               │
   希望数を見ながら店舗別分配数を手動決定          │
    │                                            │
    ▼                                            │
9. 移動依頼データをDBに登録                       │
   （ret_demand_distribution_requests）          │
    │                                            │
    ▼                                            │
10. WMS Queueへ移動依頼ジョブを投入 ─────────────▶ 11. Queueジョブ受信
    │                                            │
    │                                            ▼
    │                                        12. 在庫移動依頼を作成
    │                                           （wms_stock_transfer_requests）
    │                                            │
    │                                            ▼
    │                                        13. ピッキング・出庫処理
    │                                            │
    ▼                                            │
14. 完了状況確認（DB参照） ◀─────────────────────┘
```

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
