# 発注・移動候補追加モーダル 商品検索リデザイン 作業計画

## 前提

- 発注候補・移動候補の手動追加モーダルが既に実装済み
- 現在は1行ずつ商品コード/名前/JANで検索→選択→数量入力するフロー
- `stats_item_warehouse_daily_sales` テーブルは未作成（集計元）
- `stats_item_warehouse_sales_summaries` テーブルは未作成（表示用サマリ）
- ユーザー回答: 出荷元=daily_sales、最終入荷日=非対応、移動候補=ケース/バラ分別、検索範囲=全商品（倉庫選択）、集計=日次、プリセット=全PENDING

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | DB・モデル作成 | daily_sales + summaries テーブル・マイグレーション・モデル | マイグレーション実行成功、モデルのリレーション確認 |
| P2 | サマリ集計コマンド | daily_sales → summaries 集計コマンド | コマンド実行でsummariesにデータが入る |
| P3 | 発注候補モーダルUI | 検索フィルタ + 商品リスト + 数量入力に変更 | モーダルで検索→数量入力→発注候補登録が動作する |
| P4 | 移動候補モーダルUI | 同様のリデザイン + ケース/バラ自動分割 | モーダルで検索→数量入力→移動候補登録が動作する |
| P5 | 動作確認・調整 | E2E確認、パフォーマンス検証、UI微調整 | 全フロー正常動作、レスポンス1秒以内 |

---

## P1: DB・モデル作成

### 目的

出荷実績の日別データと期間サマリを格納するテーブルを作成し、Eloquentモデルを実装する。

### 修正方針

#### テーブル1: `stats_item_warehouse_daily_sales`

日別・倉庫別・商品別の出荷データ。サマリ集計の元データ。

