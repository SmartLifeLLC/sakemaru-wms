# 自動発注フロー改善 作業計画

## 前提

- 仕様書作成済み: `storage/specifications/ordering/20260227/20260227-ordering-update-specification.md`
- 実DB検証済み（2026-02-27）
- 整合性チェック完了（不整合7件を仕様書に反映済み）
- 現在ブランチ: feature/stock-transfer-map-view → release/v1.0 にマージ済み

---

## Phase 一覧

| # | Phase | 概要 | 依存 | 完了条件 |
|---|-------|------|------|---------|
| P1 | DB基盤 | マイグレーション5本・Enum・モデル作成 | なし | `php artisan migrate` 成功、全カラム追加確認 |
| P2 | 仕入先別自動発注 | Job互換拡張・スケジューラー・実行ログ判定 | P1 | コマンド実行で仕入先別に候補生成、execution_log記録 |
| P3 | 手動強制発注 | 排他制御変更（発注+移動候補2パターン）・UI追加 | P2 | PENDING/APPROVED候補がある仕入先はブロック、別仕入先は実行可能 |
| P4 | 確定レベル制御 | 自動承認・自動確定ロジック | P3 | STATUS1/2/3で正しく候補が自動遷移 |
| P5 | キャンセル機能 | CANCELLED/PARTIAL_CANCELLED分岐・UI追加 | P1 | PENDING→CANCELLED、PARTIAL→PARTIAL_CANCELLED、候補APPROVEDに戻る |
| P6 | 実行ログ画面 | Filamentリソース作成 | P2 | エラー確認・手動再実行が動作 |
| P7 | テスト・回帰検証 | 全42シナリオのテスト | P1-P6全完了 | 全テスト通過、既存CLI動作確認 |

### Phase依存関係図
```
P1（DB基盤）
├── P2（仕入先別自動発注）→ P3（手動強制発注）→ P4（確定レベル制御）
│                         └── P6（実行ログ画面）
└── P5（キャンセル機能）※P2-P4とは独立可能
                    ↓
              P7（テスト・回帰検証）← 全Phase完了後
```

---

## P1: DB基盤（マイグレーション・Enum・モデル）

### 目的

改善に必要なDB変更とEnum/モデルの基盤を整備する。

### 作業手順

#### 1-1. ConfirmationLevel Enum 作成

```
ファイル: app/Enums/AutoOrder/ConfirmationLevel.php
```

- STATUS1 = 'STATUS1' (候補表示のみ)
- STATUS2 = 'STATUS2' (承認まで自動実行)
- STATUS3 = 'STATUS3' (確定まで自動実行)
- `label()`, `color()` メソッド実装

#### 1-1b. IncomingScheduleStatus に PARTIAL_CANCELLED 追加

```
ファイル: app/Enums/AutoOrder/IncomingScheduleStatus.php
```

- `case PARTIAL_CANCELLED = 'PARTIAL_CANCELLED'` 追加
- `label()`: '一部入庫キャンセル'
- `color()`: 'danger'

#### 1-2. マイグレーション作成（5本）

```bash
php artisan make:migration add_auto_order_generation_time_to_wms_contractor_settings_table
php artisan make:migration add_confirmation_level_to_wms_contractor_warehouse_settings_table
php artisan make:migration add_cancel_columns_to_wms_order_incoming_schedules_table
php artisan make:migration create_wms_auto_order_execution_log_table
php artisan make:migration add_cancel_action_type_to_stock_transfer_queue_table
```

各マイグレーションの内容は仕様書セクション4.1〜4.5を厳密に実装。

**重要: `add_cancel_columns` マイグレーション（仕様書4.3）には以下を含む:**
- status ENUM に `PARTIAL_CANCELLED` を追加（MODIFY COLUMN）
- cancelled_at, cancelled_by, cancellation_reason カラム追加
- idx_ois_status_cancelled インデックス追加

#### 1-3. WmsAutoOrderExecutionLog モデル作成

```
ファイル: app/Models/WmsAutoOrderExecutionLog.php
```

- `WmsModel` を継承
- `$table = 'wms_auto_order_execution_log'`
- `$fillable`: contractor_id, executed_date, job_control_id, status, error_details, started_at, finished_at
- `$casts`: executed_date → date, started_at → datetime, finished_at → datetime
- リレーション: `contractor()`, `jobControl()`
- スコープ: `scopeToday()`, `scopeForContractor()`

