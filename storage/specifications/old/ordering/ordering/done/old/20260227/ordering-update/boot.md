# Work Plan: ordering-update

- **ID**: ordering-update
- **作成日**: 2026-02-27
- **最終更新**: 2026-02-27
- **ステータス**: 進行中
- **ディレクトリ**: storage/specifications/ordering/ordering-update/
- **仕様書**: storage/specifications/ordering/20260227/20260227-ordering-update-specification.md

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. 仕様書を読む（20260227-ordering-update-specification.md）
3. plan.md を読む（作業計画の全体像）
4. 下記「進捗」テーブルで現在のPhaseを確認
5. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
6. 「作業中コンテキスト」セクションで途中データを確認
7. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

自動発注フローの5つの改善を実装する:
1. 仕入先別自動発注タイミング制御
2. 手動強制発注・移動（フロー途中でも可能に）
3. 確定レベル制御（STATUS1/2/3）
4. 発注確定後のキャンセル機能
5. 自動発注実行ログ画面

## 重要な設計制約

- **DB破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh`, `db:wipe` 絶対禁止
- **外部キー(FK)禁止**: 全リレーションはアプリケーション層で管理
- **sakemaru接続**: 全WMSモデルは `WmsModel` を継承（`$connection = 'sakemaru'`）
- **Filament 4**: `Filament\Schemas\Components\Section` 等のv4インポートパスを使用
- **楽観ロック**: `lock_version` による競合検知を維持
- **stock_transfer_queue**: 基幹システム連携テーブル。CANCEL追加は基幹側対応も必要
- **wms_stock_transfer_candidates.contractor_id**: NULLABLE（排他制御でNULL考慮必須）
- **既存CLI互換**: `wms:auto-order-calculate` は従来通り動作すること
- **Job互換性**: `ProcessOrderCandidateGenerationJob` の既存コンストラクタ `($jobId, $deletePending)` を維持し、新パラメータ `($contractorId, $executionLogId)` はオプショナルで追加
- **PARTIAL_CANCELLED**: PARTIAL入庫予定のキャンセルは CANCELLED ではなく PARTIAL_CANCELLED に遷移。received_quantityは変更しない
- **排他制御2パターン**: 仕入先指定あり（発注+移動のPENDING/APPROVED仕入先単位チェック）と指定なし（発注+移動のAPPROVED全件チェック）を分岐

## 対象ファイル

### 新規作成
- `app/Enums/AutoOrder/ConfirmationLevel.php` - 確定レベルEnum（新規）

### 既存Enum変更
- `app/Enums/AutoOrder/IncomingScheduleStatus.php` - PARTIAL_CANCELLED追加
- `app/Models/WmsAutoOrderExecutionLog.php` - 実行ログモデル
- `app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php` - スケジューラーコマンド
- `app/Services/AutoOrder/OrderCancellationService.php` - キャンセルサービス
- `app/Filament/Resources/WmsAutoOrderExecutionLogs/` - 実行ログ画面
- マイグレーション5本

### 既存変更
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` - 仕入先指定対応・排他制御変更
- `app/Jobs/ProcessOrderCandidateGenerationJob.php` - 仕入先指定・確定レベル適用
- `app/Models/WmsContractorSetting.php` - $fillable追加
- `app/Models/WmsContractorWarehouseSetting.php` - $casts, $fillable追加
- `app/Models/WmsOrderIncomingSchedule.php` - $fillable追加
- `app/Enums/EMenu.php` - 実行ログメニュー追加
- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` - 強制生成ボタン
- `app/Filament/Resources/WmsOrderIncomingSchedules/` - キャンセルアクション
- `routes/console.php` or Scheduler - スケジューラー登録

### 参照のみ（変更禁止）
- `app/Services/AutoOrder/OrderExecutionService.php` - 確定ロジック参照
- `app/Services/AutoOrder/TransferCandidateExecutionService.php` - 移動確定ロジック参照
- `app/Services/AutoOrder/StockSnapshotService.php` - スナップショットロジック参照
- `storage/specifications/ordering/20260227/20260227-ordering-update-specification.md` - 仕様書

## テストデータ

```bash
php artisan wms:generate-test-data    # WMS全体のテストデータ生成
composer test                          # テスト実行
php artisan test --filter=AllPagesAccessibility  # ページアクセシビリティテスト
```

### ページアクセシビリティテスト（Headless）
- **DB**: sakemaru_hana_prod（localhost / root / パスワードなし）※refresh/fresh禁止
- **方式**: Livewire::actingAs() による Filament 4 headless テスト
- **テスト用ユーザー**: setUp() で新規作成 → tearDown() で削除
- **対象**: 全64ページ（リソース一覧56 + カスタムページ7 + 新規画面1）
- **テストファイル**: `tests/Feature/Filament/AllPagesAccessibilityTest.php`

---

## 進捗

| Phase | 依存 | 状態 | 更新日 | 備考 |
|-------|------|------|--------|------|
| P1: DB基盤（マイグレーション・Enum・モデル） | なし | **完了** | 2026-02-27 | PARTIAL_CANCELLED ENUM追加含む |
| P2: 仕入先別自動発注スケジューラー | P1 | **完了** | 2026-02-27 | Job互換拡張含む |
| P3: 手動強制発注・排他制御変更 | P2 | **完了** | 2026-02-27 | 発注+移動候補2パターン排他 |
| P4: 確定レベル制御 | P3 | **完了** | 2026-02-27 | |
| P5: キャンセル機能 | P1 | **完了** | 2026-02-27 | CANCELLED/PARTIAL_CANCELLED分岐 |
| P6: 実行ログ画面 | P2 | **完了** | 2026-02-27 | |
| P7: テスト・回帰検証 | P1-P6全完了 | **完了** | 2026-02-27 | 42シナリオ + 64ページアクセシビリティ（61pass/3skip） |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### DB検証結果（2026-02-27 確認済み）
- wms_contractor_settings: 1052件、ほぼ全件 is_auto_transmission=1
- wms_contractor_warehouse_settings: 0件（空テーブル）
- wms_order_candidates: 0件
- wms_order_incoming_schedules: 0件
- wms_stock_transfer_candidates.contractor_id: NULLABLE
- wms_order_incoming_schedules.status: enum(...,'CANCELLED') ※PARTIAL_CANCELLED未定義→追加必要
- stock_transfer_queue.action_type: enum('CREATE','UPDATE','DELIVER') ※CANCELなし→追加必要
- wms_auto_order_job_controls.settlement_status: varchar(20) ※ENUMではない

### マイグレーション実行結果（P1完了後に記入）
- 5本全て成功 (2026-02-27)
- wms_contractor_settings: auto_order_generation_time VARCHAR(5) NULL 追加
- wms_contractor_warehouse_settings: confirmation_level ENUM('STATUS1','STATUS2','STATUS3') NOT NULL DEFAULT 'STATUS1' 追加 + INDEX
- wms_order_incoming_schedules: status ENUMにPARTIAL_CANCELLED追加 + cancelled_at/cancelled_by/cancellation_reason追加 + INDEX
- wms_auto_order_execution_log: 新規テーブル作成
- stock_transfer_queue: action_type ENUMにCANCEL追加

### Git ブランチ
- 作業ブランチ: feature/ordering-update
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: DB基盤
- 完了日: 2026-02-27
- 実績:
  - ConfirmationLevel Enum作成 (STATUS1/STATUS2/STATUS3)
  - IncomingScheduleStatus に PARTIAL_CANCELLED 追加
  - マイグレーション5本作成・実行成功
  - WmsAutoOrderExecutionLog モデル作成
  - WmsContractorSetting: $fillable に auto_order_generation_time 追加
  - WmsContractorWarehouseSetting: $fillable に confirmation_level, $casts に ConfirmationLevel::class 追加
  - WmsOrderIncomingSchedule: $fillable に cancelled_at/cancelled_by/cancellation_reason, $casts に cancelled_at 追加

### P2: 仕入先別自動発注スケジューラー
- 完了日: 2026-02-27
- 実績:
  - ProcessOrderCandidateGenerationJob: contractorId/executionLogIdパラメータ追加（既存互換維持）
  - AutoOrderScheduledCommand作成（wms:auto-order-scheduled）
  - OrderCandidateCalculationService: calculate()にcontractorIdパラメータ追加、仕入先絞込対応
  - Scheduler登録（5分間隔、onOneServer）
  - 仕入先設定テーブル/フォームにauto_order_generation_timeフィールド追加
  - Job完了/失敗時のexecution_log更新ロジック追加

### P3: 手動強制発注・排他制御変更
- 完了日: 2026-02-27
- 実績:
  - 排他制御2パターン（パターンA: 仕入先指定あり=PENDING+APPROVED仕入先単位、パターンB: 指定なし=APPROVED全件）実装済み（P2で実施）
  - 移動候補のAPPROVEDチェックも追加（パターンB）
  - ジョブ管理画面に「仕入先別強制生成」ヘッダーアクション追加
  - PENDING候補削除→再生成フロー実装
  - APPROVED候補ありの場合はブロック＋通知

### P4: 確定レベル制御
- 完了日: 2026-02-27
- 実績:
  - ProcessOrderCandidateGenerationJobにapplyConfirmationLevels()メソッド追加
  - STATUS1: そのまま（候補表示のみ）
  - STATUS2: 自動APPROVED
  - STATUS3: 自動APPROVED→自動CONFIRMED/EXECUTED（入荷予定作成含む）
  - 失敗時はAPPROVEDで停止、ログ記録
  - 仕入先×倉庫設定テーブル/フォームにconfirmation_levelフィールド追加

### P5: キャンセル機能
- 完了日: 2026-02-27
- 実績:
  - OrderCancellationService作成
  - PENDING→CANCELLED、PARTIAL→PARTIAL_CANCELLED分岐
  - received_quantityは変更しない
  - 発注候補/移動候補のステータスAPPROVEDへロールバック
  - stock_transfer_queue CANCELレコード作成
  - 入庫予定テーブルのキャンセルアクション（単体・一括）をOrderCancellationService使用に変更
  - PARTIAL時のモーダル説明文追加

### P6: 実行ログ画面
- 完了日: 2026-02-27
- 実績:
  - WmsAutoOrderExecutionLogResource作成（3ファイル）
  - テーブルカラム: executed_date, contractor.name, status(バッジ), started_at, finished_at, error_details, job_control_id
  - フィルタ: 実行日, 仕入先, ステータス
  - レコードアクション: エラー詳細(FAILEDのみ)、手動再実行(FAILEDのみ)
  - EMenu登録: WMS_AUTO_ORDER_EXECUTION_LOG（発注処理カテゴリ、sort=1）

### P7: テスト・回帰検証
- 完了日: 2026-02-27
- 実績:
  - composer test: 84 passed（10 failed, 11 risky, 34 skipped は全て既存の問題。変更箇所に起因するFailなし）
  - php artisan migrate:status: 5本全てRan
  - 既存CLI wms:auto-order-calculate: ヘルプ出力正常
  - 新規CLI wms:auto-order-scheduled: 実行正常（auto_order_generation_time未設定のため対象0件）
  - Filamentルート: admin/wms-auto-order-execution-logs が正常登録
  - Pint: 新規ファイルのフォーマット修正済み
  - AllPagesAccessibilityテスト: 64ページ全カバー（61 passed, 3 skipped）
    - 3 skipped は全て既存の問題（TP10: WmsUserモデル未存在、TP62/TP63: testing環境でcanAccess=false）
    - 新規画面TP64（ListWmsAutoOrderExecutionLogs）: PASS
