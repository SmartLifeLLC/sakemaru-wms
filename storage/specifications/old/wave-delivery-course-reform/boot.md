# Work Plan: wave-delivery-course-reform

- **ID**: wave-delivery-course-reform
- **作成日**: 2026-02-21
- **最終更新**: 2026-02-21
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/wave-delivery-course-reform/
- **仕様書**: storage/specifications/outbound/20260219-wms-wave-delivery-course-reform.md

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

配送コース・波動生成の大規模改修。主な変更:
1. `wms_wave_settings.warehouse_id` を削除し、配送コース基準に統一
2. 出荷倉庫不一致時の在庫移動伝票自動生成
3. 得意先配送コース時間切替機能の新規追加
4. 横持ち出荷の最終配送先を実倉庫ベースに修正
5. 仮想倉庫の同一実倉庫判定によるピッキングスキップ

## 重要な設計制約

- **FK禁止**: 全リレーションはアプリケーションレベルで管理
- **migrate:fresh/refresh/reset 禁止**: 本番データが削除されるため
- **wms_ 以外のテーブルのスキーマ変更禁止**: ALTER TABLE は wms_ テーブルのみ
- **基幹テーブルへのランタイムデータ更新は許可**: `earnings.picking_status` 等
- **原子的UPDATE方式**: SELECT→判定→UPDATE は禁止。DB側条件で排他制御
- **べき等性必須**: request_id で全在庫移動伝票の二重生成を防止
- **仮想倉庫の判定**: `COALESCE(stock_warehouse_id, id)` で実倉庫に解決

## 対象ファイル

### 新規作成
- `app/Services/WarehouseResolver.php` — 実倉庫解決ユーティリティ (F-0)
- `database/migrations/xxxx_create_wms_buyer_delivery_course_switch_settings_table.php` (F-1)
- `database/migrations/2026_02_21_000001_remove_warehouse_id_from_wms_wave_settings.php` (F-1b)
- `app/Models/WmsBuyerDeliveryCourseSwitchSetting.php` (F-2)
- `app/Console/Commands/SwitchDeliveryCourseCommand.php` (F-3)
- `app/Services/WarehouseMismatchTransferService.php` (F-4)
- `app/Filament/Resources/WmsBuyerDeliveryCourseSwitchSettings/` (F-5)

### 既存変更
- `app/Console/Commands/GenerateWavesCommand.php` (M-1)
- `app/Services/WaveService.php` (M-2)
- `app/Models/WaveSetting.php` (M-2b)
- `app/Models/WmsPickingTask.php` (M-3)
- `app/Services/Shortage/StockTransferQueueService.php` (M-4)
- `routes/console.php` (M-5)
- `app/Services/DeliveryCourseChangeService.php` (M-6)
- `app/Filament/Resources/Waves/Pages/ListWaves.php` (M-7)
- `app/Filament/Resources/WaveSettings/Schemas/WaveSettingForm.php` (M-8)
- `app/Filament/Resources/WaveSettings/Tables/WaveSettingsTable.php` (M-9)
- `app/Console/Commands/TestData/GenerateWaveSettingsCommand.php` (M-10)
- `database/seeders/WaveSettingSeeder.php` (M-11)
- `app/Console/Commands/TestData/GeneratePickerWaveCommand.php` (M-12)

### 参照のみ（変更禁止）
- `earnings` テーブル（スキーマ変更禁止、ランタイム更新は可）
- `buyer_details` テーブル（スキーマ変更禁止、ランタイム更新は可）
- `delivery_courses` テーブル
- `warehouses` テーブル
- `stock_transfer_queue` テーブル（データ挿入は可）

## テストデータ

```bash
php artisan wms:generate-test-data
php artisan wms:generate-waves
```

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: WarehouseResolver ユーティリティ作成 | 完了 | 2026-02-21 | F-0 |
| P1: wms_wave_settings.warehouse_id 削除（モデル・サービス改修） | 完了 | 2026-02-21 | M-2b, M-2, M-6, M-1, M-7, M-8, M-9, M-10, M-11, M-12 |
| P2: StockTransferQueueService 実倉庫ベース修正 | 完了 | 2026-02-21 | M-4 |
| P3: DB変更とモデル作成（配送コース切替） | 完了 | 2026-02-21 | F-1, F-2 |
| P4: 出荷倉庫不一致対応 | 完了 | 2026-02-21 | F-4, M-3 |
| P5: 得意先配送コース時間切替 | 完了 | 2026-02-21 | F-3, F-5, M-5 |
| P6: マイグレーション実行・テスト・検証 | 完了 | 2026-02-21 | F-1b, テスト |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### warehouse_id 参照箇所（P1作業用）
- `GenerateWavesCommand.php`: L74, L82, L98, L165, L226, L241, L304, L404, L447, L468, L684
- `WaveService.php`: L26, L43, L51, L59, L71, L104, L131, L136
- `WaveSetting.php`: L19($fillable), L37-40(warehouse() relation)
- `DeliveryCourseChangeService.php`: L48, L65, L84, L141, L156, L186, L200, L208
- `ListWaves.php`: L46-47, L60, L71, L88, L192, L198, L253, L407, L459, L473, L526, L647, L692, L712, L784
- `WaveSettingForm.php`: L25-49(warehouse Select), L54(course filter)
- `WaveSettingsTable.php`: L22-29(warehouse column)
- `GenerateWaveSettingsCommand.php`: L20, L37, L66, L83, L96
- `WaveSettingSeeder.php`: L22, L47, L49, L65
- `GeneratePickerWaveCommand.php`: L65, L255, L314, L401, L460, L492, L561, L604