#### 1-4. 既存モデルの $fillable / $casts 更新

- `WmsContractorSetting`: $fillable に `auto_order_generation_time` 追加
- `WmsContractorWarehouseSetting`: $fillable に `confirmation_level` 追加、$casts に ConfirmationLevel::class
- `WmsOrderIncomingSchedule`: $fillable に `cancelled_at`, `cancelled_by`, `cancellation_reason` 追加、$casts の status は IncomingScheduleStatus::class（PARTIAL_CANCELLED含む）

#### 1-5. マイグレーション実行

```bash
php artisan migrate
```

#### 1-6. DB確認

```sql
-- 追加カラム確認
DESCRIBE wms_contractor_settings;
DESCRIBE wms_contractor_warehouse_settings;
DESCRIBE wms_order_incoming_schedules;
DESCRIBE wms_auto_order_execution_log;
SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_NAME='stock_transfer_queue' AND COLUMN_NAME='action_type';
```

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Enums/AutoOrder/ConfirmationLevel.php` | 新規 |
| `app/Enums/AutoOrder/IncomingScheduleStatus.php` | 変更（PARTIAL_CANCELLED追加） |
| `app/Models/WmsAutoOrderExecutionLog.php` | 新規 |
| `database/migrations/xxxx_add_auto_order_generation_time_*.php` | 新規 |
| `database/migrations/xxxx_add_confirmation_level_*.php` | 新規 |
| `database/migrations/xxxx_add_cancel_columns_*.php` | 新規 |
| `database/migrations/xxxx_create_wms_auto_order_execution_log_*.php` | 新規 |
| `database/migrations/xxxx_add_cancel_action_type_*.php` | 新規 |
| `app/Models/WmsContractorSetting.php` | 変更 |
| `app/Models/WmsContractorWarehouseSetting.php` | 変更 |
| `app/Models/WmsOrderIncomingSchedule.php` | 変更 |

### 完了条件

- `php artisan migrate` が成功
- 全5テーブルの変更がDBで確認できる
- 既存の全テーブルのデータが破損していない

---

## P2: 仕入先別自動発注スケジューラー

### 目的

仕入先ごとに異なるタイミングで自動発注計算を実行できるようにする。

### 作業手順

#### 2-1. ProcessOrderCandidateGenerationJob コンストラクタ互換拡張

**重要: 既存パラメータを維持したまま新パラメータをオプショナルで追加する。**

```php
// 現在のコンストラクタ（変更しない）
public function __construct(
    public string $jobId,                    // 既存: WmsQueueProgress用ID
    public bool $deletePending = false,      // 既存: PENDING候補削除フラグ
    // ↓ 新規パラメータ（オプショナル）
    public ?int $contractorId = null,        // 仕入先指定（nullなら全仕入先）
    public ?int $executionLogId = null,      // 実行ログID
) {}
```

**既存のdispatch呼び出し（互換維持、変更不要）**:
```php
// ListWmsAutoOrderJobControls.php:159（既存）
ProcessOrderCandidateGenerationJob::dispatch($queueProgress->job_id, false);
// → $contractorId=null, $executionLogId=null で既存動作と同一
```

**新しいdispatch呼び出し（スケジューラー/手動再実行）**:
```php
ProcessOrderCandidateGenerationJob::dispatch(
    jobId: $queueProgress->job_id,
    deletePending: false,
    contractorId: $setting->contractor_id,
    executionLogId: $log->id,
);
```

**handle()内の追加ロジック**:
- `$this->contractorId` があれば → `calculate($snapshotJobId, $this->contractorId)` に渡す
- `$this->executionLogId` があれば → 完了/失敗時に execution_log を SUCCESS/FAILED に更新
- 既存の `$jobId` / `$deletePending` / `WmsQueueProgress` のロジックは一切変えない

#### 2-2. AutoOrderScheduledCommand 作成

```
ファイル: app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php
```

- 仕様書5.1のコードを実装
- execution_logによる当日実行済み判定
- PENDING/APPROVED候補チェック（発注候補+移動候補の両方）
- ログ記録 + Job dispatch（WmsQueueProgress作成 → Job dispatch）

#### 2-3. OrderCandidateCalculationService 修正

- `calculate(?int $snapshotJobId = null, ?int $contractorId = null)` にパラメータ追加
- contractorId指定時: その仕入先の商品のみ計算
- contractorId=null時: 既存の全仕入先一括計算（後方互換）

#### 2-4. Scheduler登録

```
ファイル: routes/console.php（または app/Console/Kernel.php）
```

```php
Schedule::command('wms:auto-order-scheduled')->everyFiveMinutes()->onOneServer();
```

#### 2-5. 仕入先設定画面に auto_order_generation_time フィールド追加

- WmsContractorSetting のFilamentリソースにTimePickerまたはTextInput(HH:MM)を追加

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php` | 新規 |
| `app/Services/AutoOrder/OrderCandidateCalculationService.php` | 変更 |
| `app/Jobs/ProcessOrderCandidateGenerationJob.php` | 変更 |
| `routes/console.php` | 変更 |
| 仕入先設定のFilamentリソース | 変更 |

