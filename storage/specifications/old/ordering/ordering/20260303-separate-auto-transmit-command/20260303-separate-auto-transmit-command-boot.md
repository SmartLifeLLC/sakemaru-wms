# Work Plan: separate-auto-transmit-command

- **ID**: separate-auto-transmit-command
- **作成日**: 2026-03-03
- **最終更新**: 2026-03-03
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/ordering/20260303-separate-auto-transmit-command/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260303-separate-auto-transmit-command-boot.md）
2. 20260303-separate-auto-transmit-command-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

候補生成ジョブ（ProcessOrderCandidateGenerationJob）から自動送信呼び出しを分離し、`transmission_time` に基づく独立した送信コマンド `wms:auto-order-transmit` を新設する。

## 重要な設計制約

- FK禁止（プロジェクトルール）
- `migrate:fresh` / `migrate:refresh` / `migrate:reset` / `db:wipe` 禁止
- DB変更なし（新規テーブル・カラム不要）
- `ProcessAutoSendJob` は変更しない（既存パイプラインを流用）

## 対象ファイル

### 新規作成
- `app/Console/Commands/AutoOrder/AutoOrderTransmitCommand.php` — 自動送信スケジューラコマンド

### 既存変更
- `app/Jobs/ProcessOrderCandidateGenerationJob.php` — `dispatchAutoSendIfNeeded()` 削除
- `routes/console.php` — `wms:auto-order-transmit` スケジューラ登録追加

### 参照のみ（変更禁止）
- `app/Jobs/ProcessAutoSendJob.php` — 既存の自動送信ジョブ
- `app/Models/WmsContractorSetting.php` — `transmission_time`, `is_auto_transmission` 参照
- `app/Models/WmsAutoOrderExecutionLog.php` — 当日実行済み判定
- `app/Models/WmsQueueProgress.php` — ジョブ進捗管理
- `app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php` — 既存スケジューラ（参考パターン）

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: ProcessOrderCandidateGenerationJob修正 | 完了 | 2026-03-03 | dispatchAutoSendIfNeeded() + import削除、Pint通過 |
| P2: AutoOrderTransmitCommand新規作成 | 完了 | 2026-03-03 | 新コマンド作成、Pint通過 |
| P3: スケジューラ登録 | 完了 | 2026-03-03 | routes/console.php に追加、一覧テーブル更新 |
| P4: 動作確認 | 完了 | 2026-03-03 | 4仕入先検出・正常終了・schedule:list確認 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 削除対象コード（P1実施前に記録）
- `dispatchAutoSendIfNeeded()` メソッド: 294〜319行目
- `handle()` 内の呼び出し: 186〜188行目
- 削除対象import: `use App\Jobs\ProcessAutoSendJob` (8行目付近、他で使用なし)

### Git ブランチ
- 作業ブランチ: feature/ordering-update
- ベースブランチ: main

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: ProcessOrderCandidateGenerationJob修正
- 完了日: 2026-03-03
- 実績:
  - `dispatchAutoSendIfNeeded()` メソッド全体（旧294〜319行）を削除
  - `handle()` 内の呼び出し（旧185〜188行）を削除
  - `use App\Models\WmsContractorSetting` importを削除（他で未使用）
  - Pint通過

### P2: AutoOrderTransmitCommand新規作成
- 完了日: 2026-03-03
- 成果物: `app/Console/Commands/AutoOrder/AutoOrderTransmitCommand.php`
- 実績:
  - `wms:auto-order-transmit` コマンド作成
  - 対象抽出: transmission_contractor_id IS NULL + is_auto_transmission + transmission_time + 曜日
  - スキップ: 候補生成未完了 / 送信済み / 候補なし
  - Pint通過

### P3: スケジューラ登録
- 完了日: 2026-03-03
- 実績:
  - `routes/console.php` にスケジュール登録追加（5分毎、onOneServer、withoutOverlapping）
  - スケジューラ一覧テーブルに `wms:auto-order-transmit` エントリ追加
  - Pint通過

### P4: 動作確認
- 完了日: 2026-03-03
- 実績:
  - `php artisan wms:auto-order-transmit` → 4仕入先検出、全て送信済みでスキップ、正常終了
  - `php artisan schedule:list` → `wms:auto-order-transmit` 5分毎で表示確認
