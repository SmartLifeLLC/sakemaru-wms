# DEMAND_DISTRIBUTION Queue対応 & origin_type 導入 & custom-queue統合

- **作成日**: 2026-03-01
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/ordering/20260301-demand-distribution-and-origin-type/`

## 背景・目的

### 問題1: origin_type が不在

現在 `wms_order_candidates` / `wms_stock_transfer_candidates` は `is_manually_modified` (boolean) で手動修正を追跡しているが、レコードの**生成元**（自動計算 / ユーザ手動 / 外部システム連携）を正確に区別できない。

- AUTO生成 → `is_manually_modified = false`（デフォルト）
- USER手動追加 → `is_manually_modified = true`
- Queue経由（trade連携） → `is_manually_modified = true`（手動と同じ扱い）

Queue経由とUI手動追加が同じフラグになるため、運用上の区別が不可能。

### 問題2: DEMAND_DISTRIBUTION ジョブが未実装

`QueueJobType::DEMAND_DISTRIBUTION` がEnum定義済みだが、`ProcessWmsQueueJobsCommand` のハンドラが未実装（TODO状態）。tradeシステムからの分配要求を受け付ける基盤が必要。

### 問題3: Queue実行基盤が独自コマンドに依存

外部システム連携のQueue処理が `php artisan wms:process-queue` という独自コマンドで実装されており、Laravelの標準Queue基盤（`php artisan queue:work`）と統合されていない。

**現状の問題点**:

| 項目 | 現状 | あるべき姿 |
|---|---|---|
| 実行コマンド | `wms:process-queue`（独自） | `queue:work`（Laravel標準） |
| ポーリング | 独自while loop | Laravel Workerのpop()サイクル |
| リトライ | 独自 attempts/max_attempts | Laravel標準のリトライ + 独自テーブルのリトライ |
| 監視 | 独自ログのみ | Laravel Horizon / queue:monitor 等が利用可能 |
| `jobs` テーブル | SQLite上（未活用） | sakemaru DBに統合可能 |
| `wms_queue_jobs` テーブル | 独自ポーリング対象 | custom-queue方式でpop()から自動検知 |

**参照実装**: `sakemaru-ai-core` の custom-queue パターン（`DatabaseWithCustomQueue`）を WMS にも導入する。

## 現状の実装

### wms_order_candidates テーブル

```
is_manually_modified  BOOLEAN  DEFAULT false   -- 手動修正フラグ
modified_by           BIGINT   NULLABLE         -- 修正者ユーザID
modified_at           DATETIME NULLABLE         -- 修正日時
```

### 生成パス（3箇所）

| パス | ファイル | is_manually_modified |
|---|---|---|
| AUTO計算 | `OrderCandidateCalculationService.php:805-831` (insert) | false (デフォルト) |
| USER手動追加 | `ListWmsOrderCandidates.php:500-519` | true |
| Queue ORDER_CREATE | `OrderCreateJobHandler.php:262-281` | true |

### Queue処理コマンド（独自実装）

```
php artisan wms:process-queue [--type=] [--once] [--limit=100]
```

`ProcessWmsQueueJobsCommand.php:148` にて `DEMAND_DISTRIBUTION` は未実装エラーを返す。

### wms_queue_jobs テーブル（sakemaru DB上）

```
id                     BIGINT       -- PK
job_type               VARCHAR(50)  -- order_create / transfer_create / demand_distribution
payload                JSON         -- ジョブパラメータ
status                 VARCHAR(20)  -- pending / processing / completed / failed
priority               INT          -- 優先度（0=最高）
attempts               INT          -- 試行回数
max_attempts           INT          -- 最大試行回数（デフォルト3）
source_system          VARCHAR(20)  -- 依頼元 (trade/wms/batch)
source_user_id         BIGINT       -- 依頼元ユーザID
source_reference_type  VARCHAR(50)  -- 依頼元参照テーブル
source_reference_id    BIGINT       -- 依頼元参照ID
result                 JSON         -- 処理結果
error_message          TEXT         -- エラーメッセージ
```

### Queue基盤の現状

| コンポーネント | 状態 |
|---|---|
| `config/queue.php` database接続 | `driver: database`、`connection: null`（SQLiteのjobsテーブルを使用） |
| `DatabaseWithCustomQueue` | **WMS未導入**（sakemaru-ai-coreにのみ存在） |
| `sakemaru` queue connection | **未定義**（database connectionのみ存在） |
| `ProcessEarningDeliveryQueue` | `->onConnection('sakemaru')` 指定だがqueue connectionが未定義 |
| `ArchiveDepletedLots` | 同上 |

## 変更内容

### 概要

1. `OriginType` Enumを新規作成（AUTO / USER / DIST）
2. `wms_order_candidates` と `wms_stock_transfer_candidates` に `origin_type` カラムを追加
3. 全生成パスで `origin_type` を正しくセット
4. `DemandDistributionJobHandler` を実装
5. リスト画面に生成元を表示
6. **custom-queue基盤を導入し、`wms:process-queue` を `queue:work` に統合**

---

### 詳細設計

#### 1. DB変更

**マイグレーション**: `add_origin_type_to_order_and_transfer_candidates`

```php
// wms_order_candidates
$table->enum('origin_type', ['AUTO', 'USER', 'DIST'])
    ->default('AUTO')
    ->after('is_manually_modified')
    ->comment('生成元: AUTO=自動計算, USER=ユーザ手動, DIST=分配システム');

