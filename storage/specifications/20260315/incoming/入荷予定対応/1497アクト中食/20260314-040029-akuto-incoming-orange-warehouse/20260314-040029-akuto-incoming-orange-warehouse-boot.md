# Work Plan: akuto-incoming-orange-warehouse

- **ID**: akuto-incoming-orange-warehouse
- **作成日**: 2026-03-14
- **最終更新**: 2026-03-14 (P6完了・全Phase完了)
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/incoming/入荷予定対応/1497アクト中食/20260314-040029-akuto-incoming-orange-warehouse/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイル（boot.md）を読む
2. `20260314-040029-akuto-incoming-orange-warehouse-plan.md` を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

アクト中食（1497）冷凍商品の入荷予定CSV対応。オレンジ冷凍倉庫（コード901）を新設し、サテライト倉庫→オレンジ倉庫への移動依頼＋オレンジ倉庫→アクト中食への外部発注の仕組みをデータ設定＋パーサー修正で実現する。

## 重要な設計制約

- **FK禁止**: warehouses, item_contractors テーブルへのFK制約は追加しない
- **migrate:fresh/refresh 禁止**: Seederのみでデータ投入
- **共有DB**: warehouses, item_contractors, contractors, suppliers は基幹システム（sakemaru）と共有
- **冪等性**: Seederは複数回実行しても安全であること
- **wms_contractor_settings.contractor_id は UNIQUE**: 1コントラクタ1設定のみ
- **コード変更は最小限**: ActCsvIncomingParser のマッピング追加のみ。OrderCandidateCalculationService 等は変更しない

## 対象ファイル

### 新規作成
- `database/seeders/OrangeWarehouseSeeder.php` — オレンジ冷凍倉庫新設 + contractors/suppliers/wms_contractor_settings/wms_warehouse_auto_order_settings
- `database/seeders/AkutoFrozenItemContractorSeeder.php` — 対象24商品のitem_contractors設定

### 既存変更
- `app/Services/AutoOrder/IncomingParsers/ActCsvIncomingParser.php` — 得意先コード→倉庫マッピング追加
- `database/seeders/InitSystemSeeder.php` — 新Seeder呼び出し追加

### 参照のみ（変更禁止）
- `app/Services/AutoOrder/IncomingReceiveService.php`
- `app/Services/AutoOrder/OrderCandidateCalculationService.php`
- `app/Services/AutoOrder/TransferCandidateExecutionService.php`
- `app/Models/WmsContractorSetting.php`
- `app/Models/Sakemaru/Warehouse.php`
- `app/Models/Sakemaru/ItemContractor.php`

## テストデータ

- 対象商品CSV: `storage/seeders/akuto-frozoz-items.csv`（24商品）
- アクト中食サンプルCSV: `storage/specifications/incoming/入荷予定対応/1497アクト中食/ny934*.csv`（3ファイル）
- 仕様書: `storage/specifications/incoming/入荷予定対応/1497アクト中食/20260314-040029-akuto-incoming-orange-warehouse/20260314-040029-akuto-incoming-orange-warehouse.md`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: OrangeWarehouseSeeder作成 | 完了 | 2026-03-14 | 構文OK, Pint OK |
| P2: AkutoFrozenItemContractorSeeder作成 | 完了 | 2026-03-14 | item_contractors 3種（オレンジEXT/サテライトINT/91無効化） |
| P3: ActCsvIncomingParserマッピング追加 | 完了 | 2026-03-14 | SHOP_WAREHOUSE_MAP + saveSlipGroup変換 |
| P4: InitSystemSeeder更新 | 完了 | 2026-03-14 | 新Seeder呼び出し追加 |
| P5: Seeder実行＋DB確認 | 完了 | 2026-03-14 | 全9項目パス、冪等性OK |
| P6: CSVアップロード動作確認 | 完了 | 2026-03-14 | 934→91マッピングOK、倉庫解決OK |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### DB調査結果（仕様書作成時に調査済み）
- 倉庫91 ID: 91
- 倉庫901: 未存在（新規作成）
- 91のINTERNALコントラクタ: contractor_id=9012, wms_contractor_settings id=116
- アクト中食: contractor_id=1497, supplier_id=8901, wms_contractor_settings id=290 (MANUAL_CSV)
- オレンジ用コントラクタ: id=9901（未使用、利用可能）
- 対象商品: 24件（items テーブルで全件確認済み）
- サテライト倉庫（店舗）: [1, 2, 3, 4, 7, 8, 9, 10, 11, 21, 22, 23]
- warehouse_stock_transfer_delivery_courses: 0件（空テーブル）

