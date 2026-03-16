# 自動発注フロー改善 仕様書

**作成日**: 2026-02-27
**ステータス**: ドラフト（実DB検証済み）
**対象ブランチ**: (未定)
**DB検証**: localhost / sakemaru_hana_prod にて全テーブル構造を確認済み (2026-02-27)

### DB検証で発見された重要事項

| # | 発見事項 | 仕様書への反映 |
|---|---------|--------------|
| 1 | `wms_stock_transfer_candidates.status` に CONFIRMED が存在しない | 移動候補は APPROVED → EXECUTED の直接遷移で設計 |
| 2 | `stock_transfer_queue.action_type` に CANCEL が存在しない | キャンセル機能実装時に ENUM 値の追加が必要 |
| 3 | `wms_auto_order_job_controls.settlement_status` は varchar(20) | ENUM型ではなくvarchar型として扱う |
| 4 | `wms_contractor_warehouse_settings` は空テーブル（0件） | 新規カラム追加に影響なし |
| 5 | `wms_order_candidates` / `wms_order_incoming_schedules` は0件 | テストデータの準備が必要 |
| 6 | `wms_contractor_settings` は1052件、ほぼ全件 `is_auto_transmission=1` | 自動発注時刻設定の移行計画が必要 |

---

## 目次

1. [背景・課題](#1-背景課題)
2. [現状分析](#2-現状分析)
3. [改善仕様](#3-改善仕様)
4. [DB変更設計](#4-db変更設計)
5. [実装設計](#5-実装設計)
6. [テスト計画](#6-テスト計画)
7. [実装順序](#7-実装順序)
8. [関連ファイル一覧](#8-関連ファイル一覧)

---

## 1. 背景・課題

### 現在の自動発注フロー

```
画面1: 発注・移動候補生成 (admin/wms-auto-order-job-controls)
  ↓
画面2: 移動候補処理 (admin/wms-stock-transfer-candidates)
画面3: 発注候補処理 (admin/wms-order-candidates)
  ↓
画面4: 発注・移動確定待ち (admin/wms-order-confirmation-waiting)
  ↓
入荷予定 / CSV・JX送信
```

### 課題一覧

| # | 課題 | 影響 |
|---|------|------|
| 1 | 仕入先別の自動発注タイミングが設定できない | 全仕入先で同一タイミングのCLI実行が必要 |
| 2 | フロー途中での手動強制発注・移動ができない | APPROVED候補がある場合に新規計算がブロックされる |
| 3 | 確定レベルの制御ができない | 全ての候補が同じフローを通る必要がある |
| 4 | 発注確定後のキャンセルができない | 確定後に入荷予定の取消が不可能 |

---

## 2. 現状分析

### 2.1 処理フロー詳細

```
┌─────────────────────────────────────────────┐
│ Step 1: 候補生成                              │
│ ProcessOrderCandidateGenerationJob            │
│  ├─ StockSnapshotService.generateAll()        │
│  │   → wms_item_stock_snapshots 生成          │
│  └─ OrderCandidateCalculationService          │
│      ├─ INTERNAL移動候補計算                   │
│      │   → wms_stock_transfer_candidates      │
│      └─ EXTERNAL発注候補計算                   │
│          → wms_order_candidates               │
│                                               │
│ 排他制御:                                      │
│  - hasRunningJob()で二重実行防止               │
│  - APPROVED候補がある場合は計算不可             │
└──────────────────┬──────────────────────────┘
                   ▼
┌─────────────────────────────────────────────┐
│ Step 2: 承認                                  │
│ 画面: 発注候補処理 / 移動候補処理              │
│  ├─ status: PENDING → APPROVED               │
│  ├─ 除外: PENDING → EXCLUDED                 │
│  ├─ 手動数量修正 (is_manually_modified)       │
│  └─ 楽観ロック (lock_version) で競合検知      │
└──────────────────┬──────────────────────────┘
                   ▼
┌─────────────────────────────────────────────┐
│ Step 3: 確定                                  │
│ ProcessOrderConfirmationJob                   │
│  ├─ 移動候補確定                               │
│  │   TransferCandidateExecutionService        │
│  │   ├─ status → EXECUTED                     │
│  │   ├─ stock_transfer_queue (CREATE) 生成    │
│  │   └─ WmsOrderIncomingSchedule              │
│  │       (order_source=TRANSFER) 作成         │
│  ├─ 発注候補確定                               │
│  │   OrderExecutionService                    │
│  │   ├─ status → CONFIRMED                    │
│  │   └─ WmsOrderIncomingSchedule              │
│  │       (order_source=AUTO) 作成             │
│  ├─ CSVファイル生成                            │
│  │   OrderDataFileService                     │
│  └─ JXファイル生成・送信                       │
│      OrderTransmissionService                 │
└─────────────────────────────────────────────┘
```

### 2.2 ステータス遷移（現状） ※実DB検証済み

**発注候補 (wms_order_candidates.status)**:
```
enum('PENDING','APPROVED','CONFIRMED','EXCLUDED','EXECUTED')

PENDING → APPROVED → CONFIRMED → EXECUTED
    ↓
  EXCLUDED
```

**移動候補 (wms_stock_transfer_candidates.status)**:
```
enum('PENDING','APPROVED','EXCLUDED','EXECUTED')
※ CONFIRMEDは存在しない（発注候補とは異なる）

PENDING → APPROVED → EXECUTED
    ↓
  EXCLUDED
```

**入庫予定 (wms_order_incoming_schedules.status)**:
```
enum('PENDING','PARTIAL','CONFIRMED','TRANSMITTED','CANCELLED')
※ 改善で PARTIAL_CANCELLED を追加予定

PENDING → PARTIAL → CONFIRMED → TRANSMITTED
   ↓         ↓
CANCELLED  PARTIAL_CANCELLED
(全キャンセル) (一部入庫後キャンセル: received_quantity維持)
```

**stock_transfer_queue.action_type**:
```
enum('CREATE','UPDATE','DELIVER')
※ CANCELは存在しない → キャンセル機能実装時に追加が必要
```

**wms_auto_order_job_controls.settlement_status**:
```
varchar(20) NOT NULL DEFAULT 'PENDING'
※ ENUM型ではなくvarchar型
```

### 2.3 発注数量の計算ロジック

```
利用可能在庫 = 現在の有効在庫(current_effective_stock) + 入荷予定数(incoming_quantity)
不足数 = 安全在庫(safety_stock) - 利用可能在庫
発注数量 = 不足数 をロット単位で切り上げ
```

**入荷予定数の計算**: PENDING/PARTIAL ステータスの `expected_quantity - received_quantity` を合計

### 2.4 排他制御（現状）

| メカニズム | 対象 | 方式 |
|-----------|------|------|
| ジョブ排他 | 発注計算Job | `hasRunningJob()` チェック |
| APPROVED候補チェック | 発注計算 | APPROVED件数 > 0 で計算ブロック |
| 楽観ロック | 候補の個別更新 | `lock_version` カラム |

---

## 3. 改善仕様

### 3.1 仕入先別自動発注タイミング

**概要**: `wms_contractor_settings` に自動発注生成時刻を設定し、仕入先ごとに自動発注タイミングを制御する。

**動作仕様**:
- スケジューラーが5分間隔で実行
- 各仕入先の `auto_order_generation_time` と現在時刻を比較
- 当日まだ発注生成が実行されていない仕入先に対して発注計算を実行
- 既存の曜日設定 (`is_transmission_sun` ～ `is_transmission_sat`) を発注生成の曜日チェックにも活用

**フロー**:
```
Scheduler (毎5分)
  ↓
wms_contractor_settings を参照
  ↓
auto_order_generation_time <= 現在時刻 AND 曜日チェック
  ↓
wms_auto_order_execution_log で当日の実行状況を確認
  ├─ RUNNING or SUCCESS のレコードあり → スキップ
  ├─ FAILED のみ → スキップ（管理者が手動で再実行する）
  └─ レコードなし → 実行対象
  ↓
execution_log に RUNNING で記録
  ↓
仕入先別に ProcessOrderCandidateGenerationJob をdispatch
  ↓
Job完了時に execution_log を SUCCESS/FAILED に更新
  ├─ SUCCESS: job_control_id を記録
  └─ FAILED: error_details に原因を保存
```

**既存フローとの互換性**:
- `auto_order_generation_time` が NULL の場合は自動実行対象外（従来通り手動実行）
- 既存のCLI実行 (`wms:auto-order-calculate`) は引き続き利用可能

### 3.2 手動強制発注・移動

**概要**: フローの途中でも、特定の仕入先に対して即座に発注・移動データを生成できるようにする。

**動作仕様**:
- APPROVED候補が存在しても、**異なる仕入先**に対しては発注計算を実行可能にする
- 現在の「APPROVED候補がある場合は計算不可」の制約を仕入先単位に変更

**排他制御の変更（仕入先単位）**:
```php
// 改善後: APPROVED または PENDING の候補がある仕入先はブロック
$existingCount = WmsOrderCandidate::whereIn('status', ['PENDING', 'APPROVED'])
    ->where('contractor_id', $contractorId)
    ->count();

// ※ wms_stock_transfer_candidates.contractor_id は NULLABLE
//    INTERNAL移動の場合はcontractor_idが設定されるが、
//    NULLの移動候補はこのチェックに該当しない（仕入先と無関係の移動）
$existingTransferCount = WmsStockTransferCandidate::whereIn('status', ['PENDING', 'APPROVED'])
    ->where('contractor_id', $contractorId)  // NULLは対象外
    ->count();

if ($existingCount > 0 || $existingTransferCount > 0) {
    throw new RuntimeException("仕入先ID:{$contractorId} に未処理の候補が存在します");
}
```

**注意: `wms_stock_transfer_candidates.contractor_id` はNULLABLE**
- INTERNAL移動（仕入先=自社倉庫間）の場合はcontractor_idが設定される
- contractor_id=NULLの移動候補は仕入先別の排他制御には含まれない
- 全仕入先一括計算（既存CLI）の場合は従来通り全APPROVED候補チェックを維持

**重要: PENDING候補の重複防止**

現状の問題点:
- 既存のチェックは APPROVED のみ。PENDING候補がある状態で再計算すると**同一商品に複数の候補が生成**される
- 手動生成後に自動発注が動くと二重発注リスクがある

対策: **PENDING + APPROVED の両方を仕入先単位でチェック**し、未処理候補がある仕入先は計算をスキップする。
- 手動生成でPENDING候補がある → 自動発注はその仕入先をスキップ
- 自動発注でPENDING候補がある → 手動強制生成はブロック（削除してから再生成）
- これにより同一仕入先内での候補重複を防止

**自動発注スケジューラーでの扱い**:
```
スケジューラー実行時
  ↓
仕入先ごとにチェック:
  ├─ execution_log: RUNNING/SUCCESS → スキップ（当日実行済み）
  ├─ PENDING/APPROVED候補あり → スキップ（未処理候補あり）
  └─ どちらもなし → 計算実行
```

**UI変更**:
- 画面1（ジョブ管理）に「仕入先別強制生成」ボタンを追加
- 仕入先を選択して個別に発注計算を実行可能に
- 既存PENDING候補がある場合は「削除して再生成」の確認ダイアログを表示

### 3.3 確定レベル制御

**概要**: `wms_contractor_warehouse_settings` に確定レベルを設定し、自動発注データの処理レベルを制御する。

**確定レベル定義**:

| レベル | 名称 | 発注候補の処理 | 移動候補の処理 |
|--------|------|---------------|---------------|
| STATUS1 | 候補表示 | PENDING のまま候補一覧に表示 | PENDING のまま候補一覧に表示 |
| STATUS2 | 承認まで | APPROVED まで自動進行（確定待ち状態） | APPROVED → 確定待ち |
| STATUS3 | 確定まで | CONFIRMED まで自動進行（入荷候補作成） | EXECUTED まで自動進行 |

**移動候補の特別ルール**: 移動候補については全て承認後に確定待ちにする（STATUS2以上の場合）

**処理フロー（改善後）**:
```
候補生成（PENDING）
  ├─ STATUS1: ここで停止。ユーザーが手動で承認→確定
  ├─ STATUS2: 自動で APPROVED まで進行。ユーザーが手動で確定
  └─ STATUS3: 自動で CONFIRMED/EXECUTED まで進行。入荷候補も自動作成
```

**確定レベルの適用単位**: 倉庫×仕入先の組み合わせごとに設定

### 3.4 発注確定後のキャンセル

**概要**: 確定済みの発注データをキャンセルし、関連する入荷予定データを削除する。

**キャンセル可能条件**:

| 入庫予定ステータス | キャンセル可否 | 遷移先 | 理由 |
|------------------|-------------|--------|------|
| PENDING | ○ 可能 | → CANCELLED | 未入庫のため全キャンセル |
| PARTIAL | ○ 可能 | → PARTIAL_CANCELLED | 入庫済み数量(received_quantity)は維持、残数量の入荷を取消 |
| CONFIRMED | × 不可 | - | 既に入庫完了 |
| TRANSMITTED | × 不可 | - | 基幹システム連携済み |
| CANCELLED | - | - | 既にキャンセル済み |
| PARTIAL_CANCELLED | - | - | 既にキャンセル済み |

**PARTIAL_CANCELLEDの意味**:
- `received_quantity > 0` の入庫実績は記録として維持される
- `expected_quantity - received_quantity` の残数量分が入荷取消となる
- 次回の在庫スナップショットで `incoming_quantity` の計算対象から除外される
- 不足分は次回の発注計算で自動的に再計算される

**キャンセル時の処理フロー**:
```
キャンセル操作
  ↓
ステータス判定
  ├─ PENDING  → CANCELLED（全キャンセル）
  └─ PARTIAL  → PARTIAL_CANCELLED（一部入庫後キャンセル）
  ↓
cancelled_at, cancelled_by, cancellation_reason を記録
  ↓
発注候補のステータス更新
  ├─ AUTO発注: WmsOrderCandidate.status → APPROVED に戻す（再確定可能に）
  └─ TRANSFER: WmsStockTransferCandidate.status → APPROVED に戻す
  ↓
関連データの処理
  ├─ TRANSFER の場合:
  │   stock_transfer_queue に CANCEL アクション追加が必要
  │   ※ 現在の action_type ENUM は ('CREATE','UPDATE','DELIVER') のみ
  │   → ALTER TABLE で 'CANCEL' を追加する必要あり
  └─ AUTO の場合: 購買キュー (CANCEL) を作成（該当する場合）
  ↓
次回の発注計算で入荷予定に含まれなくなる
```

**在庫への影響**:
- 発注確定時点では real_stocks に直接変更なし
- 入荷予定 (WmsOrderIncomingSchedule) のみが在庫計算に影響
- キャンセルにより CANCELLED / PARTIAL_CANCELLED ステータスとなった入荷予定は、次回の在庫スナップショットで `incoming_quantity` から除外される
- **追加の在庫戻しロジックは不要**（入荷予定の状態変更のみで対応可能）

---

## 4. DB変更設計

### 4.1 wms_contractor_settings: 自動発注生成時刻追加

```sql
ALTER TABLE wms_contractor_settings
ADD COLUMN auto_order_generation_time VARCHAR(5) NULL
    COMMENT '自動発注生成時刻 (HH:MM形式)'
    AFTER transmission_time;
```

**マイグレーション**: `add_auto_order_generation_time_to_wms_contractor_settings_table`

### 4.2 wms_contractor_warehouse_settings: 確定レベル追加

```sql
ALTER TABLE wms_contractor_warehouse_settings
ADD COLUMN confirmation_level ENUM('STATUS1', 'STATUS2', 'STATUS3')
    NOT NULL DEFAULT 'STATUS1'
    COMMENT '確定レベル: STATUS1=候補表示, STATUS2=承認まで, STATUS3=確定まで'
    AFTER designated_code;

CREATE INDEX idx_wcws_confirmation_level
    ON wms_contractor_warehouse_settings (confirmation_level);
```

**マイグレーション**: `add_confirmation_level_to_wms_contractor_warehouse_settings_table`

**Enum定義**: `App\Enums\AutoOrder\ConfirmationLevel`

```php
enum ConfirmationLevel: string
{
    case STATUS1 = 'STATUS1';  // 候補表示のみ
    case STATUS2 = 'STATUS2';  // 承認まで自動実行
    case STATUS3 = 'STATUS3';  // 確定まで自動実行

    public function label(): string
    {
        return match ($this) {
            self::STATUS1 => '候補表示',
            self::STATUS2 => '承認まで',
            self::STATUS3 => '確定まで',
        };
    }
}
```

### 4.3 wms_order_incoming_schedules: PARTIAL_CANCELLED追加・キャンセル関連カラム追加

```sql
-- ステータスENUMに PARTIAL_CANCELLED を追加
ALTER TABLE wms_order_incoming_schedules
MODIFY COLUMN status ENUM('PENDING','PARTIAL','CONFIRMED','TRANSMITTED','CANCELLED','PARTIAL_CANCELLED')
    NOT NULL DEFAULT 'PENDING';

-- キャンセル関連カラム追加
ALTER TABLE wms_order_incoming_schedules
ADD COLUMN cancelled_at DATETIME NULL COMMENT 'キャンセル日時' AFTER status,
ADD COLUMN cancelled_by BIGINT UNSIGNED NULL COMMENT 'キャンセル者ID' AFTER cancelled_at,
ADD COLUMN cancellation_reason VARCHAR(500) NULL COMMENT 'キャンセル理由' AFTER cancelled_by;

CREATE INDEX idx_ois_status_cancelled
    ON wms_order_incoming_schedules (status, cancelled_at);
```

**マイグレーション**: `add_cancel_columns_to_wms_order_incoming_schedules_table`

### 4.4 wms_auto_order_execution_log: 仕入先別実行履歴（新規テーブル）

既存の `wms_auto_order_job_controls` は変更せず、仕入先別の実行履歴を別テーブルで管理する。

```sql
CREATE TABLE wms_auto_order_execution_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contractor_id BIGINT UNSIGNED NOT NULL COMMENT '仕入先ID',
    executed_date DATE NOT NULL COMMENT '実行日',
    job_control_id BIGINT UNSIGNED NULL COMMENT '関連するwms_auto_order_job_controls.id',
    status ENUM('RUNNING', 'SUCCESS', 'FAILED') NOT NULL DEFAULT 'RUNNING' COMMENT '実行結果',
    error_details TEXT NULL COMMENT 'FAILED時の原因',
    started_at DATETIME NOT NULL COMMENT '実行開始日時',
    finished_at DATETIME NULL COMMENT '実行完了日時',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_contractor_date (contractor_id, executed_date),
    INDEX idx_executed_date (executed_date),
    INDEX idx_status (status)
) COMMENT '仕入先別自動発注実行ログ';
```

**マイグレーション**: `create_wms_auto_order_execution_log_table`

**設計方針**:
- 既存の `wms_auto_order_job_controls` には一切変更を加えない
- スケジューラーの「当日未実行」判定はこのテーブルで行う
- FAILED時は `error_details` に原因を保存し、自動再実行はしない（管理者確認後に手動再実行）

**当日実行済み判定ロジック**:
```php
// SUCCESS または RUNNING のレコードがあれば「実行済み」と判定
// FAILED のみの場合は「未実行」扱いにしない（管理者の手動再実行が必要）
WmsAutoOrderExecutionLog::where('contractor_id', $contractorId)
    ->where('executed_date', today())
    ->whereIn('status', ['RUNNING', 'SUCCESS'])
    ->exists();
```

### 4.5 stock_transfer_queue: CANCEL action_type追加

```sql
ALTER TABLE stock_transfer_queue
MODIFY COLUMN action_type ENUM('CREATE','UPDATE','DELIVER','CANCEL')
    NOT NULL DEFAULT 'CREATE';
```

**マイグレーション**: `add_cancel_action_type_to_stock_transfer_queue_table`

**注意**: stock_transfer_queue は基幹システム(sakemaru-ai-core)側で処理されるテーブル。
CANCEL action_type の処理ハンドラーを基幹システム側にも追加する必要がある。

### 4.6 DB変更サマリー

| # | テーブル | 変更 | カラム/インデックス | 難度 |
|---|---------|------|-------------------|------|
| 1 | wms_contractor_settings | カラム追加 | `auto_order_generation_time` (VARCHAR:5) | 低 |
| 2 | wms_contractor_warehouse_settings | カラム追加 | `confirmation_level` (ENUM) + INDEX | 低 |
| 3 | wms_order_incoming_schedules | ENUM変更+カラム追加 | `status` に 'PARTIAL_CANCELLED' 追加 + `cancelled_at`, `cancelled_by`, `cancellation_reason` + INDEX | 低 |
| 4 | **wms_auto_order_execution_log** | **新規テーブル** | 仕入先別実行履歴（既存job_controlsは変更なし） | 低 |
| 5 | stock_transfer_queue | ENUM変更 | `action_type` に 'CANCEL' 追加 | 中（基幹システム連携） |

**既存ステータスENUMの注意点**:
- `wms_order_candidates.status`: CONFIRMED あり → 変更不要
- `wms_stock_transfer_candidates.status`: **CONFIRMED なし** (PENDING,APPROVED,EXCLUDED,EXECUTED のみ) → 変更不要（APPROVED→EXECUTEDで直接遷移）
- `wms_order_incoming_schedules.status`: CANCELLED あり → **PARTIAL_CANCELLED を追加**

---

## 5. 実装設計

### 5.1 改善1: 仕入先別自動発注スケジューラー

**新規コマンド**: `wms:auto-order-scheduled`

```php
// app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php
class AutoOrderScheduledCommand extends Command
{
    protected $signature = 'wms:auto-order-scheduled';
    protected $description = '仕入先別スケジュールに基づく自動発注計算';

    public function handle()
    {
        $now = now();
        $currentTime = $now->format('H:i');
        $currentDayColumn = 'is_transmission_' . strtolower($now->format('D'));

        // 自動発注対象の仕入先を取得
        $settings = WmsContractorSetting::query()
            ->whereNotNull('auto_order_generation_time')
            ->where('auto_order_generation_time', '<=', $currentTime)
            ->where($currentDayColumn, true)
            ->get();

        foreach ($settings as $setting) {
            // 当日すでに実行済み（RUNNING or SUCCESS）かチェック
            $alreadyRun = WmsAutoOrderExecutionLog::where('contractor_id', $setting->contractor_id)
                ->where('executed_date', $now->toDateString())
                ->whereIn('status', ['RUNNING', 'SUCCESS'])
                ->exists();

            if ($alreadyRun) continue;

            // 未処理候補（PENDING/APPROVED）がある仕入先はスキップ
            $hasPendingCandidates = WmsOrderCandidate::whereIn('status', ['PENDING', 'APPROVED'])
                ->where('contractor_id', $setting->contractor_id)
                ->exists();
            $hasPendingTransfers = WmsStockTransferCandidate::whereIn('status', ['PENDING', 'APPROVED'])
                ->where('contractor_id', $setting->contractor_id)
                ->exists();

            if ($hasPendingCandidates || $hasPendingTransfers) {
                Log::info("仕入先ID:{$setting->contractor_id} に未処理候補あり、スキップ");
                continue;
            }

            // 実行ログを記録
            $log = WmsAutoOrderExecutionLog::create([
                'contractor_id' => $setting->contractor_id,
                'executed_date' => $now->toDateString(),
                'status' => 'RUNNING',
                'started_at' => $now,
            ]);

            // 仕入先別に候補生成Job起動
            // ※ 既存コンストラクタ: __construct($jobId, $deletePending=false)
            //    新規パラメータ: $contractorId, $executionLogId をオプショナルで追加
            //    既存の画面からのdispatch（$jobId, false）は互換維持
            $queueProgress = WmsQueueProgress::createForJob('auto-order-scheduled');
            ProcessOrderCandidateGenerationJob::dispatch(
                jobId: $queueProgress->job_id,
                deletePending: false,
                contractorId: $setting->contractor_id,
                executionLogId: $log->id,
            );
        }
    }
}
```

**Scheduler登録** (`routes/console.php` or `Kernel.php`):
```php
Schedule::command('wms:auto-order-scheduled')->everyFiveMinutes()->onOneServer();
```

### 5.2 改善2: 手動強制発注・排他制御の仕入先単位化

**OrderCandidateCalculationService 修正**:
- `calculate()` メソッドに `?int $contractorId = null` パラメータ追加
- 排他制御を2パターンに分岐（**発注候補 + 移動候補の両方をチェック**）

**排他制御パターンA: 仕入先指定あり（スケジューラー/手動強制）**:
```php
if ($contractorId !== null) {
    // PENDING + APPROVED 両方チェック（仕入先単位）
    $hasOrderCandidates = WmsOrderCandidate::query()
        ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
        ->where('contractor_id', $contractorId)
        ->exists();

    // 移動候補もチェック（contractor_id=NULLはWHERE句で自然除外）
    $hasTransferCandidates = WmsStockTransferCandidate::query()
        ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
        ->where('contractor_id', $contractorId)
        ->exists();

    if ($hasOrderCandidates || $hasTransferCandidates) {
        throw new RuntimeException("仕入先ID:{$contractorId} に未処理の候補が存在します");
    }
}
```

**排他制御パターンB: 仕入先指定なし（既存CLI互換）**:
```php
else {
    // 従来通りAPPROVEDチェック + 移動候補も追加
    $hasApprovedOrders = WmsOrderCandidate::query()
        ->where('status', CandidateStatus::APPROVED)
        ->exists();

    $hasApprovedTransfers = WmsStockTransferCandidate::query()
        ->where('status', CandidateStatus::APPROVED)
        ->exists();  // contractor_id=NULL含む全件

    if ($hasApprovedOrders || $hasApprovedTransfers) {
        throw new RuntimeException("確定待ちの候補があります。先に確定を行ってください。");
    }
}
```

**Filament UI追加**: 画面1（ジョブ管理）に仕入先選択つき「強制発注生成」ツールバーアクション

### 5.3 改善3: 確定レベル自動適用

**候補生成後の自動レベル適用** (`ProcessOrderCandidateGenerationJob` の後処理):

**重要: STATUS3の自動確定は候補生成Jobの最終ステップとして同一トランザクション内で実行する。**
候補がPENDING状態で一時停止することがないため、排他制御（PENDING候補チェック）とは干渉しない。

```php
// 候補生成後、確定レベルに応じて自動処理
// ※ この処理は ProcessOrderCandidateGenerationJob 内で
//    候補INSERT直後に同一トランザクションで実行する

// 1. confirmation_level を倉庫×仕入先で一括取得
$levels = WmsContractorWarehouseSetting::all()
    ->keyBy(fn ($s) => "{$s->warehouse_id}-{$s->contractor_id}");

foreach ($candidates as $candidate) {
    $key = "{$candidate->warehouse_id}-{$candidate->contractor_id}";
    $level = $levels[$key]->confirmation_level ?? ConfirmationLevel::STATUS1;

    if ($level === ConfirmationLevel::STATUS2 || $level === ConfirmationLevel::STATUS3) {
        // 自動承認
        $candidate->update(['status' => CandidateStatus::APPROVED]);
    }

    if ($level === ConfirmationLevel::STATUS3) {
        // 自動確定（入荷予定作成含む）
        if ($candidate instanceof WmsOrderCandidate) {
            $orderExecutionService->confirmCandidate($candidate);
        } elseif ($candidate instanceof WmsStockTransferCandidate) {
            $transferExecutionService->executeCandidate($candidate);
        }
    }
}
```

**confirmation_level未設定時の動作**:
- `wms_contractor_warehouse_settings` にレコードがない場合（現在0件）→ STATUS1（候補表示のみ）として扱う
- 既存の動作と完全に互換

### 5.4 改善4: キャンセル機能

**新規サービス**: `OrderCancellationService`

```php
// app/Services/AutoOrder/OrderCancellationService.php
class OrderCancellationService
{
    public function cancelIncomingSchedule(
        WmsOrderIncomingSchedule $schedule,
        int $userId,
        string $reason
    ): void {
        // 1. キャンセル可能チェック
        if (!in_array($schedule->status, [
            IncomingScheduleStatus::PENDING,
            IncomingScheduleStatus::PARTIAL,
        ])) {
            throw new \RuntimeException('この入庫予定はキャンセルできません');
        }

        DB::connection('sakemaru')->transaction(function () use ($schedule, $userId, $reason) {
            // 2. 入庫予定をキャンセル（PARTIALの場合はPARTIAL_CANCELLED）
            $cancelStatus = $schedule->status === IncomingScheduleStatus::PARTIAL
                ? IncomingScheduleStatus::PARTIAL_CANCELLED
                : IncomingScheduleStatus::CANCELLED;

            $schedule->update([
                'status' => $cancelStatus,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);

            // 3. 発注候補のステータスを戻す
            if ($schedule->order_candidate_id) {
                WmsOrderCandidate::where('id', $schedule->order_candidate_id)
                    ->update(['status' => CandidateStatus::APPROVED]);
            }

            // 4. 移動候補のステータスを戻す + stock_transfer_queue CANCEL
            //    ※ 移動候補は EXECUTED → APPROVED に戻す（CONFIRMEDは存在しない）
            if ($schedule->transfer_candidate_id) {
                WmsStockTransferCandidate::where('id', $schedule->transfer_candidate_id)
                    ->update(['status' => CandidateStatus::APPROVED]);

                if ($schedule->stock_transfer_id) {
                    // ※ stock_transfer_queue.action_type に 'CANCEL' 追加が前提
                    DB::connection('sakemaru')->table('stock_transfer_queue')->insert([
                        'client_id' => /* クライアントID */,
                        'action_type' => 'CANCEL',
                        'stock_transfer_id' => $schedule->stock_transfer_id,
                        'request_id' => "transfer-cancel-{$schedule->id}-" . now()->timestamp,
                        'process_date' => now()->toDateString(),
                        'delivered_date' => now()->toDateString(),
                        'from_warehouse_code' => /* 移動元倉庫コード */,
                        'to_warehouse_code' => /* 移動先倉庫コード */,
                        'items' => json_encode([]),
                        'created_at' => now(),
                    ]);
                }
            }
        });
    }
}
```

**Filament UI**: 入庫予定一覧にキャンセルアクション（理由入力モーダル付き）を追加

### 5.5 改善5: 自動発注実行ログ画面

**目的**: 仕入先別の自動発注実行状況の確認・FAILED時の原因確認・手動再実行を行う画面。

**リソース構成**:
```
app/Filament/Resources/WmsAutoOrderExecutionLogs/
├── WmsAutoOrderExecutionLogResource.php
├── Pages/
│   └── ListWmsAutoOrderExecutionLogs.php
└── Tables/
    └── WmsAutoOrderExecutionLogsTable.php
```

**配置**: 既存の「発注・移動候補生成」画面 (WmsAutoOrderJobControlResource) と同じナビゲーショングループに配置。

**テーブルカラム**:

| カラム | 表示 | 備考 |
|--------|------|------|
| executed_date | 実行日 | 日付フィルタ対応 |
| contractor.name | 仕入先名 | 仕入先フィルタ対応 |
| status | ステータス | バッジ表示: RUNNING=青, SUCCESS=緑, FAILED=赤 |
| started_at | 開始日時 | |
| finished_at | 完了日時 | |
| error_details | エラー内容 | FAILEDの場合のみ表示。長文は省略表示→モーダルで全文確認 |
| job_control_id | 関連ジョブ | リンクで候補生成履歴画面へ遷移 |

**デフォルト表示**: 当日のログを新しい順に表示。

**フィルタ**:
- 実行日（DatePicker）
- 仕入先（Select）
- ステータス（Select: RUNNING / SUCCESS / FAILED）

**レコードアクション**:

1. **エラー詳細表示** (FAILEDの場合のみ)
```php
Action::make('viewError')
    ->label('エラー詳細')
    ->icon('heroicon-o-exclamation-triangle')
    ->color('danger')
    ->visible(fn ($record) => $record->status === 'FAILED')
    ->modalHeading('エラー詳細')
    ->modalSubmitAction(false)
    ->modalCancelActionLabel('閉じる')
    ->infolist(fn ($record) => [
        Section::make()->schema([
            TextEntry::make('contractor.name')->label('仕入先'),
            TextEntry::make('executed_date')->label('実行日'),
            TextEntry::make('started_at')->label('開始日時'),
            TextEntry::make('error_details')->label('エラー内容')
                ->columnSpanFull()
                ->prose(),
        ]),
    ]);
```

2. **手動再実行** (FAILEDの場合のみ)
```php
Action::make('retry')
    ->label('再実行')
    ->icon('heroicon-o-arrow-path')
    ->color('warning')
    ->visible(fn ($record) => $record->status === 'FAILED')
    ->requiresConfirmation()
    ->modalHeading('この仕入先の発注計算を再実行しますか？')
    ->modalDescription(fn ($record) =>
        "仕入先: {$record->contractor->name}\n前回エラー: " . Str::limit($record->error_details, 100))
    ->action(function ($record) {
        // 新しい実行ログを作成
        $log = WmsAutoOrderExecutionLog::create([
            'contractor_id' => $record->contractor_id,
            'executed_date' => today(),
            'status' => 'RUNNING',
            'started_at' => now(),
        ]);

        // Job起動（既存コンストラクタ互換: $jobId必須、新規パラメータはnamed args）
        $queueProgress = WmsQueueProgress::createForJob('auto-order-retry');
        ProcessOrderCandidateGenerationJob::dispatch(
            jobId: $queueProgress->job_id,
            deletePending: false,
            contractorId: $record->contractor_id,
            executionLogId: $log->id,
        );

        Notification::make()->title('再実行を開始しました')->success()->send();
    });
```

**ツールバーアクション**: なし（作成は自動発注スケジューラーまたは手動強制発注から行う）

**EMenu登録**:
```php
case WMS_AUTO_ORDER_EXECUTION_LOG = 'ordering.auto_order_execution_log';
// label: '自動発注実行ログ'
// icon: 'heroicon-o-clipboard-document-check'
// category: EMenuCategory::ORDERING
```

---

## 6. テスト計画

### 6.1 正常系テスト

| # | シナリオ | 検証内容 |
|---|---------|---------|
| T01 | STATUS1: 候補表示のみ | 候補がPENDINGで生成され、一覧に表示される |
| T02 | STATUS2: 承認まで自動 | 候補がAPPROVEDで生成され、確定待ちに表示される |
| T03 | STATUS3: 確定まで自動 | 候補がCONFIRMED/EXECUTEDで生成され、入荷予定が作成される |
| T04 | 仕入先別自動発注 | 指定時刻に該当仕入先のみ計算が実行される |
| T05 | 手動強制発注 | 他仕入先のAPPROVED候補があっても、指定仕入先の計算が実行される |
| T06 | キャンセル（PENDING） | 入庫予定が CANCELLED になり、候補がAPPROVEDに戻る |
| T07 | キャンセル（PARTIAL） | 入庫予定が PARTIAL_CANCELLED になり、received_quantity は維持、候補がAPPROVEDに戻る |

### 6.2 在庫影響テスト

| # | シナリオ | 検証内容 |
|---|---------|---------|
| T08 | 確定→在庫スナップショット | 入荷予定が`incoming_quantity`に反映される |
| T09 | キャンセル→再スナップショット | CANCELLED / PARTIAL_CANCELLED の入荷予定が`incoming_quantity`から除外される |
| T10 | キャンセル→再計算 | キャンセル後の発注計算で正しい不足数が算出される |
| T11 | 移動キャンセル→stock_transfer_queue | CANCEL アクションが正しく生成される |

### 6.3 排他制御テスト

| # | シナリオ | 検証内容 |
|---|---------|---------|
| T12 | 同一仕入先の同時計算 | 2回目の計算が仕入先単位でブロックされる |
| T13 | 異なる仕入先の同時計算 | 別仕入先の計算は正常に実行される |
| T14 | 楽観ロック競合 | 候補の同時編集が検知される |
| T15 | キャンセルと確定の同時実行 | トランザクションで一貫性が保たれる |
| T15a | **手動PENDING候補あり→自動発注** | 同一仕入先のPENDING候補があれば自動発注がスキップされる |
| T15b | **手動PENDING候補あり→別仕入先の自動発注** | 別仕入先の自動発注は正常に実行される |
| T15c | **自動PENDING候補あり→手動強制生成** | ブロックされ、削除確認ダイアログが表示される |
| T16a | FAILED後の自動再実行なし | execution_logがFAILEDのみでも自動再実行されない |
| T16b | FAILED後の手動再実行 | 管理者が手動で再実行でき、新しいログが記録される |
| T16c | **contractor_id=NULLの移動候補** | NULL移動候補が仕入先別排他制御に影響しない |
| T16d | **移動候補APPROVED→CLI一括計算ブロック** | 移動候補にAPPROVEDがある場合、CLI一括計算がブロックされる |
| T16e | **移動候補PENDING→仕入先別計算ブロック** | 同一仕入先の移動候補にPENDINGがある場合、仕入先別計算がブロックされる |

### 6.4 境界値テスト

| # | シナリオ | 検証内容 |
|---|---------|---------|
| T17 | 発注点ちょうど (stock = safety_stock) | 不足数 = 0、候補が生成されない |
| T18 | 在庫0 | 安全在庫分の候補が生成される |
| T19 | リードタイム0日 | 到着予定日が発注日と同日 |
| T20 | 入荷予定が安全在庫を超過 | 候補が生成されない |
| T21 | auto_order_generation_time = NULL | 自動実行対象外 |
| T22 | **confirmation_level未設定（レコードなし）** | STATUS1（候補表示のみ）として動作する |
| T23 | **wms_contractor_warehouse_settings 0件** | 全仕入先がSTATUS1で動作し既存と互換 |
| T23a | **PARTIAL_CANCELLED後の再計算** | PARTIAL_CANCELLEDの入庫予定がincoming_quantityに含まれず、不足分が正しく再計算される |
| T23b | **PARTIAL_CANCELLEDのreceived_quantity維持** | キャンセル後もreceived_quantityが変更されていない |

### 6.5 実行ログ画面テスト

| # | シナリオ | 検証内容 |
|---|---------|---------|
| T24 | ログ一覧表示 | 当日のログが新しい順に表示される |
| T25 | FAILEDエラー詳細モーダル | error_detailsが全文表示される |
| T26 | 手動再実行ボタン | 新しいログがRUNNINGで作成されJobがdispatchされる |
| T27 | SUCCESS/RUNNINGレコードの再実行ボタン | 再実行ボタンが非表示 |
| T28 | 日付・仕入先・ステータスフィルタ | 各フィルタが正しく機能する |

### 6.6 回帰テスト

| # | シナリオ | 検証内容 |
|---|---------|---------|
| T29 | 既存の全仕入先一括計算 | CLI実行が従来通り動作する |
| T30 | 既存の承認→確定フロー | ステータス遷移が正常 |
| T31 | CSV/JX送信 | ファイル生成・送信が正常 |
| T32 | ロット調整 | ロット適用後の数量が正しい |
| T33 | 到着予定日計算 | リードタイム+曜日+休日が正しく反映 |

### 6.6 ページアクセシビリティテスト（Headless）

**目的**: 全Filamentページ（リソース一覧・カスタムページ）がエラーなくレンダリングされることをheadlessで検証する。

**テスト方式**: Filament 4標準の `Livewire::actingAs()->test(ListPage::class)->assertSuccessful()` パターン（サーバーサイドレンダリング、ブラウザ不要のheadlessテスト）

**テスト用ユーザー**:
- `setUp()` で `sakemaru_hana_prod.users` テーブルにテスト用ユーザーを新規INSERT
- `canAccessPanel()` が `true` を返す条件を満たすユーザーを作成
- `tearDown()` でテスト用ユーザーを削除（DBを汚さない）
- **migrate:fresh / migrate:refresh は絶対に使用しない**

**DB接続**: `sakemaru` コネクション（localhost / root / パスワードなし / `sakemaru_hana_prod`）

```php
// テスト用ユーザー作成例
protected function setUp(): void
{
    parent::setUp();
    $this->user = \App\Models\Sakemaru\User::create([
        'client_id' => 1,
        'name' => 'WMS_TEST_USER_' . uniqid(),
        'email' => 'wms-test-' . uniqid() . '@test.local',
        'password' => bcrypt('test-password'),
        'is_active' => true,
    ]);
}

protected function tearDown(): void
{
    $this->user?->forceDelete();
    parent::tearDown();
}
```

**テストファイル**: `tests/Feature/Filament/AllPagesAccessibilityTest.php`

#### リソース一覧ページ（56ページ）

| # | テストID | 対象ページクラス | カテゴリ |
|---|---------|----------------|---------|
| 1 | TP01 | ListWaves | 出荷管理 |
| 2 | TP02 | ListWmsPickingWaitings | 出荷管理 |
| 3 | TP03 | ListWmsPickingTasks | 出荷管理 |
| 4 | TP04 | ListWmsCompletedPickingTasks | 出荷管理 |
| 5 | TP05 | ListWmsPickingItemEdits | 出荷管理 |
| 6 | TP06 | ListWmsPickingItemResults (Tasks内) | 出荷管理 |
| 7 | TP07 | ListWmsShipmentSlips | 出荷管理 |
| 8 | TP08 | ListWmsShipmentInspections | 出荷管理 |
| 9 | TP09 | ListWmsOrderIncomingSchedules | 入荷管理 |
| 10 | TP10 | ListWmsReceiptInspections | 入荷管理 |
| 11 | TP11 | ListWmsIncomingCompleted | 入荷管理 |
| 12 | TP12 | ListWmsIncomingTransmitted | 入荷管理 |
| 13 | TP13 | ListWmsShortages | 欠品管理 |
| 14 | TP14 | ListWmsShortagesWaitingApprovals | 欠品管理 |
| 15 | TP15 | ListWmsShortageAllocations | 欠品管理 |
| 16 | TP16 | ListFinishedWmsShortageAllocations | 欠品管理 |
| 17 | TP17 | ListHistoryWmsShortageAllocations | 欠品管理 |
| 18 | TP18 | ListWmsStockTransferCandidates | 倉庫移動 |
| 19 | TP19 | ListWmsAutoOrderJobControls | 発注処理 |
| 20 | TP20 | ListWmsOrderCandidates | 発注処理 |
| 21 | TP21 | ListWmsOrderConfirmationWaiting | 発注処理 |
| 22 | TP22 | ListWmsOrderConfirmed | 発注履歴 |
| 23 | TP23 | ListWmsOrderDataFiles | 発注履歴 |
| 24 | TP24 | ListWmsOrderDocuments | 発注履歴 |
| 25 | TP25 | ListWmsJxTransmissionLogs | 発注履歴 |
| 26 | TP26 | ListRealStocks | 在庫管理 |
| 27 | TP27 | ListWmsItemStockSnapshots | 在庫管理 |
| 28 | TP28 | ListExpirationAlerts | 在庫管理 |
| 29 | TP29p | ListWarehouses | 倉庫マスタ |
| 30 | TP30 | ListLocations | 倉庫マスタ |
| 31 | TP31 | ListFloors | 倉庫マスタ |
| 32 | TP32 | ListWarehouseStockTransferDeliveryCourses | 倉庫マスタ |
| 33 | TP33p | ListWmsWarehouseCalendars | 倉庫マスタ |
| 34 | TP34 | ListContractors | 発注マスタ |
| 35 | TP35 | ListItemContractors | 発注マスタ |
| 36 | TP36 | ListWmsContractorSettings | 発注マスタ |
| 37 | TP37 | ListWmsContractorWarehouseSettings | 発注マスタ |
| 38 | TP38 | ListWmsContractorHolidays | 発注マスタ |
| 39 | TP39 | ListWmsOrderJxSettings | 発注マスタ |
| 40 | TP40 | ListWmsMonthlySafetyStocks | 発注マスタ |
| 41 | TP41 | ListWaveSettings | 出荷マスタ |
| 42 | TP42 | ListWmsPickers | ピッキングマスタ |
| 43 | TP43 | ListWmsPickingAreas | ピッキングマスタ |
| 44 | TP44 | ListWmsPickingAssignmentStrategies | ピッキングマスタ |
| 45 | TP45 | ListWmsPickerAttendance | ピッキングマスタ |
| 46 | TP46 | ListEarnings | 統計 |
| 47 | TP47 | ListWmsPickingLogs | ログ |
| 48 | TP48 | ListWmsPickingItemResults (Results内) | ログ |
| 49 | TP49 | ListWmsRouteCalculationLogs | ログ |
| 50 | TP50 | ListWmsImportLogs | ログ |
| 51 | TP51 | ListWmsQueueJobs | ログ |
| 52 | TP52 | ListPurchases | その他 |
| 53 | TP53 | ListWarehouseContractors | その他 |
| 54 | TP54 | ListClientPrinterCourseSettings | 設定 |
| 55 | TP55 | ListDeliveryCourseChanges | 設定 |
| 56 | TP56 | ListWmsBuyerDeliveryCourseSwitchSettings | 設定 |

#### カスタムページ（7ページ）

| # | テストID | 対象ページクラス | 用途 |
|---|---------|----------------|------|
| 57 | TP57 | WmsInbound | 入荷ダッシュボード |
| 58 | TP58 | WmsOutbound | 出荷ダッシュボード |
| 59 | TP59 | FloorPlanEditor | 倉庫レイアウト |
| 60 | TP60 | PickingRouteVisualization | ピッキング経路 |
| 61 | TP61 | AutoOrderGuide | 発注ガイド |
| 62 | TP62 | TestDataGenerator | テストデータ生成 |
| 63 | TP63 | JxTestData | JXテストデータ |

#### 新規画面（改善で追加、Phase 6完了後）

| # | テストID | 対象ページクラス | 用途 |
|---|---------|----------------|------|
| 64 | TP64 | ListWmsAutoOrderExecutionLogs | 自動発注実行ログ |

**合計**: 64ページ（既存63 + 新規1）

### 6.7 リスク・懸念事項

| リスク | 対策 |
|--------|------|
| 複数バッチ間の同一商品重複発注 | PENDING+APPROVED の仕入先単位排他制御で防止 |
| PARTIAL入庫予定のキャンセル時の挙動 | **解決済み**: PARTIAL → PARTIAL_CANCELLED に遷移。received_quantityは維持、残数量分の入荷を取消。次回発注計算で不足分が自動再計算される |
| demand_breakdownによる複数入庫予定のキャンセル | order_candidate_id で関連する全入庫予定を一括キャンセル |
| STATUS3での自動確定失敗時のリカバリ | 同一トランザクション内で実行、失敗時はAPPROVEDで停止しエラーログ記録 |
| contractor_id=NULLの移動候補 | 仕入先別排他制御の対象外。全仕入先一括計算時は従来通りの全体チェック維持 |
| stock_transfer_queue CANCEL時の必須カラム | client_id, items, from/to_warehouse_code等の必須カラムを移動候補から取得して設定 |

---

## 7. 実装順序

### Phase 1: DB変更・Enum追加（基盤）
1. `ConfirmationLevel` Enum 作成
2. `IncomingScheduleStatus` に `PARTIAL_CANCELLED` 追加
3. マイグレーション作成・実行（5本）
   - `wms_contractor_settings`: `auto_order_generation_time` 追加
   - `wms_contractor_warehouse_settings`: `confirmation_level` 追加
   - `wms_order_incoming_schedules`: status ENUM に PARTIAL_CANCELLED 追加 + キャンセル関連3カラム追加
   - **`wms_auto_order_execution_log`: 新規テーブル作成**
   - `stock_transfer_queue`: action_type に CANCEL 追加
4. `WmsAutoOrderExecutionLog` モデル作成
5. 既存 Model の `$casts`, `$fillable` 更新

### Phase 2: 仕入先別自動発注（課題1）
**依存: Phase 1 完了必須**
1. `ProcessOrderCandidateGenerationJob` のコンストラクタ拡張
   - 既存パラメータ維持: `$jobId` (string), `$deletePending` (bool)
   - 新規パラメータ追加: `?int $contractorId = null`, `?int $executionLogId = null`
   - 既存の画面からの dispatch（$jobId, false）は互換維持
2. `wms:auto-order-scheduled` コマンド作成
3. `OrderCandidateCalculationService` の仕入先指定対応
4. `WmsAutoOrderExecutionLog` による実行済み判定ロジック
5. Job完了時のログ更新（SUCCESS/FAILED + error_details）
6. Scheduler 登録
7. 仕入先設定画面に `auto_order_generation_time` フィールド追加

### Phase 3: 手動強制発注（課題2）
**依存: Phase 2 完了必須**
1. 排他制御の仕入先単位化（`OrderCandidateCalculationService`）
   - **パターンA（仕入先指定あり）**: 発注候補 + 移動候補の PENDING/APPROVED を仕入先単位チェック
   - **パターンB（仕入先指定なし/CLI互換）**: 発注候補 + 移動候補の APPROVED を全件チェック
   - `contractor_id=NULL` の移動候補はパターンAでは自然除外、パターンBでは対象
2. ジョブ管理画面に「仕入先別強制生成」アクション追加
3. `ProcessOrderCandidateGenerationJob` の仕入先指定対応

### Phase 4: 確定レベル制御（課題3）
**依存: Phase 3 完了必須**
1. 候補生成後の自動レベル適用ロジック
2. 仕入先×倉庫設定画面に `confirmation_level` フィールド追加
3. 各確定レベルでのフロー検証

### Phase 5: キャンセル機能（課題4）
**依存: Phase 1 完了必須（Phase 2-4 とは独立可能）**
1. `OrderCancellationService` 作成
   - PENDING → CANCELLED、PARTIAL → PARTIAL_CANCELLED の分岐
   - received_quantity は変更しない
2. 入庫予定画面にキャンセルアクション追加
3. キャンセル時のステータス戻しロジック
4. stock_transfer_queue CANCEL 連携

### Phase 6: 実行ログ画面
**依存: Phase 2 完了必須**
1. Filamentリソース作成（WmsAutoOrderExecutionLogResource）
2. テーブルカラム・フィルタ・レコードアクション実装
3. EMenu登録

### Phase 7: テスト・検証
**依存: Phase 1-6 全完了必須**
1. 各Phaseの単体テスト（全42シナリオ + ページアクセシビリティ64ページ: T01〜T33 + サブ番号 T15a-c, T16a-e, T23a-b）
2. 統合テスト（フロー全体）
3. 回帰テスト（T29〜T33）

---

## 8. 関連ファイル一覧

### モデル
| ファイル | 説明 |
|---------|------|
| `app/Models/WmsOrderCandidate.php` | 発注候補 |
| `app/Models/WmsStockTransferCandidate.php` | 移動候補 |
| `app/Models/WmsAutoOrderJobControl.php` | ジョブ制御 |
| `app/Models/WmsOrderIncomingSchedule.php` | 入庫予定 |
| `app/Models/WmsContractorSetting.php` | 仕入先設定 |
| `app/Models/WmsContractorWarehouseSetting.php` | 仕入先×倉庫設定 |

### サービス
| ファイル | 説明 |
|---------|------|
| `app/Services/AutoOrder/OrderCandidateCalculationService.php` | 発注候補計算 |
| `app/Services/AutoOrder/OrderExecutionService.php` | 発注確定 |
| `app/Services/AutoOrder/TransferCandidateExecutionService.php` | 移動確定 |
| `app/Services/AutoOrder/StockSnapshotService.php` | 在庫スナップショット |
| `app/Services/AutoOrder/OrderDataFileService.php` | CSVファイル生成 |
| `app/Services/AutoOrder/OrderTransmissionService.php` | JXファイル生成・送信 |
| `app/Services/AutoOrder/IncomingConfirmationService.php` | 入庫確定 |

### Job・Command
| ファイル | 説明 |
|---------|------|
| `app/Jobs/ProcessOrderCandidateGenerationJob.php` | 候補生成Job |
| `app/Jobs/ProcessOrderConfirmationJob.php` | 確定Job |
| `app/Console/Commands/AutoOrder/AutoOrderCalculateCommand.php` | CLI発注計算 |

### Filamentリソース
| ファイル | 説明 |
|---------|------|
| `app/Filament/Resources/WmsAutoOrderJobControls/` | 画面1: ジョブ管理 |
| `app/Filament/Resources/WmsStockTransferCandidates/` | 画面2: 移動候補 |
| `app/Filament/Resources/WmsOrderCandidates/` | 画面3: 発注候補 |
| `app/Filament/Resources/WmsOrderConfirmationWaiting/` | 画面4: 確定待ち |

### Enum
| ファイル | 説明 |
|---------|------|
| `app/Enums/AutoOrder/CandidateStatus.php` | 候補ステータス |
| `app/Enums/AutoOrder/SettlementStatus.php` | 確定ステータス |
| `app/Enums/AutoOrder/OrderSource.php` | 発注元種別 |
| `app/Enums/AutoOrder/IncomingScheduleStatus.php` | 入庫予定ステータス |

### 既存仕様書
| ファイル | 説明 |
|---------|------|
| `storage/specifications/20260124-order-calculation-flow.md` | 発注計算フロー |
| `storage/specifications/20260124-order-calculation-logic.md` | 発注計算ロジック |
| `storage/specifications/20260127-transfer-incoming-flow-specification.md` | 移動入庫フロー |

### 既存テスト
| ファイル | 説明 |
|---------|------|
| `tests/Unit/Services/AutoOrder/OrderTransmissionServiceTest.php` | 送信テスト |
| `tests/Unit/Services/AutoOrder/TransferCandidateExecutionServiceTest.php` | 移動確定テスト |
