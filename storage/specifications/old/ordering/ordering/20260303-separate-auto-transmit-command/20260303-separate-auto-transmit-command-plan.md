# 自動送信コマンド分離 作業計画

## 前提

- 現状: `ProcessOrderCandidateGenerationJob` の末尾で `ProcessAutoSendJob` が即座にdispatch → 候補生成直後に送信され、承認レベル設定が無意味
- あるべき姿: `auto_order_generation_time` で候補生成 → オペレーターチェック → `transmission_time` で自動送信
- `ProcessAutoSendJob` は変更不要（既存パイプラインをそのまま流用）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | ProcessOrderCandidateGenerationJob修正 | dispatchAutoSendIfNeeded() 削除 | メソッド・呼び出し・import削除完了、Pint通過 |
| P2 | AutoOrderTransmitCommand新規作成 | transmission_timeで発火する送信コマンド作成 | コマンド作成完了、Pint通過 |
| P3 | スケジューラ登録 | routes/console.phpにコマンド追加 | スケジューラ一覧テーブル更新、登録完了 |
| P4 | 動作確認 | コマンド実行テスト | `php artisan wms:auto-order-transmit` がエラーなく実行される |

---

## P1: ProcessOrderCandidateGenerationJob修正

### 目的

候補生成ジョブから自動送信のトリガーを除去し、候補生成と送信を完全に分離する。

### 修正対象ファイル

- `app/Jobs/ProcessOrderCandidateGenerationJob.php`

### 修正内容

1. **`dispatchAutoSendIfNeeded()` メソッド全体を削除**（294〜319行目）

2. **`handle()` 内の呼び出しを削除**（185〜188行目）
   ```php
   // 削除対象:
   // 自動送信が有効な場合、自動送信ジョブを起動
   if ($this->contractorId && $results['batchCode']) {
       $this->dispatchAutoSendIfNeeded($results['batchCode']);
   }
   ```

3. **不要importの削除**
   - `use App\Jobs\ProcessAutoSendJob;` — このファイル内で他に使用されていないため削除
   - `use App\Models\WmsContractorSetting;` — `applyConfirmationLevels()` では使っていないが、他で使っていないか確認して削除

### 確認手順

1. `WmsContractorSetting` が `applyConfirmationLevels()` や他の箇所で使われていないことを確認
2. `ProcessAutoSendJob` がこのファイル内で他に参照されていないことを確認
3. `./vendor/bin/pint app/Jobs/ProcessOrderCandidateGenerationJob.php` でフォーマット確認

### 完了条件

- `dispatchAutoSendIfNeeded()` メソッドが完全に削除されている
- `handle()` 内の呼び出しが削除されている
- 不要なimport文が削除されている
- `./vendor/bin/pint` が通る

---

## P2: AutoOrderTransmitCommand新規作成

### 目的

`transmission_time` に基づいて自動送信を実行する独立したArtisanコマンドを作成する。

### 修正対象ファイル

- `app/Console/Commands/AutoOrder/AutoOrderTransmitCommand.php`（新規作成）

### 設計

既存の `AutoOrderScheduledCommand` のパターンに倣い、以下のロジックを実装:

1. **対象抽出条件**:
   - `transmission_contractor_id IS NULL`（集約先がない＝親）
   - `is_auto_transmission = true`
   - `transmission_time IS NOT NULL`
   - `transmission_time <= 現在時刻`
   - 当日の曜日フラグ = true

2. **スキップ条件**:
   - 当日の `WmsAutoOrderExecutionLog` が `SUCCESS` でない（候補生成が未完了）
   - 当日の `auto_send` ジョブが `completed` 状態（送信済み）

3. **バッチコード取得**:
   - 当日の `WmsOrderCandidate` or `WmsStockTransferCandidate` から `batch_code` を取得
   - status が PENDING / APPROVED / CONFIRMED のいずれか

4. **ジョブdispatch**:
   - `WmsQueueProgress::createJob()` で進捗レコード作成
   - `ProcessAutoSendJob::dispatch()` で送信ジョブ起動

### 参照すべきパターン

- `AutoOrderScheduledCommand.php` — 曜日判定・ログ出力のパターン
- `ProcessAutoSendJob.php` — dispatch時のパラメータ

### importリスト

```php
use App\Enums\AutoOrder\CandidateStatus;
use App\Jobs\ProcessAutoSendJob;
use App\Models\WmsAutoOrderExecutionLog;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
```

### 完了条件

- コマンドファイルが作成されている
- `php artisan list | grep auto-order-transmit` で表示される
- `./vendor/bin/pint` が通る

---

## P3: スケジューラ登録

### 目的

`wms:auto-order-transmit` を5分毎のスケジューラに登録する。

### 修正対象ファイル

- `routes/console.php`

### 修正内容

1. **スケジューラ一覧テーブルにエントリ追加**:
   ```
   │ wms:auto-order-transmit          │ 5分ごと          │ 送信時刻に基づく自動送信                               │
   │                                  │                  │ wms_contractor_settings.transmission_time               │
   │                                  │                  │ に基づきPENDING/APPROVED→確定→JXファイル生成→送信     │
   ```

2. **Schedule登録追加**（`wms:auto-order-scheduled` の直後に配置）:
   ```php
   // 仕入先別自動送信スケジューラー (5分間隔)
   // ※ 仕入先ごとのtransmission_timeに基づいて承認→確定→JXファイル生成→送信を実行
   Schedule::command('wms:auto-order-transmit')
       ->everyFiveMinutes()
       ->onOneServer()
       ->withoutOverlapping()
       ->appendOutputTo(storage_path('logs/auto-order-transmit.log'));
   ```

### 完了条件

- `routes/console.php` にスケジューラ登録が追加されている
- スケジューラ一覧テーブルが更新されている
- `./vendor/bin/pint` が通る

---

## P4: 動作確認

### 目的

新コマンドがエラーなく実行できることを確認する。

### 確認手順

1. `php artisan wms:auto-order-transmit` を実行（対象がなくても正常終了すること）
2. ログ出力を確認

### 完了条件

- コマンドがエラーなく実行される
- `php artisan schedule:list` で新コマンドが表示される

---

## 制約（厳守）

- FK禁止（プロジェクトルール）
- `migrate:fresh` / `migrate:refresh` / `migrate:reset` / `db:wipe` 禁止
- DB変更なし（新規テーブル・カラム不要）
- `ProcessAutoSendJob` は変更しない
- `AutoOrderScheduledCommand` は変更しない

## 全体完了条件

1. 候補生成ジョブ（ProcessOrderCandidateGenerationJob）が自動送信を呼ばなくなっている
2. 新コマンド `wms:auto-order-transmit` が `transmission_time` に基づいて送信を実行する
3. スケジューラに5分毎で登録されている
4. `./vendor/bin/pint` が全対象ファイルで通る
