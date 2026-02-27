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
| P1: DB基盤（マイグレーション・Enum・モデル） | なし | 未着手 | - | PARTIAL_CANCELLED ENUM追加含む |
| P2: 仕入先別自動発注スケジューラー | P1 | 未着手 | - | Job互換拡張含む |
| P3: 手動強制発注・排他制御変更 | P2 | 未着手 | - | 発注+移動候補2パターン排他 |
| P4: 確定レベル制御 | P3 | 未着手 | - | |
| P5: キャンセル機能 | P1 | 未着手 | - | CANCELLED/PARTIAL_CANCELLED分岐 |
| P6: 実行ログ画面 | P2 | 未着手 | - | |
| P7: テスト・回帰検証 | P1-P6全完了 | 未着手 | - | 42シナリオ + 64ページアクセシビリティ |

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
- (未実施)

### Git ブランチ
- 作業ブランチ: (未作成)
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: DB基盤
- 完了日: -
- 実績:
  - (完了後に記入)

### P2: 仕入先別自動発注スケジューラー
- 完了日: -
- 実績:
  - (完了後に記入)

### P3: 手動強制発注・排他制御変更
- 完了日: -
- 実績:
  - (完了後に記入)

### P4: 確定レベル制御
- 完了日: -
- 実績:
  - (完了後に記入)

### P5: キャンセル機能
- 完了日: -
- 実績:
  - (完了後に記入)

### P6: 実行ログ画面
- 完了日: -
- 実績:
  - (完了後に記入)

### P7: テスト・回帰検証
- 完了日: -
- 実績:
  - (完了後に記入)