// wms_stock_transfer_candidates
$table->enum('origin_type', ['AUTO', 'USER', 'DIST'])
    ->default('AUTO')
    ->after('is_manually_modified')
    ->comment('生成元: AUTO=自動計算, USER=ユーザ手動, DIST=分配システム');
```

**既存データの補正**:
マイグレーション内で既存レコードを更新する：
```php
// is_manually_modified = true のレコードは USER とみなす（厳密にはQueue経由も含まれるが最善の推定）
DB::connection('sakemaru')->table('wms_order_candidates')
    ->where('is_manually_modified', true)
    ->update(['origin_type' => 'USER']);

DB::connection('sakemaru')->table('wms_stock_transfer_candidates')
    ->where('is_manually_modified', true)
    ->update(['origin_type' => 'USER']);
```

#### 2. Enum作成

**新規ファイル**: `app/Enums/AutoOrder/OriginType.php`

```php
enum OriginType: string
{
    case AUTO = 'AUTO';
    case USER = 'USER';
    case DIST = 'DIST';

    public function label(): string
    {
        return match ($this) {
            self::AUTO => '自動',
            self::USER => '手動',
            self::DIST => '分配',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AUTO => 'primary',
            self::USER => 'success',
            self::DIST => 'warning',
        };
    }
}
```

#### 3. モデル変更

**WmsOrderCandidate**: `fillable` に `origin_type` 追加、`casts` に `OriginType::class` 追加

**WmsStockTransferCandidate**: 同上

#### 4. 生成パス別 origin_type セット

| パス | ファイル | セット値 |
|---|---|---|
| AUTO計算（EXTERNAL） | `OrderCandidateCalculationService.php` insert配列 (~L815) | `'origin_type' => 'AUTO'` |
| AUTO計算（INTERNAL） | `OrderCandidateCalculationService.php` insert配列 (~L588) | `'origin_type' => 'AUTO'` |
| USER手動追加 | `ListWmsOrderCandidates.php` create (~L500) | `'origin_type' => 'USER'` |
| USER数量変更 | `WmsOrderCandidatesTable.php` update (~L191, L558) | 変更しない（origin_typeは生成時のみ） |
| USER手動編集 | `EditWmsOrderCandidate.php` (~L22) | 変更しない |
| Queue ORDER_CREATE | `OrderCreateJobHandler.php` create (~L262) | `'origin_type' => 'DIST'` |
| Queue TRANSFER_CREATE | `TransferCreateJobHandler.php` create (~L247) | `'origin_type' => 'DIST'` |
| Queue DEMAND_DISTRIBUTION | `DemandDistributionJobHandler.php` (新規) | `'origin_type' => 'DIST'` |

**注意**: `origin_type` は生成時に1回だけセットし、その後の手動修正では変更しない。手動修正の追跡は引き続き `is_manually_modified` で行う。

#### 5. DemandDistributionJobHandler 実装

**新規ファイル**: `app/Services/AutoOrder/DemandDistributionJobHandler.php`

**目的**: tradeシステムからの需要分配要求を処理し、発注候補 + 移動候補を一括生成する。

**payload 仕様**:
```json
{
  "demand_request_id": 12345,
  "items": [
    {
      "type": "order",
      "warehouse_id": 1,
      "item_id": 100,
      "quantity": 500,
      "note": "分配システムからの自動要求"
    },
    {
      "type": "transfer",
      "satellite_warehouse_id": 2,
      "hub_warehouse_id": 1,
      "item_id": 100,
      "transfer_quantity": 200,
      "expected_arrival_date": "2026-03-05"
    }
  ]
}
```

**処理フロー**:
1. `payload.items` を `type` で振り分け
2. `type: "order"` → `OrderCreateJobHandler` と同等の処理（`origin_type = DIST`）
3. `type: "transfer"` → `TransferCreateJobHandler` と同等の処理（`origin_type = DIST`）
4. 全アイテム処理後、結果をまとめて返却

**実装方針**: `OrderCreateJobHandler` / `TransferCreateJobHandler` の `processItem` メソッドを共通化（trait or 委譲）するか、内部で既存ハンドラに委譲する。

**推奨**: 既存ハンドラに `origin_type` パラメータを渡せるようリファクタリングし、`DemandDistributionJobHandler` は振り分け＋委譲のみ担当。

#### 6. custom-queue 基盤導入（DB変更なし）

`wms:process-queue` を廃止し、Laravel標準の `queue:work` で `wms_queue_jobs` を自動処理する。
sakemaru-ai-core の `DatabaseWithCustomQueue` パターンを WMS に導入する。

**アーキテクチャ**:

```
外部システム (trade等)
    │ 直接 SQL INSERT into wms_queue_jobs (status='pending')
    ▼
