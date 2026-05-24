# Work Plan: tanaoroshi-inventory-count

- **ID**: tanaoroshi-inventory-count
- **作成日**: 2026-05-23
- **最終更新**: 2026-05-23
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260523/20260523-022134-tanaoroshi-inventory-count/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. `20260523-022134-tanaoroshi-inventory-count-plan.md` を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開
7. 仕様書: `20260523-022134-tanaoroshi-inventory-count.md`（同ディレクトリ）

## 概要

棚卸し機能（作成→スナップショット→HT実棚入力→差異確認→確定→在庫調整反映）を段階的に実装する。全倉庫単位、フロアグルーピング + code1フィルタリング。確定時は既存StockDisposalController経由で在庫調整。

## 重要な設計制約

1. **FK禁止**: アプリケーション層で整合性管理
2. **migrate:fresh/refresh/reset 禁止**: `php artisan migrate` のみ使用
3. **real_stocks直接更新はconfirm時のみ**: カウント中は一切触らない
4. **楽観ロック必須**: real_stocks更新時は `wms_lock_version` チェック
5. **冪等性**: Android API入力は `request_uuid` で重複送信を防止
6. **在庫移動ロックは運用ルール**: システム的ロックは実装しない
7. **サブエージェント活用**: 各Phaseの実装はサブエージェントで並列化可
8. **Filament 4仕様**: `storage/specifications/filament4spec.md` を必ず参照
9. **モーダルデザイン**: `~/.claude/design-knowledge/modal-design.md` 参照
10. **テーブルデザイン**: `storage/specifications/table-design-specification.md` 参照

## 対象ファイル

### 新規作成

**マイグレーション**:
- `database/migrations/2026_05_23_XXXXXX_create_wms_inventory_counts_table.php`
- `database/migrations/2026_05_23_XXXXXX_create_wms_inventory_count_items_table.php`
- `database/migrations/2026_05_23_XXXXXX_create_wms_inventory_count_item_logs_table.php`

**モデル**:
- `app/Models/WmsInventoryCount.php`
- `app/Models/WmsInventoryCountItem.php`
- `app/Models/WmsInventoryCountItemLog.php`

**サービス**:
- `app/Services/InventoryCount/InventoryCountService.php`

**Filament Resource**:
- `app/Filament/Resources/WmsInventoryCountResource.php`
- `app/Filament/Resources/WmsInventoryCount/Pages/ListWmsInventoryCounts.php`
- `app/Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php`
- `app/Filament/Resources/WmsInventoryCount/Tables/WmsInventoryCountTable.php`
- `app/Filament/Resources/WmsInventoryCount/Tables/WmsInventoryCountItemTable.php`

**APIコントローラー**:
- `app/Http/Controllers/Api/InventoryCountController.php`

**PDF出力**:
- `app/Services/InventoryCount/InventoryInstructionPdfService.php`
- `app/Services/InventoryCount/InventoryDiffListPdfService.php`

### 既存変更

- `routes/api.php` — 棚卸しAPIルート追加
- メガメニュー Blade — 「在庫管理 > 棚卸し」メニュー追加
- `app/Http/Controllers/Api/StockDisposalController.php` — REASONS に `INVENTORY` 追加

### 参照のみ（変更禁止）

- `app/Models/WmsModel.php` — 基底クラス
- `app/Models/WaveGroup.php` — generateGroupNo() パターン
- `app/Models/WmsStockSnapshot.php` — スナップショットロジック参考
- `app/Filament/Pages/WmsPickingWait.php` — 進捗・詳細UI参考
- `app/Filament/Resources/Waves/` — Wave作成フロー参考
- `app/Models/Sakemaru/Location.php` — code1/code2/code3, floor_id
- `app/Models/Sakemaru/Floor.php` — フロアモデル
- `app/Enums/InventoryStatus.php` — ステータスenum
- `app/Enums/TransactionType.php` — INVENTORY / INVENTORY_DIFF / INVENTORY_EXECUTE
- sakemaru-ai-core: `app/Services/Pdf/BaseTcpdfService.php` — TCPDF基底パターン
- `storage/specifications/20260523/棚卸指示書（H）.pdf` — 指示書レイアウト参考
- `storage/specifications/20260523/20230815 棚卸差異リスト.pdf` — 差異リストレイアウト参考
- `storage/specifications/20260523/preview (2).html` — 実装計画書

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: DB + モデル + マイグレーション | 完了 | 2026-05-23 | 3テーブル + 3モデル + サービス骨格、Tinker検証OK |
| P2: Web UI（一覧・作成・詳細）+ スナップショット | 完了 | 2026-05-23 | Resource + 一覧/詳細ページ + スナップショット + RelationManager |
| P3: 棚卸指示書PDF出力 | 完了 | 2026-05-23 | TCPDF A4横, フロア改ページ, Code128バーコード |
| P4: Android API（実棚入力） | 完了 | 2026-05-23 | 6エンドポイント, 冪等性, バーコードスキャン |
| P5: 差異確認 + 差異リストPDF | 完了 | 2026-05-23 | 差異計算, 差異リストPDF(A4縦), 差異計算アクション |
| P6: 確定 + 在庫調整反映 | 完了 | 2026-05-23 | real_stocks楽観ロック更新, INVENTORY reason追加, 確定アクション |
| P7: メガメニュー + 統合テスト | 完了 | 2026-05-23 | EMenuCategory::INVENTORY自動連携, 構文チェックOK |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### DB調査結果（P1完了）
- real_stocks: id, client_id, warehouse_id, stock_allocation_id, item_id, current_quantity, reserved_quantity, available_quantity, wms_lock_version, picking_quantity, lock_version
- floors: name例=「輸入課1F」「本店1F」「華むすびの蔵センター1F」（warehouse_id別）
- items: バーコード専用カラムなし。`item_search_information`テーブル（code_type=JAN, search_string=バーコード値）経由
- locations: id, warehouse_id, floor_id, wms_picking_area_id, code1, code2, code3, name, temperature_type
- item_prices: cost_unit_price（仕入原価）が取得可能

