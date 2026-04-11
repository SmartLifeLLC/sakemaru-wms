# DEMAND_DISTRIBUTION & origin_type & custom-queue 作業計画

## 前提

- ブランチ: `feature/ordering-update`
- `wms_queue_jobs` テーブルは sakemaru DB 上に既存（変更なし）
- `OrderCreateJobHandler` / `TransferCreateJobHandler` は実装済み・動作確認済み
- `ProcessWmsQueueJobsCommand` で `DEMAND_DISTRIBUTION` のみ未実装（TODO）
- 参照実装: `sakemaru-ai-core/storage/specifications/custom-queue/implementation-guide.md`

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | 基盤（Enum + Migration + Model） | OriginType Enum 作成、DBカラム追加、モデル更新 | migrate 成功、Enum import 可能 |
| P1 | origin_type を全生成パスにセット | 7箇所の生成パスに origin_type を追加 | 自動計算→AUTO、手動→USER、Queue→DIST が正しくセットされる |
| P2 | custom-queue 基盤導入 | DatabaseWithCustomQueue + Connector + config + Provider | `queue:work` で wms_queue_jobs がポーリングされる |
| P3 | Handler リファクタリング + ProcessWmsQueueJob | handleItems() 公開 + Laravel Job 作成 | ProcessWmsQueueJob が job_type で振り分け実行できる |
| P4 | DemandDistributionJobHandler 実装 | 需要分配ハンドラ作成（order + transfer 委譲） | demand_distribution ジョブが正常処理される |
| P5 | UI変更 + 既存ジョブ修正 + コマンド非推奨化 | 生成元列追加、onConnection修正、コマンド警告 | リスト画面にバッジ表示、フィルター動作 |
| P6 | 統合検証 | 全フロー通しテスト | AUTO/USER/DIST 各パスで正常動作 |

---

## P0: 基盤（Enum + Migration + Model）

### 目的

origin_type の土台を作る。この Phase ではカラム追加とモデル更新のみ行い、既存の動作に影響を与えない。

### 作業手順

#### 1. OriginType Enum 作成

**新規**: `app/Enums/AutoOrder/OriginType.php`