```sql
CREATE TABLE stats_item_warehouse_daily_sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    target_date DATE NOT NULL,
    shipped_piece_qty INT NOT NULL DEFAULT 0,      -- バラ換算出荷数量
    shipped_case_qty INT NOT NULL DEFAULT 0,       -- ケース出荷数量
    shipped_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- 出荷金額
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uk_warehouse_item_date (warehouse_id, item_id, target_date),
    INDEX idx_item_date (item_id, target_date),
    INDEX idx_target_date (target_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

#### テーブル2: `stats_item_warehouse_sales_summaries`

```sql
CREATE TABLE stats_item_warehouse_sales_summaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    last_3d_qty INT NOT NULL DEFAULT 0,
    last_7d_qty INT NOT NULL DEFAULT 0,
    last_14d_qty INT NOT NULL DEFAULT 0,
    last_30d_qty INT NOT NULL DEFAULT 0,
    avg_3d_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    avg_7d_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    avg_14d_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    avg_30d_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    last_shipped_at DATE NULL,
    calculated_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uk_warehouse_item (warehouse_id, item_id),
    INDEX idx_item_id (item_id),
    INDEX idx_last_shipped_at (last_shipped_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

#### モデル

**`app/Models/StatsItemWarehouseDailySales.php`**
- extends `WmsModel`（sakemaru接続）
- リレーション: `item()`, `warehouse()`
- スコープ: `forWarehouse($id)`, `forItem($id)`, `forDateRange($from, $to)`

**`app/Models/StatsItemWarehouseSalesSummary.php`**
- extends `WmsModel`
- リレーション: `item()`, `warehouse()`
- スコープ: `forWarehouse($id)`

### 修正対象ファイル

- `database/migrations/XXXX_create_stats_item_warehouse_daily_sales_table.php`（新規）
- `database/migrations/XXXX_create_stats_item_warehouse_sales_summaries_table.php`（新規）
- `app/Models/StatsItemWarehouseDailySales.php`（新規）
- `app/Models/StatsItemWarehouseSalesSummary.php`（新規）

### 完了条件

- `php artisan migrate` が成功
- `php artisan migrate:status` で両テーブルが Ran 表示
- モデルの `php -l` 構文チェック通過

---

## P2: サマリ集計コマンド

### 目的

`stats_item_warehouse_daily_sales` から期間別サマリを計算し、`stats_item_warehouse_sales_summaries` に upsert するコマンドを作成する。

### 修正方針

**`app/Console/Commands/Stats/SyncSalesSummariesCommand.php`**

```php
// コマンド: wms:sync-sales-summaries
// オプション: --warehouse-id= (特定倉庫のみ), --dry-run

// 集計ロジック:
// 1. daily_sales から倉庫×商品ごとに集計
// 2. 直近3/7/14/30日の合計・日平均を計算
// 3. 最終出荷日を取得
// 4. summaries テーブルに upsert
```

集計SQL概要:
```sql
SELECT
    warehouse_id,
    item_id,
    SUM(CASE WHEN target_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY) THEN shipped_piece_qty ELSE 0 END) AS last_3d_qty,
    SUM(CASE WHEN target_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN shipped_piece_qty ELSE 0 END) AS last_7d_qty,
    -- ... 14d, 30d 同様
    MAX(CASE WHEN shipped_piece_qty > 0 THEN target_date ELSE NULL END) AS last_shipped_at
FROM stats_item_warehouse_daily_sales
WHERE target_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY warehouse_id, item_id
```

### 修正対象ファイル

- `app/Console/Commands/Stats/SyncSalesSummariesCommand.php`（新規）

### 完了条件

- コマンドが `php artisan wms:sync-sales-summaries` で実行可能
- daily_sales にテストデータを入れた状態でサマリが正しく計算される
- `--dry-run` で実際の書き込みなしに集計結果を確認可能

---

## P3: 発注候補モーダルUI

### 目的

発注候補追加モーダルを「検索フィルタ + 商品リストテーブル + 行内数量入力」形式にリデザインする。

### 修正方針

#### Livewire側（`ListWmsOrderCandidates.php`）

1. 既存の `searchItemsForOrderCreate()` を新しい検索メソッドに置き換え

```php
public function searchItemsForModal(
    int $warehouseId,
    ?string $keyword = null,
    ?int $contractorId = null,
    ?int $category1Id = null,
    ?int $category2Id = null,
    ?int $category3Id = null,
    ?string $lastShippedFrom = null,
    ?string $lastShippedTo = null,
    int $page = 1,
    int $perPage = 25,
): array {
    // items テーブルを基準に検索
    // LEFT JOIN stats_item_warehouse_sales_summaries で出荷実績
    // LEFT JOIN item_contractors で発注先情報
    // keyword: 商品名 / 商品コード / JANコード（全角→半角変換）
    // 既存PENDING候補の数量をサブクエリで取得
    // ページネーション対応
}
```

2. 登録処理の修正
- 検索結果から数量入力された行のみ抽出して登録
- ケース数量・バラ数量の両方に対応
- 既存PENDING候補がある場合は数量を上書き更新

#### Blade側（`order-candidate-create-items.blade.php`）

Alpine.jsで以下のUIを構築:

```
■ 検索フィルタ
- 商品名/CD/JAN: テキスト入力
- 発注先: セレクト（item_contractorsから取得）
- 大分類/中分類/小分類: 連動セレクト
- 最終出荷日: 日付範囲

■ 検索結果テーブル
| 商品CD | 商品名 | 規格 | 入数 | 発注先 | 最終出荷 | 3d | 7d | ケース | バラ |
- ケース/バラ列は数量入力（TextInput）
- 既存PENDING候補がある行はその数量をプリセット
- ページネーション付き

■ フッター
- 入力済み件数の表示
- [発注せず閉じる] [追加する]
```

### 修正対象ファイル

- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php`（変更）
- `resources/views/filament/components/order-candidate-create-items.blade.php`（変更）

### 完了条件

- モーダルが開き、倉庫選択→検索フィルタ→商品リスト表示まで動作
- 出荷実績（3d/7d）が表示される
- 既存PENDING候補の数量がプリセット表示される
- 数量入力→追加ボタンで発注候補が登録される
- `php -l` 構文チェック通過

---

## P4: 移動候補モーダルUI

### 目的

移動候補追加モーダルを同様にリデザインし、ケース/バラ自動分割ロジックを追加する。

### 修正方針

#### Livewire側（`ListWmsStockTransferCandidates.php`）

1. `searchItemsForModal()` と同様の検索メソッドを実装
2. 登録処理にケース/バラ分割ロジックを追加:

```php
// 入力: 必要数量（バラ換算）
// 処理:
$capacityCase = $item->capacity_case ?? 1;
$caseQty = intdiv($totalPieceQty, $capacityCase);  // ケース数
$pieceQty = $totalPieceQty % $capacityCase;          // 余りバラ数
// ケース行とバラ行を別々に作成
```

#### Blade側（`transfer-order-create-items.blade.php`）

- 発注候補モーダルと同様のUI構造
- 数量入力はバラ換算の単一カラム（登録時にケース/バラ自動分割）
- または、ケース/バラ両方の入力カラム

### 修正対象ファイル

- `app/Filament/Resources/WmsStockTransferCandidates/Pages/ListWmsStockTransferCandidates.php`（変更）
- `resources/views/filament/components/transfer-order-create-items.blade.php`（変更）

### 完了条件

- 移動候補モーダルが検索フィルタ付きリスト形式で動作
- 数量入力→登録でケース/バラが正しく分割される
- `php -l` 構文チェック通過

---

## P5: 動作確認・調整

### 目的

全フローの結合確認とパフォーマンス・UI微調整。

### 確認項目

1. **発注候補追加フロー**: 倉庫選択→検索→数量入力→登録→テーブルに反映
2. **移動候補追加フロー**: 同上＋ケース/バラ分割の正確性
3. **パフォーマンス**: 検索レスポンス1秒以内（商品数万件想定）
4. **既存候補プリセット**: 全PENDING候補の数量が正しく表示
5. **エラーハンドリング**: 販売終了品、発注先未設定品の警告表示
6. **モーダルデザイン**: ヘッダー紺色、ボタン配置、レスポンシブ

### 完了条件

- 全フローがブラウザで正常動作
- エラーケース（0件結果、販売終了品、重複登録）の処理が正常
- `npm run build` 成功

---

## 制約（厳守）

1. **FK禁止**: 全テーブルで外部キーを設定しない
2. **破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` は絶対禁止
3. **照合順序**: `utf8mb4_general_ci` を明示指定
4. **Filament 4パターン**: `Filament\Actions\Action` を使用、`Filament\Tables\Actions\Action` は使わない
5. **モーダルデザイン**: ヘッダー紺色（#1e293b）、ボタン右寄せ、キャンセル「発注せず閉じる」
6. **検索**: 全角→半角変換 `mb_convert_kana($search, 'as')` 適用
7. **パフォーマンス**: ページネーション必須、N+1回避、select * 禁止
8. **WmsModelベースクラス**: 新規モデルは `WmsModel` を継承（sakemaru接続）

## 全体完了条件

- P1〜P5 全て完了
- `php artisan migrate:status` で新規テーブル2つが Ran
- `php artisan wms:sync-sales-summaries` が正常実行
- 発注候補・移動候補の追加モーダルが新UIで動作
- `npm run build` 成功
- `php -l` 全ファイル構文エラーなし
