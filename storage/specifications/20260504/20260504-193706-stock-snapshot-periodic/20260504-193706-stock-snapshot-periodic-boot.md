# Work Plan: stock-snapshot-periodic

- **ID**: stock-snapshot-periodic
- **作成日**: 2026-05-04
- **最終更新**: 2026-05-04
- **ステータス**: 進行中
- **ディレクトリ**: storage/specifications/20260504/20260504-193706-stock-snapshot-periodic/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260504-193706-stock-snapshot-periodic-boot.md）
2. 20260504-193706-stock-snapshot-periodic-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

定期在庫スナップショット機能の実装。1日2回（6:00/18:00）在庫状態を2層構造（サマリー15ヶ月 + ロット明細6ヶ月→S3退避）で記録し、整合性検証を行う。

## 重要な設計制約

- **FK禁止**: 全リレーションはアプリケーションレベル管理
- **migrate:fresh/refresh/reset/db:wipe 禁止**: 基幹システム共有DB
- **`real_stocks` テーブルへの書き込み禁止**: SELECT のみ
- **同一時点性必須**: サマリーとロット明細は同一 REPEATABLE READ トランザクション内の consistent read から取得
- **冪等性必須**: サマリーは複合PKの `ON DUPLICATE KEY UPDATE`、ロット明細は `UNIQUE(snapshot_date, snapshot_time, lot_id)` の `ON DUPLICATE KEY UPDATE`
- **検証結果も冪等性必須**: `wms_stock_snapshot_verifications` は `UNIQUE(snapshot_date, snapshot_time)` に対して upsert
- **多重実行防止**: `GET_LOCK("wms:snapshot:{date}:{time}")` で並列実行を防止
- **パーティション保守必須**: `ensureFuturePartitions()` で16ヶ月先まで補充し、追加処理は `GET_LOCK("wms:snapshot:partition-maintenance")` で直列化
- **アーカイブ削除安全性**: ロット明細はS3 manifest検証後に `DROP PARTITION`。通常運用で大量DELETEしない
- **旧テーブル `wms_item_stock_snapshots` は変更しない**

## 仕様書