### 完了条件

- `php artisan wms:auto-order-scheduled` 実行で仕入先別に候補生成
- execution_log にRUNNING→SUCCESS/FAILEDが記録される
- auto_order_generation_time=NULLの仕入先はスキップ
- 既存CLI `wms:auto-order-calculate` が従来通り動作

---

## P3: 手動強制発注・排他制御変更

### 目的

PENDING/APPROVED候補がある仕入先はブロックしつつ、別仕入先の発注は許可する。

### 作業手順

#### 3-1. OrderCandidateCalculationService 排他制御変更

**現在のコード（L88-96）は発注候補のAPPROVEDのみチェック。移動候補はノーチェック。**

**パターンA: 仕入先指定あり（スケジューラー/手動強制）**:
- `WmsOrderCandidate`: PENDING + APPROVED を `contractor_id` で絞込チェック
- `WmsStockTransferCandidate`: PENDING + APPROVED を `contractor_id` で絞込チェック
- `contractor_id=NULL` の移動候補は WHERE句で自然除外（対象外）
- どちらかにヒット → ブロック

**パターンB: 仕入先指定なし（既存CLI互換）**:
- `WmsOrderCandidate`: APPROVED を全件チェック（既存ロジック維持）
- `WmsStockTransferCandidate`: APPROVED を全件チェック（**新規追加**）
- `contractor_id=NULL` 含む全件が対象
- どちらかにヒット → ブロック

#### 3-2. ジョブ管理画面に「仕入先別強制生成」アクション追加

```
ファイル: app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php
```

- ツールバーアクションに仕入先選択Select + 「強制発注生成」ボタン
- 選択した仕入先にPENDING候補がある場合: 「削除して再生成」確認ダイアログ
- confirmation後にJob dispatch

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Services/AutoOrder/OrderCandidateCalculationService.php` | 変更 |
| `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` | 変更 |

### 完了条件

- 仕入先AにPENDING候補あり → 仕入先Aの計算はブロック
- 仕入先AにPENDING候補あり → 仕入先Bの計算は実行可能
- 強制生成ボタンでPENDING削除→再生成が動作
- 既存CLIの排他制御が維持

---

## P4: 確定レベル制御

### 目的

候補生成後に確定レベル（STATUS1/2/3）に応じて自動承認・自動確定を行う。

### 作業手順

#### 4-1. ProcessOrderCandidateGenerationJob に自動レベル適用ロジック追加

- 候補INSERT直後に同一トランザクション内で実行
- wms_contractor_warehouse_settings からconfirmation_levelを一括取得
- STATUS2: 自動APPROVED
- STATUS3: 自動APPROVED → 自動CONFIRMED/EXECUTED（入荷予定作成含む）
- レコードなし: STATUS1（候補表示のみ）として扱う

#### 4-2. 仕入先×倉庫設定画面に confirmation_level フィールド追加

- WmsContractorWarehouseSetting のFilamentリソースにSelectフィールド追加
- ConfirmationLevel Enumの選択肢表示

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Jobs/ProcessOrderCandidateGenerationJob.php` | 変更 |
| 仕入先×倉庫設定のFilamentリソース | 変更 |

