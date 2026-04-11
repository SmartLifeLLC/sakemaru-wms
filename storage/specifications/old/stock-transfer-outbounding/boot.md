# Work Plan: stock-transfer-outbounding

- **ID**: stock-transfer-outbounding
- **作成日**: 2026-02-24
- **最終更新**: 2026-02-24
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/stock-transfer-outbounding/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

欠品対応モーダル（横持ち出荷指示）の倉庫選択を改善する。得意先別最寄倉庫テーブルの作成、在庫リストでの最寄倉庫表示、同一配送コース上の横持ち出荷予定倉庫の表示を実装する。

## 重要な設計制約

- **FK禁止**: 全テーブルにForeignKeyは作成しない（アプリケーションレベルで整合性管理）
- **DB破壊禁止**: `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` は絶対禁止
- **テーブルプレフィックス**: `wms_` は手動命名（database configのprefixは空文字列）
- **Filament 4パターン**: `Filament\Actions\Action`, `Filament\Schemas\Components\Section` 等の正しいインポート
- **WmsModel基底クラス**: WMS用テーブルは `WmsModel` を継承し `sakemaru` コネクションを使用

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_create_wms_partner_nearest_warehouses_table.php`
- `database/migrations/XXXX_create_wms_partner_warehouse_distances_table.php`
- `app/Models/WmsPartnerNearestWarehouse.php`
- `app/Models/WmsPartnerWarehouseDistance.php`

### 既存変更
- `resources/views/filament/forms/components/proxy-shipment-allocations.blade.php` - 在庫リストUIに最寄倉庫ラベルと配送コース内横持ち予定倉庫セクションを追加
- `app/Filament/Resources/WmsShortages/Tables/WmsShortagesTable.php` - viewDataに最寄倉庫・配送コース横持ち情報を追加
- `app/Filament/Resources/WmsShortagesWaitingApprovals/Tables/WmsShortagesWaitingApprovalsTable.php` - 同上

### 参照のみ（変更禁止）
- `app/Models/WmsModel.php`
- `app/Models/Sakemaru/Warehouse.php`
- `app/Models/Sakemaru/DeliveryCourse.php`
- `app/Models/Sakemaru/Partner.php`
- `app/Models/WmsShortage.php`
- `app/Models/WmsShortageAllocation.php`
- `app/Services/Shortage/ProxyShipmentService.php`

## テストデータ

- `wms_partner_warehouse_distances` に得意先×倉庫の距離データを手動で投入
- `wms_partner_nearest_warehouses` に最寄倉庫のキャッシュデータを投入
- 既存の `php artisan wms:generate-test-shortages` で欠品テストデータを生成

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: DB マイグレーション＆モデル作成 | 完了 | 2026-02-24 | 2テーブル作成、2モデル作成 |
| P1: 在庫リストへの最寄倉庫ラベル表示 | 完了 | 2026-02-24 | おすすめバッジ＋先頭ソート |
| P2: 同一配送コース上横持ち出荷予定倉庫の表示 | 完了 | 2026-02-24 | 配送コース内セクション追加 |
| P3: 動作確認・テスト | 完了 | 2026-02-24 | 構文OK、Pint OK、既存テスト影響なし |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### マイグレーション名（P0完了後に記入）
- wms_partner_warehouse_distances: 2026_02_24_000001_create_wms_partner_warehouse_distances_table.php
- wms_partner_nearest_warehouses: 2026_02_24_000002_create_wms_partner_nearest_warehouses_table.php

### 変更ファイル一覧
- database/migrations/2026_02_24_000001_create_wms_partner_warehouse_distances_table.php (新規)
- database/migrations/2026_02_24_000002_create_wms_partner_nearest_warehouses_table.php (新規)
- app/Models/WmsPartnerWarehouseDistance.php (新規)
- app/Models/WmsPartnerNearestWarehouse.php (新規)
- app/Filament/Resources/WmsShortages/Tables/WmsShortagesTable.php (変更)
- app/Filament/Resources/WmsShortagesWaitingApprovals/Tables/WmsShortagesWaitingApprovalsTable.php (変更)
- resources/views/filament/forms/components/proxy-shipment-allocations.blade.php (変更)

### Git ブランチ
- 作業ブランチ: feature/stock-transfer-outbounding
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P0: DB マイグレーション＆モデル作成
- 完了日: 2026-02-24
- 実績:
  - wms_partner_warehouse_distances テーブル作成（partner_id + warehouse_id ユニーク制約）
  - wms_partner_nearest_warehouses テーブル作成（partner_id ユニーク制約）
  - WmsPartnerWarehouseDistance モデル作成
  - WmsPartnerNearestWarehouse モデル作成
  - php artisan migrate 成功

### P1: 在庫リストへの最寄倉庫ラベル表示
- 完了日: 2026-02-24
- 実績:
  - WmsShortagesTable viewData に nearest_warehouse_id 追加
  - WmsShortagesWaitingApprovalsTable viewData に nearest_warehouse_id 追加
  - Bladeテンプレートに sortedStocks computed property 追加（最寄倉庫を先頭にソート）
  - 在庫リスト行に「おすすめ」バッジ（緑色）を条件付き表示

### P2: 同一配送コース上横持ち出荷予定倉庫の表示
- 完了日: 2026-02-24
- 実績:
  - WmsShortagesTable viewData に same_course_allocations 追加
  - WmsShortagesWaitingApprovalsTable viewData に same_course_allocations 追加
  - Bladeテンプレートに「同一配送コース上横持ち出荷予定倉庫」セクション追加（アンバー色ボーダー）
  - 倉庫クリックで横持ち出荷指示に追加可能
  - データがない場合はセクション非表示（x-show + x-cloak）

### P3: 動作確認・テスト
- 完了日: 2026-02-24
- 実績:
  - 全ファイルのPHP構文チェックOK（php -l）
  - Laravel Pint フォーマッタOK
  - PHPUnitテスト実行: 17失敗は既存問題、今回の変更と無関係
  - 関連テストファイルなし（UI表示系の変更のため）