### Git ブランチ
- 作業ブランチ: (実施後に記入)
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: OrangeWarehouseSeeder作成
- 完了日: 2026-03-14
- 成果物: `database/seeders/OrangeWarehouseSeeder.php`
- 実績:
  - 6ステップ: warehouses(replicate 91→901) + contractors(id=9901) + suppliers(id=9901) + wms_contractor_settings(9901 INTERNAL) + wms_contractor_settings(1497 CSV更新) + wms_warehouse_auto_order_settings
  - 全操作が冪等（exists チェック or updateOrCreate）
  - php -l 構文OK, Pint フォーマットOK

### P2: AkutoFrozenItemContractorSeeder作成
- 完了日: 2026-03-14
- 成果物: `database/seeders/AkutoFrozenItemContractorSeeder.php`
- 実績:
  - CSV読み込み→items.codeでitem_id一括取得（N+1回避）
  - Step 2: オレンジ倉庫(901)×24商品×1497 updateOrInsert（safety_stock=入数）
  - Step 3: サテライト12倉庫のcontractor_id 1497→9901 一括UPDATE
  - Step 4: 倉庫91×24商品×1497 is_auto_order=false 一括UPDATE
  - 全操作が冪等（updateOrInsert + 条件付きUPDATE）
  - php -l 構文OK, Pint フォーマットOK

### P3: ActCsvIncomingParserマッピング追加
- 完了日: 2026-03-14
- 成果物: `app/Services/AutoOrder/IncomingParsers/ActCsvIncomingParser.php`
- 実績:
  - SHOP_WAREHOUSE_MAP 定数追加（934→91, 607→901, 618→10, 122→21）
  - saveSlipGroup() の shopCode 変換: ltrim('0') + MAP lookup + fallback
  - php -l 構文OK, Pint フォーマットOK

### P4: InitSystemSeeder更新
- 完了日: 2026-03-14
- 成果物: `database/seeders/InitSystemSeeder.php`
- 実績:
  - WmsPickingAssignmentStrategySeeder の後に OrangeWarehouseSeeder → AkutoFrozenItemContractorSeeder の順で追加
  - php -l 構文OK, Pint フォーマットOK

### P5: Seeder実行＋DB確認
- 完了日: 2026-03-14
- 実績:
  - OrangeWarehouseSeeder: is_virtual generated column 除外修正 → 実行成功（倉庫id=101）
  - AkutoFrozenItemContractorSeeder: client_id追加修正 → 実行成功（24件+288件+24件）
  - 全9項目データ検証パス（倉庫901, contractor9901, supplier9901, wcs設定, item_contractors）
  - 2回目実行エラーなし（冪等性確認OK）

### P6: CSVアップロード動作確認
- 完了日: 2026-03-14
- 実績:
  - サンプルCSV ny93420260218114750.csv でパーサー実行成功
  - 得意先コード 00000934 → ltrim '0' → 934 → SHOP_WAREHOUSE_MAP → b_shop_code='91' 確認
  - 2伝票(9994244, 9340951)とも b_shop_code='91' で正しくマッピング
  - 倉庫解決: code=91 → 華むすびの蔵センター(id=91)、code=901 → オレンジ冷凍倉庫(id=101)
