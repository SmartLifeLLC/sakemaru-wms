# 自動送信コマンドの分離

- **作成日**: 2026-03-03
- **ステータス**: ドラフト
- **ディレクトリ**: storage/specifications/ordering/20260303-separate-auto-transmit-command/

## 背景・目的

現状、`ProcessOrderCandidateGenerationJob`（候補生成ジョブ）の末尾で`ProcessAutoSendJob`が即座にdispatchされ、候補生成→承認→確定→JX送信が一気に実行されている。

しかし本来の運用フローは以下のとおり：

```
[auto_order_generation_time] 候補生成 + 承認レベル適用
       ↓  人がチェックする時間
[transmission_time]          確定 + ファイル生成 + JX送信
```

`is_auto_transmission=true` は「送信時刻になったら自動送信する」設定であり、「生成直後に即送信する」設定ではない。`auto_order_generation_time` と `transmission_time` にはギャップがあり、その間にオペレーターがチェックする。

## 現状の実装

### 実行フロー（現状 - 問題あり）

```
wms:auto-order-scheduled (5分毎)
  └─ auto_order_generation_time <= 現在時刻 の発注先を抽出
       └─ ProcessOrderCandidateGenerationJob dispatch
            ├─ スナップショット → 候補計算
            ├─ applyConfirmationLevels() — 承認レベル適用
            └─ dispatchAutoSendIfNeeded() — ★問題: 即座にAutoSend実行
                 └─ ProcessAutoSendJob
                      ├─ PENDING → 強制承認（承認レベル無視）
                      ├─ APPROVED → 確定
                      ├─ JXファイル生成
                      └─ JX送信
```

### 問題点

1. 候補生成直後にAutoSendが走り、承認レベル設定が無意味になる
2. `transmission_time` が使われていない（`auto_order_generation_time` のみ使用）
3. 人がチェックする時間がない

### 関連設定カラム（wms_contractor_settings）

| カラム | 用途 |
|---|---|
| `auto_order_generation_time` | 候補生成時刻（HH:mm） |
| `transmission_time` | 送信時刻（HH:mm） |
| `is_auto_transmission` | 自動送信フラグ |
| `is_transmission_sun〜sat` | 送信曜日フラグ |
| `transmission_contractor_id` | 集約先（NULLなら自身が親） |
| `transmission_type` | 送信方式（JX_FINET, MANUAL_CSV, FTP, INTERNAL） |

## 変更内容

### 概要

1. `ProcessOrderCandidateGenerationJob` から `dispatchAutoSendIfNeeded()` を削除
2. 新コマンド `wms:auto-order-transmit` を作成し、`transmission_time` で発火
3. スケジューラに `wms:auto-order-transmit` を登録（5分毎）

### あるべきフロー（変更後）

```
wms:auto-order-scheduled (5分毎)
  └─ auto_order_generation_time <= 現在時刻 の発注先を抽出
       └─ ProcessOrderCandidateGenerationJob dispatch
            ├─ スナップショット → 候補計算
            └─ applyConfirmationLevels() — 承認レベル適用
                 STATUS1: PENDING のまま（手動チェック待ち）
                 STATUS2: 自動承認（APPROVED）
                 STATUS3: 自動承認 + 自動確定（CONFIRMED）

       ↓  オペレーターがチェックする時間

wms:auto-order-transmit (5分毎) ★新規
  └─ transmission_time <= 現在時刻 & is_auto_transmission=true の発注先を抽出
       └─ ProcessAutoSendJob dispatch（既存ジョブを流用）
            ├─ PENDING → 承認
            ├─ APPROVED → 確定
            ├─ JXファイル生成
            └─ JX送信
```

### 詳細設計

#### 1. ProcessOrderCandidateGenerationJob の修正

**削除対象:**
- `dispatchAutoSendIfNeeded()` メソッド全体（294〜319行）
- `handle()` 内の `dispatchAutoSendIfNeeded()` 呼び出し
- `use App\Jobs\ProcessAutoSendJob` import
- `use App\Models\WmsContractorSetting` import（他で使っていなければ）

#### 2. 新コマンド `wms:auto-order-transmit` の作成

**ファイル:** `app/Console/Commands/AutoOrder/AutoOrderTransmitCommand.php`

**ロジック:**