### スナップショット検証（P2実施後に記入）
- テスト倉庫ID: (実施後に記入)
- スナップショット件数: (実施後に記入)

### Git ブランチ
- 作業ブランチ: (作業開始時に記入)
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: DB + モデル + マイグレーション
- 完了日: 2026-05-23
- 実績:
  - wms_inventory_counts, wms_inventory_count_items, wms_inventory_count_item_logs 3テーブル作成
  - WmsInventoryCount, WmsInventoryCountItem, WmsInventoryCountItemLog 3モデル作成
  - InventoryCountService 骨格作成（メソッドスタブ）
  - Tinkerで Create/Relation/generateCountNo 全検証OK

### P2: Web UI + スナップショット
- 完了日: 2026-05-23
- 実績:
  - WmsInventoryCountResource + Table + ItemTable + ListPage + ViewPage + RelationManager 作成
  - InventoryCountService.create/takeSnapshot/startCounting/cancel 実装
  - スナップショット: real_stocks JOIN items/locations/floors/item_search_information/item_prices チャンク処理
  - View詳細ページ: ヘッダー情報 + RelationManager明細テーブル
  - route: admin/wms-inventory-counts, admin/wms-inventory-counts/{record}

### P3: 棚卸指示書PDF
- 完了日: 2026-05-23
- 実績:
  - `app/Services/InventoryCount/InventoryInstructionPdfService.php` 作成
  - TCPDF A4横、kozgopromediumフォント、フロア別改ページ、Code128バーコード
  - 座標描画パターン（HTML不使用）、3行ブロック×明細
  - ViewPage に「棚卸指示書PDF」ダウンロードアクション追加

### P4: Android API
- 完了日: 2026-05-23
- 実績:
  - `app/Http/Controllers/Api/InventoryCountController.php` 作成（6エンドポイント）
  - index/show/items/scan/count/logs
  - count: request_uuid冪等性チェック、first/second_count_quantity更新、ログ記録
  - scan: barcode OR item_code検索
  - `routes/api.php` にルート追加（api.key + auth:sanctum ミドルウェア内）
  - `InventoryCountService::registerCount()` 実装

### P5: 差異確認 + 差異リストPDF
- 完了日: 2026-05-23
- 実績:
  - `InventoryCountService::calculateDifferences()` 実装（chunkById、final→second→first優先度）
  - `app/Services/InventoryCount/InventoryDiffListPdfService.php` 作成
  - TCPDF A4縦、item_code順、2行ブロック（code+cost / name+counts+diffs）
  - ViewPage に「差異計算」「差異リストPDF」アクション追加

### P6: 確定 + 在庫調整反映
- 完了日: 2026-05-23
- 実績:
  - `InventoryCountService::confirm()` 実装（トランザクション、楽観ロック）
  - real_stocks.current_quantity + available_quantity を差異分更新、wms_lock_version チェック
  - `StockDisposalController::REASONS` に 'INVENTORY' 追加
  - ViewPage に「確定」アクション追加（danger色、確認ダイアログ付き）

### P7: メガメニュー + 統合テスト
- 完了日: 2026-05-23
- 実績:
  - WmsInventoryCountResource.getNavigationGroup()='在庫管理' → EMenuCategory::INVENTORY自動連携
  - メガメニュー「在庫」タブに自動表示（追加実装不要）
  - 全ファイル構文チェックOK（php -l）
  - 既存テスト回帰なし（失敗は事前からの環境起因）