┌──────────────────────────────────────────────┐
│  DatabaseWithCustomQueue extends DatabaseQueue │
│  ────────────────────────────────────────────│
│  pop() {                                      │
│    1. wms_queue_jobs をポーリング (sakemaru DB) │
│    2. pending レコード → dispatch() で jobs へ  │
│    3. parent::pop() で通常ジョブ処理            │
│  }                                            │
└────────────┬─────────────────────────────────┘
             │ dispatch() で jobs テーブルに挿入
             ▼
┌─────────────────────────────┐
│  jobs テーブル (Laravel標準)   │
│  ─ ProcessWmsQueueJob        │
│  ─ ProcessEarningDelivery    │
│  ─ その他の通常ジョブ          │
└────────────┬────────────────┘
             │ parent::pop() → ジョブ実行
             ▼
┌─────────────────────────────┐
│  ProcessWmsQueueJob          │
│  ─ 排他ロック（status WHERE） │
│  ─ job_type で振り分け        │
│  ─ Handler 実行              │
│  ─ wms_queue_jobs 更新       │
└──────────────────────────────┘
```

##### 6-1. DatabaseWithCustomQueue

**新規ファイル**: `app/Queue/DatabaseWithCustomQueue.php`

```php
namespace App\Queue;

use App\Jobs\ProcessWmsQueueJob;
use App\Models\WmsQueueJob;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseWithCustomQueue extends DatabaseQueue
{
    public function pop($queue = null)
    {
        $this->processPendingWmsQueueJobs();

        return parent::pop($queue);
    }

    protected function processPendingWmsQueueJobs(): void
    {
        $jobs = DB::connection('sakemaru')
            ->table('wms_queue_jobs')
            ->where('status', 'pending')
            ->where('attempts', '<', DB::raw('max_attempts'))
            ->orderBy('priority', 'asc')
            ->orderBy('created_at', 'asc')
            ->limit(10)
            ->get();

        foreach ($jobs as $job) {
            $cacheKey = "wms_queue_job_dispatched:{$job->id}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            ProcessWmsQueueJob::dispatch($job->id);

            Cache::put($cacheKey, true, now()->addMinutes(10));

            Log::info('WMS queue job auto-dispatched', [
                'job_id' => $job->id,
                'job_type' => $job->job_type,
            ]);
        }
    }
}
```

**ポイント**:
- `wms_queue_jobs` は `sakemaru` DB接続で直接クエリ（`DB::connection('sakemaru')`）
- Cacheガードで重複dispatch防止（10分間）
- `parent::pop()` で通常のLaravelジョブも処理継続

##### 6-2. DatabaseWithCustomQueueConnector

**新規ファイル**: `app/Queue/DatabaseWithCustomQueueConnector.php`

```php
namespace App\Queue;

use Illuminate\Queue\Connectors\DatabaseConnector;

class DatabaseWithCustomQueueConnector extends DatabaseConnector
{
    public function connect(array $config)
    {
        return new DatabaseWithCustomQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null
        );
    }
}
```

##### 6-3. ProcessWmsQueueJob（Laravelジョブ）

**新規ファイル**: `app/Jobs/ProcessWmsQueueJob.php`

`wms_queue_jobs` の1レコードを受け取り、`job_type` に応じて既存ハンドラに委譲する。

```php
namespace App\Jobs;