```php
<?php

namespace App\Enums\AutoOrder;

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

#### 2. マイグレーション作成・実行

```bash
php artisan make:migration add_origin_type_to_order_and_transfer_candidates --path=database/migrations
```

マイグレーション内容:
- `wms_order_candidates` に `origin_type` ENUM カラム追加（default: 'AUTO', after: 'is_manually_modified'）
- `wms_stock_transfer_candidates` に同上
- 既存データ補正: `is_manually_modified = true` → `origin_type = 'USER'`
- connection は `sakemaru`

```bash
php artisan migrate
```

#### 3. モデル更新

**`app/Models/WmsOrderCandidate.php`**:
- `fillable` に `'origin_type'` 追加
- `casts` に `'origin_type' => OriginType::class` 追加

**`app/Models/WmsStockTransferCandidate.php`**:
- 同上

### 完了条件

- [ ] `php artisan migrate` 成功
- [ ] `php artisan migrate:status` で新マイグレーションが `Ran` 表示
- [ ] `OriginType::AUTO->label()` が `'自動'` を返す
- [ ] 既存レコードで `is_manually_modified=true` のものが `origin_type='USER'` に更新されている
- [ ] 既存レコードで `is_manually_modified=false` のものが `origin_type='AUTO'`（デフォルト）

---

## P1: origin_type を全生成パスにセット

### 目的

全ての発注候補・移動候補の生成箇所で `origin_type` を正しくセットする。

### 修正対象と変更内容

#### 1. AUTO計算（EXTERNAL）
**ファイル**: `app/Services/AutoOrder/OrderCandidateCalculationService.php`
**位置**: insert配列（~L815付近）
**変更**: `'origin_type' => 'AUTO'` を追加

#### 2. AUTO計算（INTERNAL）
**ファイル**: `app/Services/AutoOrder/OrderCandidateCalculationService.php`
**位置**: insert配列（~L588付近）
**変更**: `'origin_type' => 'AUTO'` を追加

#### 3. USER手動追加
**ファイル**: `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php`
**位置**: WmsOrderCandidate::create（~L500付近）
**変更**: `'origin_type' => OriginType::USER` を追加

#### 4. USER数量変更アクション（発注候補テーブル内）
**ファイル**: `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php`
**位置**: ~L191, ~L558 の update/create 箇所
**変更**: 新規作成時のみ `'origin_type' => OriginType::USER`。update時は変更しない（origin_type は生成時のみ）

#### 5. Queue ORDER_CREATE
**ファイル**: `app/Services/AutoOrder/OrderCreateJobHandler.php`
**位置**: WmsOrderCandidate::create（~L262付近）
**変更**: `'origin_type' => OriginType::DIST` を追加（`'origin_type' => 'DIST'`）

#### 6. Queue TRANSFER_CREATE
**ファイル**: `app/Services/AutoOrder/TransferCreateJobHandler.php`
**位置**: WmsStockTransferCandidate::create（~L247付近）
**変更**: `'origin_type' => OriginType::DIST` を追加

#### 注意事項

- `EditWmsOrderCandidate.php` — origin_type は変更しない（手動編集で is_manually_modified=true にするが origin_type はそのまま）
- insert 配列（バルクINSERT）では Enum の value を使う: `'origin_type' => OriginType::AUTO->value`
- Eloquent create では Enum そのまま使える: `'origin_type' => OriginType::USER`

### 完了条件

- [ ] 自動計算実行後、生成された wms_order_candidates が `origin_type='AUTO'`
- [ ] 自動計算実行後、生成された wms_stock_transfer_candidates が `origin_type='AUTO'`
- [ ] Filament UI「発注追加」で作成したレコードが `origin_type='USER'`
- [ ] OrderCreateJobHandler 経由の作成が `origin_type='DIST'`
- [ ] TransferCreateJobHandler 経由の作成が `origin_type='DIST'`

---

## P2: custom-queue 基盤導入

### 目的

`DatabaseWithCustomQueue` パターンを導入し、`queue:work` で `wms_queue_jobs` を自動ポーリングする基盤を作る。この Phase ではまだ `ProcessWmsQueueJob` は作らず、基盤のみ。

### 作業手順

#### 1. DatabaseWithCustomQueue 作成

**新規**: `app/Queue/DatabaseWithCustomQueue.php`

- `DatabaseQueue` を継承
- `pop()` をオーバーライド
- `processPendingWmsQueueJobs()` で sakemaru DB の `wms_queue_jobs` をポーリング
- `DB::connection('sakemaru')` で直接クエリ
- Cache ガードで重複 dispatch 防止（`wms_queue_job_dispatched:{id}` を10分間キャッシュ）
- `parent::pop()` で通常ジョブも処理

仕様書セクション 6-1 のコードを参照。

#### 2. DatabaseWithCustomQueueConnector 作成

**新規**: `app/Queue/DatabaseWithCustomQueueConnector.php`

仕様書セクション 6-2 のコードを参照。

#### 3. config/queue.php 変更

```php
'database' => [
    'driver' => 'custom-queue',    // database → custom-queue
    // ... 他は同じ
    'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 36000),  // 90 → 36000
],
```

#### 4. AppServiceProvider にコネクタ登録

`app/Providers/AppServiceProvider.php` の `register()` メソッドに追加:

```php
$this->app->booted(function () {
    $this->app['queue']->addConnector('custom-queue', function () {
        return new \App\Queue\DatabaseWithCustomQueueConnector($this->app['db']);
    });
});
```

### 完了条件

- [ ] `php artisan queue:work --once` がエラーなく起動する
- [ ] `wms_queue_jobs` に pending レコードがある場合、ログに `WMS queue job auto-dispatched` が出力される
- [ ] 通常の Laravel ジョブ（ProcessExportJob 等）が引き続き正常に処理される

---

## P3: Handler リファクタリング + ProcessWmsQueueJob

### 目的

既存ハンドラの `processItems()` を公開し、`ProcessWmsQueueJob`（Laravel Job）を作成して、`queue:work` → ハンドラの接続を完成させる。

### 作業手順

#### 1. OrderCreateJobHandler リファクタリング

**ファイル**: `app/Services/AutoOrder/OrderCreateJobHandler.php`

`processItems()` メソッド（private）を公開する:

```php
// 変更前
private function processItems(WmsQueueJob $job, array $items, array $payload): array