```php
$signature = 'wms:auto-order-transmit';
$description = '送信時刻に基づく自動送信';

public function handle(): int
{
    $now = now();
    $currentTime = $now->format('H:i');
    $currentDayColumn = 'is_transmission_'.strtolower($now->format('D'));

    // 自動送信対象の発注先を取得
    $settings = WmsContractorSetting::query()
        ->whereNull('transmission_contractor_id')  // 集約先がない（=親）
        ->where('is_auto_transmission', true)
        ->whereNotNull('transmission_time')
        ->where('transmission_time', '<=', $currentTime)
        ->where($currentDayColumn, true)
        ->get();

    foreach ($settings as $setting) {
        $contractorId = $setting->contractor_id;

        // 当日すでに送信済みかチェック（execution_logのstatusで判定）
        // → 当日のexecution_logがSUCCESSかつ送信済みならスキップ
        $executionLog = WmsAutoOrderExecutionLog::where('contractor_id', $contractorId)
            ->where('executed_date', today())
            ->where('status', 'SUCCESS')
            ->first();

        if (! $executionLog) {
            // 候補生成がまだ完了していない → スキップ
            continue;
        }

        // すでに送信済みかチェック（ProcessAutoSendJobの完了で判定）
        $alreadySent = WmsQueueProgress::where('job_type', 'auto_send')
            ->where('status', 'completed')
            ->whereDate('created_at', today())
            ->whereJsonContains('metadata->contractor_id', $contractorId)
            ->exists();

        if ($alreadySent) {
            continue;
        }

        // 対象の発注先（親＋子）のバッチコードを取得
        $allContractorIds = WmsContractorSetting::getContractorIdsWithChildren($contractorId);

        // 当日のバッチで未送信の候補があるか確認
        $batchCode = WmsOrderCandidate::whereIn('contractor_id', $allContractorIds)
            ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED, CandidateStatus::CONFIRMED])
            ->whereDate('created_at', today())
            ->value('batch_code');

        if (! $batchCode) {
            // 移動候補のバッチコードもチェック
            $batchCode = WmsStockTransferCandidate::whereIn('contractor_id', $allContractorIds)
                ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED, CandidateStatus::CONFIRMED])
                ->whereDate('created_at', today())
                ->value('batch_code');
        }

        if (! $batchCode) {
            continue; // 送信対象の候補なし
        }

        // ProcessAutoSendJob dispatch
        $autoSendProgress = WmsQueueProgress::createJob(
            WmsQueueProgress::JOB_TYPE_AUTO_SEND,
            null,
            ['contractor_id' => $contractorId, 'batch_code' => $batchCode, 'source' => 'scheduled_transmit']
        );

        ProcessAutoSendJob::dispatch(
            progressId: $autoSendProgress->job_id,
            batchCode: $batchCode,
            contractorId: $contractorId,
            executionLogId: $executionLog->id,
        );
    }

    return self::SUCCESS;
}
```

#### 3. スケジューラ登録

**ファイル:** `routes/console.php`

```php
Schedule::command('wms:auto-order-transmit')
    ->everyFiveMinutes()
    ->description('送信時刻に基づく自動送信');
```

#### 4. ProcessAutoSendJob の修正

変更不要。既存の7ステップパイプライン（強制承認→確定→ファイル生成→JX送信）をそのまま利用する。送信時刻に達した時点で、残りのPENDING/APPROVEDを全て確定して送信するのは正しい動作。

### 影響範囲

| 対象 | 影響 |
|---|---|
| `ProcessOrderCandidateGenerationJob` | `dispatchAutoSendIfNeeded()` 削除 — 候補生成後に即送信されなくなる |
| `ProcessAutoSendJob` | 変更なし — 新コマンドから呼ばれるようになる |
| `wms:auto-order-scheduled` | 変更なし |
| 手動実行（UI） | 変更なし — UIからの「発注候補生成」ボタンは引き続き候補生成のみ |
| 承認レベル | 正しく機能するようになる（生成時にSTATUS1/2/3適用、送信時まで維持） |

## 制約

- FK禁止（プロジェクトルール）
- `migrate:fresh` / `migrate:refresh` 禁止
- DB変更なし（新規テーブル・カラム不要）

## 対象ファイル

### 新規作成

| ファイル | 内容 |
|---|---|
| `app/Console/Commands/AutoOrder/AutoOrderTransmitCommand.php` | 自動送信スケジューラコマンド |

### 既存変更

| ファイル | 内容 |
|---|---|
| `app/Jobs/ProcessOrderCandidateGenerationJob.php` | `dispatchAutoSendIfNeeded()` 削除 |
| `routes/console.php` | `wms:auto-order-transmit` スケジューラ登録追加 |

### 参照のみ

| ファイル | 内容 |
|---|---|
| `app/Jobs/ProcessAutoSendJob.php` | 既存の自動送信ジョブ（変更なし） |
| `app/Models/WmsContractorSetting.php` | `transmission_time`, `is_auto_transmission` 参照 |
| `app/Models/WmsAutoOrderExecutionLog.php` | 当日実行済み判定 |
| `app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php` | 既存スケジューラ（参考） |

## 確認事項

1. **送信済み判定方法**: `WmsQueueProgress` の `job_type=auto_send` + `status=completed` で判定するか、`WmsAutoOrderExecutionLog` に送信済みフラグを追加するか？
2. **バッチコード取得方法**: 当日の候補から `batch_code` を取得する方法で正しいか？`WmsAutoOrderJobControl` の `batch_code` を使うべきか？
3. **エラー時の再送**: 送信失敗時に次の5分周期で再送を試みるか？