- `storage/specifications/20260504/20260504-193706-stock-snapshot-periodic/20260504-193706-stock-snapshot-periodic.md`

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_create_wms_stock_snapshots_table.php`
- `database/migrations/XXXX_create_wms_stock_snapshot_lots_table.php`
- `database/migrations/XXXX_create_wms_stock_snapshot_verifications_table.php`
- `app/Models/WmsStockSnapshot.php`
- `app/Models/WmsStockSnapshotLot.php`
- `app/Models/WmsStockSnapshotVerification.php`
- `app/Services/StockSnapshotService.php`
- `app/Console/Commands/SnapshotStocksCommand.php`
- `app/Console/Commands/SnapshotArchiveCommand.php`
- `app/Filament/Resources/WmsStockSnapshotResource.php`
- `app/Filament/Resources/WmsStockSnapshot/Pages/ListWmsStockSnapshots.php`
- `app/Filament/Resources/WmsStockSnapshot/Tables/WmsStockSnapshotTable.php`

### 既存変更
- `routes/console.php` — スケジュール追加
- `app/Enums/EMenu.php` — メニュー項目追加
- `config/filesystems.php` — 既存 `s3` ディスクを使用（原則変更不要）

### 参照のみ（変更禁止）
- `app/Models/WmsItemStockSnapshot.php` — 旧スナップショットモデル
- `app/Models/Sakemaru/RealStock.php` — 在庫データ参照元
- `app/Models/Sakemaru/RealStockLot.php` — ロット参照元
- `database/migrations/2026_01_13_*_update_wms_v_stock_available_view_*.php` — ビュー定義

## テストデータ

```bash
# ローカルDB にデータあり（2026-05-04 実測）
# real_stocks (non-zero): 50,566行
# real_stock_lots (ACTIVE): 50,566行（1:1）
# warehouses: 32（在庫あり20）、items: 51,197
```

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: マイグレーション | 完了 | 2026-05-04 | 3テーブル + 各17パーティション作成済み |
| P1: モデル | 完了 | 2026-05-04 | 3モデル作成済み |
| P2: スナップショット取得サービス | 完了 | 2026-05-04 | capture + 検証 + upsert + パーティション保守 |
| P3: 取得コマンド + ローカルテスト | 完了 | 2026-05-04 | morning/evening 実データ取得、冪等性確認済み |
| P4: S3退避サービス + コマンド | 完了 | 2026-05-04 | dry-run確認済み。古い対象データなし |
| P5: Filament UI | 修正済み | 2026-05-04 | ルート登録確認済み。複合キーなしモデルの空ORDER BYを修正 |
| P6: スケジュール + メニュー統合 | 実装済み | 2026-05-04 | EMenu追加。スケジュールはリリース判断用にコメント追加 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### マイグレーション情報（P0完了後に記入）
- サマリーテーブル マイグレーションファイル名: `2026_05_04_193706_create_wms_stock_snapshots_table.php`
- ロット明細テーブル マイグレーションファイル名: `2026_05_04_193707_create_wms_stock_snapshot_lots_table.php`
- 検証テーブル マイグレーションファイル名: `2026_05_04_193708_create_wms_stock_snapshot_verifications_table.php`
- パーティション作成範囲: 2026-05〜2027-09（各テーブル17パーティション）

### テスト実行結果（P3完了後に記入）
- サマリー対象行数（COUNT）: morning 49,467 / evening 49,467
- ロット明細対象行数（COUNT）: morning 49,490 / evening 49,490
- 整合性検証結果: summary_lot 0 mismatches / realtime 0 mismatches / healthy yes
- 実行時間: morning 2.12s、冪等性再実行 3.14s、evening 2.4s

### S3設定（P4完了後に記入）
- S3ディスク名: `s3`（既存設定）
- バケットパス: `wms-snapshots/lots/{YYYY}/{MM}/snapshot_lots_{YYYYMMDD}_{morning|evening}.csv.gz`
- manifestパス: `wms-snapshots/lots/{YYYY}/{MM}/manifest_{YYYYMM}.json`

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P0: マイグレーション
- 完了日: 2026-05-04
- 実績:
  - `php artisan migrate` 成功
  - `migrate:status` で3マイグレーション Ran
  - `INFORMATION_SCHEMA.PARTITIONS` で各テーブル17パーティション確認

### P1: モデル
- 完了日: 2026-05-04
- 実績:
  - `WmsStockSnapshot`, `WmsStockSnapshotLot`, `WmsStockSnapshotVerification` 作成
  - tinkerで基本COUNT確認

### P2: スナップショット取得サービス
- 完了日: 2026-05-04
- 実績:
  - `StockSnapshotService` 作成
  - `capture`, `verify`, `archiveAndCleanup`, `ensureFuturePartitions` 実装
  - `real_stocks.received_at` が存在しない環境では `real_stock_received_at` をNULL保存

### P3: 取得コマンド + ローカルテスト
- 完了日: 2026-05-04
- 実績:
  - `php artisan wms:snapshot-stocks --time=morning` 成功
  - 同一 morning 再実行成功（検証結果upsert確認）
  - `php artisan wms:snapshot-stocks --time=evening` 成功

### P4: S3退避サービス + コマンド
- 完了日: 2026-05-04
- 実績:
  - `php artisan wms:snapshot-archive --dry-run` 成功
  - 対象0件（lot cutoff 2025-11-01）

### P5: Filament UI
- 完了日: 2026-05-04
- 実績:
  - 閲覧専用Resource/Table/Listページ作成
  - ロット明細モーダル用Blade作成
  - `route:list` で `admin/wms-stock-snapshots` 確認
  - `WmsStockSnapshot` はサマリーテーブルの複合自然キー運用でEloquent単一PKを持たないため、Filamentの `defaultKeySort(false)` を設定
  - 行アクション用に `ListWmsStockSnapshots::getTableRecordKey()` / `resolveTableRecord()` で `snapshot_date|snapshot_time|warehouse_id|item_id` の複合キーを解決
  - Filament内部のレコードコレクションキーも `Model::getKey()` を参照するため、`WmsStockSnapshot::getKey()` を複合キー化
  - Filamentがモーダル設定をレコード未解決タイミングでも評価するため、ロット明細アクションの `modalHeading` / `modalContent` をnullable record対応
  - 未認証 `curl -k -I https://wms.sakemaru.test/admin/wms-stock-snapshots` は `/admin/login` への302を確認（ルーティング/HTTP到達確認）
  - DB確認: 2026-05-04 evening は summary 49,467キー / lot 49,490行。先頭サマリー `2026-05-04|evening|1|111009` のロット1件取得を確認

### P6: スケジュール + メニュー統合
- 完了日: 2026-05-04
- 実績:
  - `EMenu::WMS_STOCK_SNAPSHOTS` 追加
  - `routes/console.php` にコメント状態でスケジュール追加（有効化はリリース判断）