### Git ブランチ
- 作業ブランチ: feature/wave-delivery-course-reform
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P0: WarehouseResolver ユーティリティ作成
- 完了日: 2026-02-21
- 実績:
  - `app/Services/WarehouseResolver.php` 作成
  - メソッド: resolveRealWarehouseId(), isSameRealWarehouse(), getRealWarehouseCode()
  - Pint通過

### P1: wms_wave_settings.warehouse_id 削除
- 完了日: 2026-02-21
- 実績:
  - WaveSetting モデル: fillable から warehouse_id 削除、warehouse() リレーション削除、getWarehouseIdAttribute() アクセサ追加
  - WaveService: findExistingWave(), getOrCreateWave(), createTemporaryWave() から $warehouseId 引数削除
  - DeliveryCourseChangeService: getOrCreateWave() 呼び出しから $warehouseId 引数削除
  - GenerateWavesCommand: delivery_course_id 基準に統一、WarehouseResolver 使用の仮想倉庫判定追加
  - ListWaves: WaveSetting 検索・作成から warehouse_id 削除
  - WaveSettingForm: warehouse_id Select を削除、配送コースを全コースから選択可能に変更（倉庫名プレフィックス付き）
  - WaveSettingsTable: warehouse_id カラムを warehouse_name 導出カラムに変更
  - GenerateWaveSettingsCommand, WaveSettingSeeder, GeneratePickerWaveCommand: warehouse_id 参照を削除
  - マイグレーション作成（未実行、P6で実行）
  - Pint通過

### P2: StockTransferQueueService 実倉庫ベース修正
- 完了日: 2026-02-21
- 実績:
  - determineToWarehouse() メソッド追加（実倉庫ベース判定）
  - WarehouseResolver::isSameRealWarehouse() 使用
  - 異なる実倉庫→販売倉庫の実倉庫コードへ直接配送
  - Pint通過

### P3: DB変更とモデル作成（配送コース切替）
- 完了日: 2026-02-21
- 実績:
  - マイグレーション作成: wms_buyer_delivery_course_switch_settings テーブル
  - モデル作成: WmsBuyerDeliveryCourseSwitchSetting (SoftDeletes, buyer/toDeliveryCourse リレーション, switchTimeRule)
  - マイグレーション実行完了
  - Pint通過

### P4: 出荷倉庫不一致対応
- 完了日: 2026-02-21
- 実績:
  - WarehouseMismatchTransferService 作成: createMismatchTransfer(), べき等性チェック(request_id), 実倉庫ベース不一致検出
  - WmsPickingTask: STATUS_SHIPPED 定数追加、booted() に SHIPPED トリガー追加
  - Pint通過

### P5: 得意先配送コース時間切替
- 完了日: 2026-02-21
- 実績:
  - SwitchDeliveryCourseCommand 作成: 15分単位スロット、原子UPDATE実行制御、buyer_details一括更新
  - Filament Resource 作成: WmsBuyerDeliveryCourseSwitchSettingResource (リスト・作成・編集ページ)
  - フォーム: buyer_id Select, switch_time Select(15分単位), to_delivery_course_id Select
  - テーブル: 得意先名, 切替時刻, 切替先配送コース, 最終実行日
  - EMenu に DELIVERY_COURSE_SWITCH_SETTINGS 追加
  - routes/console.php にスケジューラー登録（everyFifteenMinutes）
  - Pint通過

### P6: マイグレーション実行・テスト・検証
- 完了日: 2026-02-21
- 実績:
  - Pint通過（全変更ファイル。既存の18件のスタイル問題は本PR対象外）
  - F-1b マイグレーション実行: wms_wave_settings.warehouse_id 削除完了
  - F-1 マイグレーション実行: wms_buyer_delivery_course_switch_settings テーブル作成完了
  - テスト実行: 109 passed, 17 failed（全て既存テストの問題、本PR起因のfailureなし）
  - wms:switch-delivery-course コマンド動作確認