// 変更後: public な handleItems() を追加（DemandDistributionJobHandler から呼ぶ用）
public function handleItems(WmsQueueJob $job, array $items): array
{
    $batchCode = $this->getOrCreateBatchCode($job);
    $results = [];
    $successCount = 0;
    $skipCount = 0;

    foreach ($items as $itemData) {
        $result = $this->processItem($job, $itemData, $batchCode);
        $results[] = $result;
        if ($result['status'] === 'created') {
            $successCount++;
        } else {
            $skipCount++;
        }
    }

    return [
        'batch_code' => $batchCode,
        'success_count' => $successCount,
        'skip_count' => $skipCount,
        'results' => $results,
    ];
}
```

既存の `handle()` メソッドは変更不要（内部で `processItems()` を呼び続ける）。

#### 2. TransferCreateJobHandler リファクタリング

**ファイル**: `app/Services/AutoOrder/TransferCreateJobHandler.php`

同様に `handleItems()` を公開。

#### 3. ProcessWmsQueueJob 作成

**新規**: `app/Jobs/ProcessWmsQueueJob.php`

仕様書セクション 6-3 のコードを参照。
- `$tries = 1`（リトライは wms_queue_jobs 側で管理）
- `$timeout = 600`
- DI で 3つのハンドラを受け取り、`job_type` の match で振り分け

#### 4. DatabaseWithCustomQueue の dispatch 先を接続

P2 で作成した `processPendingWmsQueueJobs()` 内の dispatch:

```php
ProcessWmsQueueJob::dispatch($job->id);
```

P2 時点で ProcessWmsQueueJob が未作成の場合、この Phase で接続を確認。

### 完了条件

- [ ] `wms_queue_jobs` に `order_create` ジョブを手動 INSERT → `queue:work` が検知して `OrderCreateJobHandler` を実行
- [ ] `wms_queue_jobs` に `transfer_create` ジョブを手動 INSERT → `TransferCreateJobHandler` が実行される
- [ ] ジョブ完了後、`wms_queue_jobs.status` が `completed` に更新される
- [ ] ジョブ失敗時、`wms_queue_jobs.status` が `failed` に更新される

---

## P4: DemandDistributionJobHandler 実装

### 目的

`demand_distribution` ジョブタイプのハンドラを実装し、発注候補と移動候補を一括生成できるようにする。

### 作業手順

#### 1. DemandDistributionJobHandler 作成

**新規**: `app/Services/AutoOrder/DemandDistributionJobHandler.php`

仕様書セクション 7 のコードを参照。

処理フロー:
1. `markAsProcessing()`
2. `payload.items` を `type` で振り分け（`order` / `transfer`）
3. `order` → `OrderCreateJobHandler::handleItems()` に委譲
4. `transfer` → `TransferCreateJobHandler::handleItems()` に委譲
5. 結果をまとめて `markAsCompleted()`

#### 2. ProcessWmsQueueJob に DEMAND_DISTRIBUTION を追加

P3 で作成した `ProcessWmsQueueJob::handle()` の match 文に既に含まれているはず:

```php
QueueJobType::DEMAND_DISTRIBUTION => $demandHandler->handle($job),
```

DI が正しく解決されることを確認。

### テスト方法

sakemaru DB に手動 INSERT:

```sql
INSERT INTO wms_queue_jobs (job_type, payload, status, source_system, source_reference_type, source_reference_id, created_at, updated_at)
VALUES (
    'demand_distribution',
    '{"demand_request_id":1,"items":[{"type":"order","warehouse_id":1,"item_id":100,"quantity":120},{"type":"transfer","satellite_warehouse_id":2,"hub_warehouse_id":1,"item_id":100,"transfer_quantity":60,"expected_arrival_date":"2026-03-05"}]}',
    'pending',
    'trade',
    'demand_requests',
    1,
    NOW(),
    NOW()
);
```

### 完了条件

- [ ] `demand_distribution` ジョブ INSERT → `queue:work` が検知して処理
- [ ] `type: "order"` のアイテムが `wms_order_candidates` に作成される（`origin_type='DIST'`）
- [ ] `type: "transfer"` のアイテムが `wms_stock_transfer_candidates` に作成される（`origin_type='DIST'`）
- [ ] `wms_queue_jobs.status` が `completed`、`result` に処理サマリーが格納
- [ ] `wms_queue_job_logs` にログが記録される

---

## P5: UI変更 + 既存ジョブ修正 + コマンド非推奨化

### 目的

Filament リスト画面に生成元を表示し、既存ジョブの接続問題を修正し、旧コマンドを非推奨化する。

### 作業手順

#### 1. WmsOrderCandidatesTable.php — 列変更

`is_manually_modified` 列を `origin_type` 列に置換:

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

フィルター追加:

```php
SelectFilter::make('origin_type')
    ->label('生成元')
    ->options(OriginType::class),