### 完了条件

- STATUS1: 候補がPENDINGのまま
- STATUS2: 候補がAPPROVEDで生成
- STATUS3: 候補がCONFIRMED/EXECUTEDで生成、入荷予定も作成
- confirmation_level未設定（レコードなし）: STATUS1として動作（既存互換）

---

## P5: キャンセル機能

### 目的

確定済みの発注・移動をキャンセルし、入荷予定を取消する。

### 作業手順

#### 5-1. OrderCancellationService 作成

```
ファイル: app/Services/AutoOrder/OrderCancellationService.php
```

- 仕様書5.4のコードを実装
- キャンセル可能チェック（PENDING/PARTIALのみ）
- **ステータス分岐**:
  - PENDING → **CANCELLED**（全キャンセル、received_quantity=0）
  - PARTIAL → **PARTIAL_CANCELLED**（一部入庫後キャンセル、received_quantity維持）
- received_quantity は変更しない（入庫済み数量は記録として維持）
- トランザクション内で: 入庫予定ステータス変更 → 候補APPROVED戻し → stock_transfer_queue CANCEL
- demand_breakdown対応: order_candidate_idで関連全入庫予定を一括キャンセル

#### 5-2. 入庫予定画面にキャンセルアクション追加

- 入庫予定一覧のレコードアクションに「キャンセル」ボタン
- キャンセル理由入力モーダル（TextArea）
- PENDING/PARTIALの場合のみ表示

#### 5-3. stock_transfer_queue CANCEL INSERTの必須カラム対応

- client_id: 移動候補のcontractor_idから取得
- from_warehouse_code / to_warehouse_code: 移動候補の倉庫から取得
- items: 空JSON `[]`（キャンセルのため）
- request_id: `"transfer-cancel-{schedule_id}-{timestamp}"`

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Services/AutoOrder/OrderCancellationService.php` | 新規 |
| 入庫予定のFilamentリソース | 変更 |

### 完了条件

- PENDING入庫予定: **CANCELLED** に変更、候補APPROVEDに戻る
- PARTIAL入庫予定: **PARTIAL_CANCELLED** に変更、received_quantityは維持、候補APPROVEDに戻る
- CONFIRMED/TRANSMITTED: キャンセル不可エラー
- TRANSFER入庫予定: stock_transfer_queue CANCELレコード作成
- キャンセル理由・日時・ユーザーが記録される
- PARTIAL_CANCELLED後の再スナップショットでincoming_quantityから除外される

---

## P6: 実行ログ画面

### 目的

自動発注の実行状況確認・エラー確認・手動再実行を行う管理画面。

### 作業手順

#### 6-1. Filamentリソース作成

```
app/Filament/Resources/WmsAutoOrderExecutionLogs/
├── WmsAutoOrderExecutionLogResource.php
├── Pages/
│   └── ListWmsAutoOrderExecutionLogs.php
└── Tables/
    └── WmsAutoOrderExecutionLogsTable.php
```

#### 6-2. テーブルカラム実装

仕様書5.5のカラム定義に従って実装:
- executed_date, contractor.name, status(バッジ), started_at, finished_at, error_details, job_control_id

#### 6-3. フィルタ実装

- 実行日（DatePicker）
- 仕入先（Select）
- ステータス（Select）

#### 6-4. レコードアクション実装

1. エラー詳細表示（FAILEDのみ）: モーダルでerror_details全文表示
2. 手動再実行（FAILEDのみ）: 確認ダイアログ → 新しいログ作成 → Job dispatch

#### 6-5. EMenu登録

- `EMenu.php` に `WMS_AUTO_ORDER_EXECUTION_LOG` 追加
- category: ORDERING, label: '自動発注実行ログ', icon: heroicon-o-clipboard-document-check

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Filament/Resources/WmsAutoOrderExecutionLogs/` | 新規（3ファイル） |
| `app/Enums/EMenu.php` | 変更 |

### 完了条件

- ログ一覧が当日分を新しい順に表示
- FAILEDレコードのエラー詳細モーダルが動作
- 手動再実行で新しいログが作成されJobがdispatch
- SUCCESS/RUNNINGレコードには再実行ボタンが非表示
- フィルタが正常動作

---

