# Work Plan: incoming-picker-tracking

- **ID**: incoming-picker-tracking
- **作成日**: 2026-04-04
- **最終更新**: 2026-04-04
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260403/20260404-001451-incoming-picker-tracking/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260404-001451-incoming-picker-tracking-boot.md）
2. 20260404-001451-incoming-picker-tracking-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

`wms_order_incoming_schedules` に `confirmed_picker_id` カラムを追加し、API（Android）経由の入荷確定時にピッカーIDを記録する。Web UI では従来通り `confirmed_by` に User ID を記録。入荷予定・入荷完了・入荷送信済みのリスト画面にピッカーカラムを追加。

## 重要な設計制約

- **FK禁止**: `confirmed_picker_id` にFKを張らない
- **migrate:fresh/refresh 禁止**: カラム追加のみ
- **既存データ不変**: 既存レコードの `confirmed_by` は変更しない（移行しない）
- **API後方互換**: APIのリクエスト/レスポンス形式は変更しない
- **confirmed_by の扱い**: API経由の場合は NULL にする

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_add_confirmed_picker_id_to_wms_order_incoming_schedules_table.php`

### 既存変更
- `app/Models/WmsOrderIncomingSchedule.php` — fillable, リレーション追加, confirm()変更
- `app/Services/AutoOrder/IncomingConfirmationService.php` — pickerId パラメータ追加
- `app/Http/Controllers/Api/IncomingController.php` — completeWork で pickerId を渡す
- `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` — ピッカーカラム追加
- `app/Filament/Resources/WmsOrderIncomingSchedules/Pages/ListWmsOrderIncomingSchedules.php` — eager load追加
- `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php` — ピッカーカラム・モーダル追加
- `app/Filament/Resources/WmsIncomingCompleted/Pages/ListWmsIncomingCompleted.php` — eager load追加
- `app/Filament/Resources/WmsIncomingTransmitted/Pages/ListWmsIncomingTransmitted.php` — eager load追加
- 入荷送信済みテーブル — ピッカーカラム追加

### 参照のみ（変更禁止）
- `app/Models/WmsPicker.php` — リレーション先
- `app/Models/WmsPickingTask.php` — picker_id パターンの参考
- `app/Models/WmsIncomingWorkItem.php` — picker_id の流れの参考

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: マイグレーション＋モデル | 完了 | 2026-04-04 | migration実行OK、fillable/リレーション/confirm()変更 |
| P2: サービス＋API変更 | 完了 | 2026-04-04 | 3メソッド+Controller変更、php -l OK |
| P3: UI（入荷予定・入荷完了・入荷送信済み） | 完了 | 2026-04-04 | 3ページにピッカーカラム追加、モーダルにも追加 |
| P4: テスト・検証 | 完了 | 2026-04-04 | テスト173パス(6失敗はJxServer無関係)、Pint OK |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### マイグレーション（P1完了後に記入）
- マイグレーションファイル名: 2026_04_04_002118_add_confirmed_picker_id_to_wms_order_incoming_schedules_table.php
- 実行結果: 成功 (64.20ms)

### サービス変更箇所（P2完了後に記入）
- IncomingConfirmationService 変更メソッド数: 3 (confirmIncoming, recordPartialIncoming, confirmMultiple)
- IncomingController 変更箇所数: 2 (completeWork内のconfirmIncoming/recordPartialIncoming呼び出し)

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: main

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: マイグレーション＋モデル
- 完了日: 2026-04-04
- 実績:
  - マイグレーション作成・実行: `confirmed_picker_id` BIGINT nullable added
  - モデル: fillableに追加、`confirmedByPicker()` リレーション追加、`confirm()` にpickerIdパラメータ追加

### P2: サービス＋API変更
- 完了日: 2026-04-04
- 実績:
  - IncomingConfirmationService: 3メソッドにpickerIdパラメータ追加、confirmed_by/confirmed_picker_idの排他セット
  - IncomingController: completeWork内の2箇所にpickerId名前付き引数追加

### P3: UI（入荷予定・入荷完了・入荷送信済み）
- 完了日: 2026-04-04
- 実績:
  - 入荷予定: eager load追加、テーブルにピッカーカラム追加
  - 入荷完了: eager load追加、テーブルにピッカーカラム追加、詳細モーダルにピッカー名追加
  - 入荷送信済み: Resource eager load追加、テーブルに担当者・ピッカー両カラム追加

### P4: テスト・検証
- 完了日: 2026-04-04
- 実績:
  - php artisan test: 173パス（6失敗はJxServer関連で無関係）
  - Pint: 変更ファイル全てOK