```

#### 2. WmsOrderCandidateForm.php — 詳細表示

既存の `is_manually_modified` TextEntry を `origin_type` に変更:

```php
TextEntry::make('origin_type')
    ->label('生成元')
    ->state(fn ($record) => $record->origin_type?->label() ?? '-'),
```

#### 3. 既存ジョブの onConnection 修正

**`app/Jobs/ProcessEarningDeliveryQueue.php`**:
```php
// 削除: $this->onConnection('sakemaru');
// 残す: $this->onQueue('earning-delivery');
```

**`app/Jobs/ArchiveDepletedLots.php`**:
```php
// 削除: $this->onConnection('sakemaru');
// 残す: $this->onQueue('lot-archive');
```

#### 4. ProcessWmsQueueJobsCommand 非推奨化

**`app/Console/Commands/ProcessWmsQueueJobsCommand.php`**:

`handle()` メソッド冒頭に警告を追加:

```php
$this->warn('⚠ このコマンドは非推奨です。queue:work を使用してください。');
$this->info('  php artisan queue:work');
$this->newLine();
```

既存の処理ロジックはフォールバックとして残す。

### 完了条件

- [ ] `/admin/wms-order-candidates` リストに「生成元」列がバッジ表示される
- [ ] 自動→青 `自動`、手動→緑 `手動`、分配→黄 `分配` のバッジカラー
- [ ] 生成元フィルターで絞り込みが動作する
- [ ] 詳細画面に生成元が表示される
- [ ] `php artisan wms:process-queue` 実行時に非推奨警告が表示される
- [ ] `ProcessEarningDeliveryQueue` / `ArchiveDepletedLots` がエラーなく dispatch される

---

## P6: 統合検証

### 目的

全フローを通しでテストし、origin_type とcustom-queue の両方が正常動作することを確認する。

### 検証項目

#### A. origin_type 検証

1. **AUTO パス**: 自動発注計算を実行（UI or コマンド）
   - wms_order_candidates に `origin_type='AUTO'` で作成されることを確認
   - wms_stock_transfer_candidates に `origin_type='AUTO'` で作成されることを確認

2. **USER パス**: Filament UI「発注追加」ボタンで手動追加
   - `origin_type='USER'` で作成されることを確認
   - その後手動で数量変更 → `is_manually_modified=true` だが `origin_type='USER'` のまま

3. **DIST パス**: wms_queue_jobs に手動 INSERT
   - `order_create` → `origin_type='DIST'`
   - `transfer_create` → `origin_type='DIST'`
   - `demand_distribution` → order は `origin_type='DIST'`、transfer も `origin_type='DIST'`

#### B. custom-queue 検証

1. `php artisan queue:work` 起動
2. wms_queue_jobs に pending レコード INSERT
3. 自動検知 → ProcessWmsQueueJob dispatch → Handler 実行 → 完了
4. 通常の Laravel ジョブ（ProcessExportJob 等）も並行して処理される

#### C. UI 検証

1. リスト画面で「生成元」列の表示確認
2. フィルターで AUTO / USER / DIST 各値で絞り込み
3. 詳細画面で生成元表示確認

#### D. 回帰確認

1. 自動発注フロー全体（計算 → ロット適用 → 承認 → 発注ファイル生成）が正常動作
2. 既存の `ProcessEarningDeliveryQueue` が `queue:work` で正常処理される

### 完了条件

- [ ] 上記 A〜D 全項目がパス
- [ ] エラーログに異常な出力がない
- [ ] `composer test` が通る（既存テストが壊れていない）

---

## 制約（厳守）

1. **migrate:fresh / migrate:refresh / db:wipe 禁止** — 本番共有DB
2. **外部キー（FK）禁止** — アプリケーション層で整合性管理
3. **wms_queue_jobs テーブル変更禁止** — 既存テーブルをそのまま使用
4. **origin_type は生成時のみセット** — 後続の手動修正では変更しない
5. **is_manually_modified は削除しない** — origin_type とは独立した概念として残す
6. **ProcessWmsQueueJobsCommand は即削除しない** — 非推奨化のみ
7. **計算ロジック変更禁止** — OrderCandidateCalculationService の計算アルゴリズムは変更不可

## 全体完了条件

- [ ] 全 Phase (P0〜P6) が完了
- [ ] `composer test` パス
- [ ] `./vendor/bin/pint` でコードフォーマット統一
- [ ] boot.md の進捗テーブルが全て「完了」
- [ ] `php artisan queue:work` 1プロセスで全ジョブ処理可能