## P7: テスト・回帰検証

### 目的

全42テストシナリオ + ページアクセシビリティ64ページを検証し、既存機能の回帰テストを実施する。

### 作業手順

#### 7-1. 単体テスト作成

```
tests/Unit/Services/AutoOrder/OrderCancellationServiceTest.php
tests/Unit/Services/AutoOrder/AutoOrderScheduledCommandTest.php
tests/Unit/Enums/ConfirmationLevelTest.php
```

#### 7-2. ページアクセシビリティテスト作成（Headless）

```
tests/Feature/Filament/AllPagesAccessibilityTest.php
```

**重要な制約**:
- **DB**: `sakemaru_hana_prod`（localhost / root / パスワードなし）
- **migrate:fresh / migrate:refresh は絶対に使用しない**
- テスト用ユーザーを `setUp()` で新規作成、`tearDown()` で削除

**テスト方式**: Filament 4標準の Livewire headless テスト
```php
Livewire::actingAs($this->user)->test(ListPage::class)->assertSuccessful();
```

**対象**: 仕様書セクション6.6の全64ページ（TP01〜TP64）
- リソース一覧ページ: 56ページ
- カスタムページ: 7ページ
- 新規画面（実行ログ）: 1ページ

**テスト用ユーザー作成**:
```php
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

#### 7-3. テスト実行

```bash
composer test
php artisan test --filter=OrderCancellation
php artisan test --filter=AutoOrderScheduled
php artisan test --filter=AllPagesAccessibility
```

#### 7-4. 回帰テスト

- T29: `php artisan wms:auto-order-calculate` が従来通り動作
- T30-T33: 既存フローの動作確認

#### 7-5. ビジネスロジックテストシナリオ一覧

仕様書セクション6の全42シナリオを実施。
特に重要:
- T07: PARTIAL → PARTIAL_CANCELLED（received_quantity維持確認）
- T15a: 手動PENDING候補あり→自動発注スキップ
- T16c: contractor_id=NULLの移動候補
- T16d: 移動候補APPROVED→CLI一括計算ブロック（新規追加分）
- T16e: 移動候補PENDING→仕入先別計算ブロック（新規追加分）
- T22-T23: confirmation_level未設定時のデフォルト動作
- T23a: PARTIAL_CANCELLED後の再計算でincoming_quantity除外
- T23b: PARTIAL_CANCELLEDのreceived_quantity維持

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `tests/Unit/Services/AutoOrder/OrderCancellationServiceTest.php` | 新規 |
| `tests/Unit/Services/AutoOrder/AutoOrderScheduledCommandTest.php` | 新規 |
| `tests/Unit/Enums/ConfirmationLevelTest.php` | 新規 |
| `tests/Feature/Filament/AllPagesAccessibilityTest.php` | 新規 |

### 完了条件

- `composer test` が全テスト通過
- 既存CLI `wms:auto-order-calculate` が正常動作
- 全42ビジネスロジックシナリオの確認完了
- 全64ページのアクセシビリティテスト通過（TP01〜TP64）

---

## 制約（厳守）

1. **DB破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh`, `db:wipe` は絶対に実行しない
2. **FK禁止**: 外部キー制約を使用しない
3. **sakemaru接続**: 全WMSモデルは `WmsModel` 継承
4. **Filament 4**: 正しいインポートパス使用（`Filament\Schemas\Components\Section` 等）
5. **既存CLI互換**: `wms:auto-order-calculate` の動作を壊さない
6. **楽観ロック維持**: `lock_version` による競合検知を維持
7. **計算ロジック変更禁止**: OrderCandidateCalculationService の計算ロジック自体は変更しない（排他制御とフィルタリングのみ変更）
8. **stock_transfer_queue**: NOT NULLカラム（client_id, items, from/to_warehouse_code等）を必ず設定

## 全体完了条件

1. 全7 Phaseが完了
2. `composer test` 通過
3. `php artisan migrate:status` で全マイグレーションがRan
4. 既存の4画面（ジョブ管理・移動候補・発注候補・確定待ち）が正常動作
5. 新画面（実行ログ）が正常動作
6. 仕様書の全42ビジネスロジックシナリオを確認
7. 全64ページのアクセシビリティテスト通過（TP01〜TP64）