use App\Enums\AutoOrder\QueueJobType;
use App\Models\WmsQueueJob;
use App\Services\AutoOrder\DemandDistributionJobHandler;
use App\Services\AutoOrder\OrderCreateJobHandler;
use App\Services\AutoOrder\TransferCreateJobHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessWmsQueueJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;   // リトライは wms_queue_jobs 側で管理
    public int $timeout = 600;

    public function __construct(public int $queueJobId) {}

    public function handle(
        OrderCreateJobHandler $orderHandler,
        TransferCreateJobHandler $transferHandler,
        DemandDistributionJobHandler $demandHandler,
    ): void {
        $job = WmsQueueJob::find($this->queueJobId);
        if (!$job) return;

        // 排他チェック: pending以外ならスキップ（他Workerが処理中）
        if ($job->status->value !== 'pending') {
            return;
        }

        try {
            $result = match ($job->job_type) {
                QueueJobType::ORDER_CREATE => $orderHandler->handle($job),
                QueueJobType::TRANSFER_CREATE => $transferHandler->handle($job),
                QueueJobType::DEMAND_DISTRIBUTION => $demandHandler->handle($job),
                default => throw new \RuntimeException("Unknown job type: {$job->job_type->value}"),
            };

            Log::info('WMS queue job processed', [
                'job_id' => $job->id,
                'job_type' => $job->job_type->value,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('WMS queue job failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
            // ハンドラ内で markAsFailed 済みのため、ここでは再throwしない
        }
    }
}
```

**ポイント**:
- `tries = 1`: Laravel側のリトライは無効。リトライは `wms_queue_jobs.attempts/max_attempts` で管理
- 既存ハンドラの `handle()` メソッドはそのまま流用（内部で `markAsProcessing()`/`markAsCompleted()`/`markAsFailed()` を呼ぶ）
- `DemandDistributionJobHandler` も同じインターフェースで追加

##### 6-4. config/queue.php 変更

```php
// 変更前
'database' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => env('DB_QUEUE', 'default'),
    'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
    'after_commit' => false,
],

// 変更後
'database' => [
    'driver' => 'custom-queue',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => env('DB_QUEUE', 'default'),
    'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 36000),
    'after_commit' => false,
],
```

変更点:
- `driver`: `database` → `custom-queue`
- `retry_after`: `90` → `36000`（10時間。長時間ジョブ対応）

##### 6-5. AppServiceProvider 変更

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    $this->app->booted(function () {
        $this->app['queue']->addConnector('custom-queue', function () {
            return new \App\Queue\DatabaseWithCustomQueueConnector($this->app['db']);
        });
    });
}
```

##### 6-6. ProcessWmsQueueJobsCommand の扱い

`queue:work` に統合されるため、`ProcessWmsQueueJobsCommand` は**非推奨化**する。

```php
// 変更: コマンド実行時に警告を表示
public function handle(): int
{
    $this->warn('⚠ このコマンドは非推奨です。queue:work を使用してください。');
    $this->info('  php artisan queue:work');
    $this->newLine();

    // 既存処理はフォールバックとして残す（移行期間中）
    // ...
}
```

完全削除は移行確認後に行う。

##### 6-7. 既存ジョブの接続修正

`sakemaru` queue connection が存在しない問題を修正する：

```php
// ProcessEarningDeliveryQueue.php
// 変更前
$this->onConnection('sakemaru');
$this->onQueue('earning-delivery');

// 変更後（onConnectionを削除し、デフォルトのdatabase接続を使用）
$this->onQueue('earning-delivery');

// ArchiveDepletedLots.php — 同様に修正
```

##### 6-8. 実行方法

```bash
# 変更前: 2つのプロセスが必要
php artisan queue:work              # 通常ジョブ処理
php artisan wms:process-queue       # wms_queue_jobs処理（別プロセス）

# 変更後: 1つのプロセスで全て処理
php artisan queue:work
```

#### 7. DemandDistributionJobHandler 実装

**新規ファイル**: `app/Services/AutoOrder/DemandDistributionJobHandler.php`

既存の `OrderCreateJobHandler` / `TransferCreateJobHandler` に委譲する振り分けハンドラ。

```php
namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\QueueJobLogLevel;
use App\Models\WmsQueueJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DemandDistributionJobHandler
{
    public function __construct(
        private OrderCreateJobHandler $orderHandler,
        private TransferCreateJobHandler $transferHandler,
    ) {}

    public function handle(WmsQueueJob $job): array
    {
        $job->markAsProcessing();
        $job->addLog(QueueJobLogLevel::INFO->value, '需要分配ジョブを開始');

        $payload = $job->payload;
        $items = $payload['items'] ?? [];

        if (empty($items)) {
            $job->addLog(QueueJobLogLevel::ERROR->value, 'payloadにitemsが含まれていません');
            $job->markAsFailed('payloadにitemsが含まれていません');
            return ['success' => false, 'error' => 'No items in payload'];
        }

        try {
            $result = DB::connection('sakemaru')->transaction(function () use ($job, $items, $payload) {
                $orderItems = [];
                $transferItems = [];

                foreach ($items as $item) {
                    match ($item['type'] ?? null) {
                        'order' => $orderItems[] = $item,
                        'transfer' => $transferItems[] = $item,
                        default => $job->addLog(
                            QueueJobLogLevel::WARNING->value,
                            "不明なtype: " . ($item['type'] ?? 'null'),
                            $item
                        ),
                    };
                }

                $orderResult = [];
                $transferResult = [];

                // 発注候補の処理: ORDER_CREATEジョブとして委譲
                if (!empty($orderItems)) {
                    $orderResult = $this->orderHandler->handleItems($job, $orderItems);
                }

                // 移動候補の処理: TRANSFER_CREATEジョブとして委譲
                if (!empty($transferItems)) {
                    $transferResult = $this->transferHandler->handleItems($job, $transferItems);
                }

                return [
                    'demand_request_id' => $payload['demand_request_id'] ?? null,
                    'order_results' => $orderResult,
                    'transfer_results' => $transferResult,
                    'total_orders' => count($orderItems),
                    'total_transfers' => count($transferItems),
                ];
            });

            $job->markAsCompleted($result);
            $job->addLog(QueueJobLogLevel::INFO->value, '需要分配ジョブが完了', $result);

            return $result;

        } catch (\Exception $e) {
            $job->addLog(QueueJobLogLevel::ERROR->value, '需要分配ジョブでエラー: ' . $e->getMessage());
            $job->markAsFailed($e->getMessage());
            Log::error('DemandDistributionJobHandler failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
```

**注意**: 既存の `OrderCreateJobHandler::handle()` / `TransferCreateJobHandler::handle()` に `handleItems()` メソッドを追加（または既存の `processItems()` を public化）して、`DemandDistributionJobHandler` から呼べるようにする。

#### 8. UI変更

**WmsOrderCandidatesTable.php** — 既存の `is_manually_modified` 列を `origin_type` 列に置換：

```php
// 変更前
TextColumn::make('is_manually_modified')
    ->label('手動修正')
    ->state(fn ($record) => $record->is_manually_modified ? '修正済' : '-')
    ->toggleable(isToggledHiddenByDefault: true),

// 変更後
TextColumn::make('origin_type')
    ->label('生成元')
    ->badge()
    ->color(fn ($record) => $record->origin_type?->color() ?? 'gray')
    ->toggleable(isToggledHiddenByDefault: false),
```

**WmsOrderCandidateForm.php** — 詳細表示にも origin_type を表示：

```php
TextEntry::make('origin_type')
    ->label('生成元')
    ->state(fn ($record) => $record->origin_type?->label() ?? '-'),
```

**フィルター追加**:
```php
SelectFilter::make('origin_type')
    ->label('生成元')
    ->options(OriginType::class),
```

---

### 影響範囲

| 対象 | 影響内容 |
|---|---|
| **origin_type 関連** | |
| `wms_order_candidates` テーブル | カラム追加 |
| `wms_stock_transfer_candidates` テーブル | カラム追加 |
| `WmsOrderCandidate` モデル | fillable/casts追加 |
| `WmsStockTransferCandidate` モデル | fillable/casts追加 |
| `OrderCandidateCalculationService` | insert配列に `origin_type` 追加（2箇所） |
| `ListWmsOrderCandidates` | create時に `origin_type => USER` 追加 |
| `WmsOrderCandidatesTable` | 数量変更アクション内のcreate/update（origin_type追加）、列変更 |
| `OrderCreateJobHandler` | create時に `origin_type => DIST` 追加、`handleItems()` 公開 |
| `TransferCreateJobHandler` | create時に `origin_type => DIST` 追加、`handleItems()` 公開 |
| **custom-queue 関連** | |
| `config/queue.php` | driver を `custom-queue` に変更 |
| `AppServiceProvider` | コネクタ登録追加 |
| `ProcessWmsQueueJobsCommand` | 非推奨化（警告表示、フォールバックとして残す） |
| `ProcessEarningDeliveryQueue` | `onConnection('sakemaru')` 削除 |
| `ArchiveDepletedLots` | `onConnection('sakemaru')` 削除 |
| リスト画面 | 列追加・フィルター追加 |

## 制約

- **FK禁止**: `origin_type` はENUMカラムで管理、外部キー不要
- **migrate:fresh/refresh 禁止**: ALTER TABLE で追加のみ
- **既存データ保全**: デフォルト値 `AUTO` により既存レコードは自動計算扱い、`is_manually_modified=true` のレコードは `USER` に補正
- **is_manually_modified は残す**: 「自動生成後の手動修正」を追跡する用途で引き続き使用。origin_type とは独立した概念
- **DB変更なしでcustom-queue導入**: `wms_queue_jobs` テーブルはそのまま使用。Laravel標準 `jobs` テーブルも既存のものを使用
- **移行期間**: `ProcessWmsQueueJobsCommand` は即座に削除せず、非推奨警告を表示しつつフォールバックとして残す

## 対象ファイル

### 新規作成
- `app/Enums/AutoOrder/OriginType.php` — Enum定義
- `database/migrations/XXXX_add_origin_type_to_order_and_transfer_candidates.php` — マイグレーション
- `app/Services/AutoOrder/DemandDistributionJobHandler.php` — DEMAND_DISTRIBUTIONハンドラ
- `app/Queue/DatabaseWithCustomQueue.php` — カスタムキュー（pop()でwms_queue_jobsポーリング）
- `app/Queue/DatabaseWithCustomQueueConnector.php` — コネクタ
- `app/Jobs/ProcessWmsQueueJob.php` — wms_queue_jobs処理用Laravelジョブ

### 既存変更
- `app/Models/WmsOrderCandidate.php` — fillable/casts追加
- `app/Models/WmsStockTransferCandidate.php` — fillable/casts追加
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — insert配列にorigin_type追加（2箇所）
- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php` — create時にorigin_type追加
- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php` — 列変更、フィルター追加、アクション内のcreate/update修正
- `app/Filament/Resources/WmsOrderCandidates/Schemas/WmsOrderCandidateForm.php` — 詳細表示変更
- `app/Services/AutoOrder/OrderCreateJobHandler.php` — origin_type追加、handleItems()公開
- `app/Services/AutoOrder/TransferCreateJobHandler.php` — origin_type追加、handleItems()公開
- `app/Console/Commands/ProcessWmsQueueJobsCommand.php` — 非推奨化（警告表示）
- `config/queue.php` — driver を `custom-queue` に変更、retry_after を 36000 に
- `app/Providers/AppServiceProvider.php` — custom-queue コネクタ登録
- `app/Jobs/ProcessEarningDeliveryQueue.php` — `onConnection('sakemaru')` 削除
- `app/Jobs/ArchiveDepletedLots.php` — `onConnection('sakemaru')` 削除

### 参照のみ
- `app/Enums/AutoOrder/QueueJobType.php` — DEMAND_DISTRIBUTION定義確認
- `app/Enums/AutoOrder/QueueJobStatus.php` — ステータス確認
- `app/Models/WmsQueueJob.php` — payload構造・ステータス管理メソッド確認
- `database/migrations/2025_12_13_165201_create_wms_order_candidates_table.php` — 現行スキーマ確認
- `database/migrations/2025_12_13_165200_create_wms_stock_transfer_candidates_table.php` — 現行スキーマ確認
- `database/migrations/2026_01_30_000001_create_wms_queue_jobs_table.php` — wms_queue_jobsスキーマ確認
- `sakemaru-ai-core: app/Queue/DatabaseWithCustomQueue.php` — 参照実装

## 確認事項

1. **DemandDistributionJobHandler の粒度**: 既存の `OrderCreateJobHandler` / `TransferCreateJobHandler` に `handleItems()` を公開して委譲する方式でよいか？
   - **推奨**: 委譲方式（重複コード削減）
2. **wms_stock_transfer_candidates のリスト画面**: 移動候補テーブルにも同様に `origin_type` 列を追加するか？
3. **DEMAND_DISTRIBUTION の payload フォーマット**: 上記仕様で trade チームと合意済みか？
4. **ProcessWmsQueueJobsCommand の完全削除タイミング**: 移行期間をどのくらいとるか？
5. **ProcessEarningDeliveryQueue の動作確認**: `onConnection('sakemaru')` 削除後、デフォルト接続で正常動作するか要検証
