# ピッキングリスト生成機能

- **作成日**: 2026-03-12
- **ステータス**: ドラフト
- **ディレクトリ**: `/Users/jungsinyu/Projects/sakemaru-wms`

## 背景・目的

倉庫出荷業務において、3種類のピッキングリストを生成する機能を開発する。

現状、Wave生成→在庫引当→ピッキングタスク作成→ピッキング実行の流れは実装済みだが、**帳票としてのピッキングリスト**（PDF/印刷用）の生成機能が未実装。現在は`PrintRequestService`による納品伝票印刷のみ対応している。

本機能では以下3種類のピッキングリストを新規開発する：

| # | リスト名 | 目的 | 単位 |
|---|---------|------|------|
| 1次 | Wave集約リスト | 作業量把握（管理者向け） | Wave × SKU |
| 2次 | ゾーン・作業者別実行リスト | ピッカー作業指示 | ピッキングタスク × 棚番 × SKU |
| 3次 | 納品先別仕分けリスト | 仕分け・検品 | 納品先 × SKU |

## 現状の実装

### データフロー（既存）

```
earnings/stock_transfers (出荷対象伝票)
  → Wave生成 (ListWaves::generateManualWave)
    → 在庫引当 (StockAllocationService::allocateForItem)
      → wms_reservations 作成
      → wms_picking_tasks 作成 (フロア・エリア別)
        → wms_picking_item_results 作成 (商品×棚番単位)
          → ピッカー割当 (AssignPickersToTasksService)
            → ピッキング実行 (ExecuteWmsPickingTask)
              → 印刷依頼 (PrintRequestService) ← 納品伝票のみ
```

### 関連テーブル

| テーブル | 用途 | 主キー |
|---------|------|--------|
| `wms_waves` | Wave管理 | id |
| `wms_picking_tasks` | ピッキングタスク（配送コース×フロア別） | id |
| `wms_picking_item_results` | ピッキング明細（商品×棚番単位） | id |
| `wms_reservations` | 在庫引当 | id |
| `wms_pickers` | ピッカー情報 | id |
| `wms_picking_areas` | ピッキングエリア | id |
| `earnings` | 売上伝票（=出荷指示） | id |
| `trade_items` | 伝票明細 | id |
| `items` | 商品マスタ | id |
| `locations` | 棚番マスタ（code1/code2/code3） | id |
| `real_stocks` | 実在庫 | id |
| `delivery_courses` | 配送コース | id |
| `partners` | 取引先（納品先=buyer） | id |

### 既存サービス・モデル

- `app/Services/WaveService.php` — Wave検索・生成
- `app/Services/StockAllocationService.php` — FEFO→FIFO在庫引当
- `app/Services/Picking/PickRouteService.php` — A*動線最適化
- `app/Services/Picking/AssignPickersToTasksService.php` — ピッカー割当
- `app/Services/Print/PrintRequestService.php` — 納品伝票印刷依頼
- `app/Models/Wave.php` — Wave（status: PENDING→PICKING→SHORTAGE→COMPLETED→CLOSED）
- `app/Models/WmsPickingTask.php` — タスク（status: PENDING→PICKING_READY→PICKING→COMPLETED→SHIPPED）
- `app/Models/WmsPickingItemResult.php` — ピッキング明細（earning_id, trade_id, trade_item_id, item_id, location_id, planned_qty等）

## 変更内容

### 概要

3種類のピッキングリストを生成するサービスとPDFレイアウトを新規作成し、Wave一覧画面およびピッキングタスク画面から印刷・ダウンロードできるようにする。

### Phase 0: DB分析

実装前に以下のDB分析を実行し、レポートを作成する。

#### テーブル調査

対象テーブル: `earnings`, `trade_items`, `items`, `real_stocks`, `locations`, `wms_picking_tasks`, `wms_picking_item_results`, `wms_reservations`, `delivery_courses`, `partners`, `wms_picking_areas`, `wms_pickers`

各テーブルについて以下を確認:
- レコード件数、インデックス、NULL率、データ偏り
- 更新頻度（トランザクション量）

#### 出荷量分析

```sql
-- SKU別出荷頻度（直近30日）
SELECT ti.item_id, i.code, i.name, COUNT(*) as frequency, SUM(ti.quantity) as total_qty
FROM trade_items ti
JOIN items i ON ti.item_id = i.id
JOIN earnings e ON ti.trade_id = e.trade_id
WHERE e.delivered_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY ti.item_id ORDER BY frequency DESC;

-- 配送コース別件数
SELECT dc.id, dc.code, dc.name, COUNT(DISTINCT e.id) as earning_count
FROM earnings e
JOIN delivery_courses dc ON e.delivery_course_id = dc.id
WHERE e.delivered_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY dc.id ORDER BY earning_count DESC;

-- Wave単位の平均/最大SKU数・行数
SELECT w.id, w.wave_no,
  COUNT(DISTINCT pir.item_id) as sku_count,
  COUNT(pir.id) as line_count
FROM wms_waves w
JOIN wms_picking_tasks pt ON w.id = pt.wave_id
JOIN wms_picking_item_results pir ON pt.id = pir.picking_task_id
GROUP BY w.id;
```

#### 動線分析

棚番コード `locations.code1/code2/code3` を解析し:
- ゾーン分割ルール（code1 = エリア、code2 = 通路、code3 = 棚段 等）
- 棚番ソート規則（既存 `PickRouteService` のA*アルゴリズムと整合）
- `wms_picking_item_results.walking_order` の分布確認

### Phase 1: 1次ピッキングリスト（Wave集約リスト）

#### 目的
Wave単位で出荷対象SKUを集約し、倉庫管理者が作業量を把握する。

#### データ取得SQL

```sql
SELECT
  pir.item_id,
  i.code AS item_code,
  i.name AS item_name,
  SUM(pir.planned_qty) AS total_qty,
  -- ケース/バラ換算
  CASE WHEN i.capacity_case > 0
    THEN FLOOR(SUM(pir.planned_qty) / i.capacity_case)
    ELSE 0
  END AS case_qty,
  CASE WHEN i.capacity_case > 0
    THEN MOD(SUM(pir.planned_qty), i.capacity_case)
    ELSE SUM(pir.planned_qty)
  END AS piece_qty,
  pa.name AS picking_zone,
  COUNT(DISTINCT COALESCE(pir.earning_id, pir.stock_transfer_id)) AS destination_count,
  -- 欠品予定数量
  SUM(pir.shortage_qty) AS shortage_qty
FROM wms_picking_item_results pir
JOIN wms_picking_tasks pt ON pir.picking_task_id = pt.id
JOIN items i ON pir.item_id = i.id
LEFT JOIN wms_picking_areas pa ON pt.wms_picking_area_id = pa.id
WHERE pt.wave_id = :wave_id
GROUP BY pir.item_id, i.code, i.name, pa.name
ORDER BY pa.display_order, i.code;
```

#### 出力項目

| 項目 | ソース | 備考 |
|------|--------|------|
| 商品CD | items.code | |
| 商品名 | items.name | |
| 総出荷数量 | SUM(planned_qty) | |
| ケース数 | FLOOR(総数 / capacity_case) | capacity_case=0の場合は0 |
| バラ数 | MOD(総数, capacity_case) | capacity_case=0の場合は総数 |
| ピッキングゾーン | wms_picking_areas.name | |
| 納品先件数 | COUNT(DISTINCT earning_id/stock_transfer_id) | |
| 欠品予定数 | SUM(shortage_qty) | 0の場合は非表示 |

### Phase 2: 2次ピッキングリスト（ゾーン・作業者別実行リスト）

#### 目的
ピッカーが棚から商品を取得するための実行リスト。棚番順ソートで動線最適化。

#### データ構造

**ヘッダー**: ピッキングタスク単位
```
Wave番号 | 配送コース | ピッキングエリア | 担当ピッカー | 出荷日
```

**明細（親）**: 棚番×SKU単位（集約）
```sql
SELECT
  pir.location_id,
  CONCAT(l.code1, '-', l.code2, '-', l.code3) AS location_code,
  pir.item_id,
  i.code AS item_code,
  i.name AS item_name,
  SUM(pir.planned_qty) AS total_pick_qty,
  pir.planned_qty_type,
  pir.walking_order
FROM wms_picking_item_results pir
JOIN items i ON pir.item_id = i.id
LEFT JOIN locations l ON pir.location_id = l.id
WHERE pir.picking_task_id = :picking_task_id
GROUP BY pir.location_id, pir.item_id
ORDER BY pir.walking_order ASC, l.code1, l.code2, l.code3;
```

**明細（子）**: 納品先別内訳
```sql
SELECT
  pir.id,
  COALESCE(pir.earning_id, pir.stock_transfer_id) AS destination_source_id,
  pir.source_type,
  -- 売上の場合はbuyer_id、移動の場合はto_warehouse_id
  CASE
    WHEN pir.source_type = 'EARNING' THEN e.buyer_id
    WHEN pir.source_type = 'STOCK_TRANSFER' THEN st.to_warehouse_id
  END AS destination_id,
  CASE
    WHEN pir.source_type = 'EARNING' THEN p.name_store
    WHEN pir.source_type = 'STOCK_TRANSFER' THEN w.name
  END AS destination_name,
  pir.planned_qty,
  pir.planned_qty_type
FROM wms_picking_item_results pir
LEFT JOIN earnings e ON pir.earning_id = e.id
LEFT JOIN partners p ON e.buyer_id = p.id
LEFT JOIN stock_transfers st ON pir.stock_transfer_id = st.id
LEFT JOIN warehouses w ON st.to_warehouse_id = w.id
WHERE pir.picking_task_id = :picking_task_id
  AND pir.item_id = :item_id
  AND pir.location_id = :location_id
ORDER BY destination_name;
```

#### 出力項目

**親明細行**:

| 項目 | ソース |
|------|--------|
| 棚番 | locations.code1-code2-code3 |
| 商品CD | items.code |
| 商品名 | items.name |
| 総ピック数量 | SUM(planned_qty) |
| 数量区分 | ケース/バラ（QuantityType enum） |

**子明細行（納品先内訳）**:

| 項目 | ソース |
|------|--------|
| 納品先名 | partners.name_store / warehouses.name |
| 数量 | planned_qty |
| 数量区分 | ケース/バラ |

### Phase 3: 3次ピッキングリスト（納品先別仕分けリスト）

#### 目的
ピック済商品を納品先別に仕分け・検品する。

#### データ取得

```sql
-- 納品先別に完全分割
SELECT
  CASE
    WHEN pir.source_type = 'EARNING' THEN e.buyer_id
    WHEN pir.source_type = 'STOCK_TRANSFER' THEN st.to_warehouse_id
  END AS destination_id,
  CASE
    WHEN pir.source_type = 'EARNING' THEN p.name_store
    WHEN pir.source_type = 'STOCK_TRANSFER' THEN w.name
  END AS destination_name,
  dc.code AS course_code,
  dc.name AS course_name,
  pir.item_id,
  i.code AS item_code,
  i.name AS item_name,
  pir.planned_qty,
  pir.planned_qty_type,
  pir.picked_qty,
  pir.shortage_qty
FROM wms_picking_item_results pir
JOIN wms_picking_tasks pt ON pir.picking_task_id = pt.id
JOIN items i ON pir.item_id = i.id
JOIN delivery_courses dc ON pt.delivery_course_id = dc.id
LEFT JOIN earnings e ON pir.earning_id = e.id
LEFT JOIN partners p ON e.buyer_id = p.id
LEFT JOIN stock_transfers st ON pir.stock_transfer_id = st.id
LEFT JOIN warehouses w ON st.to_warehouse_id = w.id
WHERE pt.wave_id = :wave_id
ORDER BY course_code, destination_name, i.code;
```

#### 出力項目

| 項目 | ソース |
|------|--------|
| 配送コース | delivery_courses.code + name |
| 納品先名 | partners.name_store / warehouses.name |
| 商品CD | items.code |
| 商品名 | items.name |
| 予定数量 | planned_qty |
| ピック済数量 | picked_qty |
| 欠品数量 | shortage_qty |
| 数量区分 | ケース/バラ |

配送コース別にグループ化し、コース内で納品先別にページ分割。

### Phase 4: 例外処理設計

| 例外パターン | 対応方針 |
|-------------|---------|
| 欠品発生時 | `wms_shortages`テーブル参照。リスト上に欠品数量を表示。再配分後は再生成 |
| 在庫不足時のWave再計算 | Wave statusが`SHORTAGE`の場合、リスト上に警告表示。再引当後に再生成 |
| 棚番未設定商品 | `location_id IS NULL`の場合、棚番欄に「未設定」と表示。2次リストでは末尾に配置 |
| ケース割れ商品 | `capacity_case`が0またはNULLの場合、バラ数のみ表示 |
| ピック途中停止の再開 | 2次リストで`status=PICKING`の場合、ピック済数量を反映した残数リストとして再生成可能 |

### Phase 5: パフォーマンス設計

| 項目 | 対策 |
|------|------|
| 大量SKU（10万行以上） | チャンク処理（1000行/バッチ）、カーソルベースページネーション |
| 並列Wave生成 | Wave単位で独立処理、GET_LOCK不要（読み取りのみ） |
| 一時テーブル | 不要（既存テーブル集約で十分） |
| Lock最小化 | リスト生成は読み取り専用、ロック不要 |
| PDF生成 | LaravelのDomPDF or Snappy（既存印刷基盤に合わせる） |
| トランザクション | 読み取り専用のためREAD COMMITTEDで十分 |

### サービス設計

```php
namespace App\Services\PickingList;

class PickingListService
{
    // 1次ピッキングリスト（Wave集約）
    public function generatePrimaryList(int $waveId): array;

    // 2次ピッキングリスト（タスク別実行リスト）
    public function generateSecondaryList(int $pickingTaskId): array;

    // 3次ピッキングリスト（納品先別仕分け）
    public function generateTertiaryList(int $waveId): array;
}
```

### PDF生成

```php
namespace App\Services\PickingList;

class PickingListPdfService
{
    // 1次リストPDF
    public function renderPrimaryPdf(array $data): string;

    // 2次リストPDF
    public function renderSecondaryPdf(array $data): string;

    // 3次リストPDF
    public function renderTertiaryPdf(array $data): string;
}
```

### UI変更

#### Wave一覧画面（ListWaves.php）

`recordActions`に以下を追加:
- 「1次リスト印刷」ボタン — Wave単位でPDF生成
- 「3次リスト印刷」ボタン — Wave単位でPDF生成

#### ピッキングタスク一覧画面（ListWmsPickingTasks.php）

`recordActions`に以下を追加:
- 「2次リスト印刷」ボタン — タスク単位でPDF生成

### 帳票レイアウト案

#### 1次リスト（A4横）
```
┌─────────────────────────────────────────────────┐
│ [Wave番号] 1次ピッキングリスト  [出荷日] [印刷日時] │
│ [倉庫名] [配送コース名]                            │
├────┬──────┬─────┬────┬───┬───┬──────┬────┬────┤
│ No │商品CD│商品名│総数量│CS │バラ│ゾーン │店数│欠品│
├────┼──────┼─────┼────┼───┼───┼──────┼────┼────┤
│  1 │10001 │日本酒A│  120│  5│  0│常温1F │  8│   0│
│  2 │10002 │焼酎B  │   36│  1│ 12│常温1F │  3│   0│
│ ...│      │      │     │   │   │      │    │    │
├────┴──────┴─────┴────┴───┴───┴──────┴────┴────┤
│ 合計: SKU数 XX / 総数量 XXXX / ケース XX / バラ XX  │
└─────────────────────────────────────────────────┘
```

#### 2次リスト（A4縦）
```
┌──────────────────────────────────────┐
│ [Wave番号] 2次ピッキングリスト         │
│ [エリア名] [ピッカー名] [出荷日]       │
├──────┬──────┬──────┬────┬──────────┤
│ 棚番  │商品CD│商品名│数量│ □チェック  │
├──────┼──────┼──────┼────┼──────────┤
│A-01-1│10001 │日本酒A│ 24│ □          │
│      │      │  ├ 店舗X │ 12 (バラ)   │
│      │      │  └ 店舗Y │ 12 (バラ)   │
│A-02-3│10002 │焼酎B  │ 12│ □          │
│      │      │  └ 店舗Z │ 12 (バラ)   │
├──────┴──────┴──────┴────┴──────────┤
│ 合計: XX品目 / XX棚 / 総数量 XXXX    │
└──────────────────────────────────────┘
```

#### 3次リスト（A4縦）
```
┌──────────────────────────────────────┐
│ [配送コース名] 3次仕分けリスト         │
│ [納品先名] [出荷日]                    │
├──────┬──────┬──────┬────┬───┬────┤
│商品CD│商品名│予定数│済数│欠品│区分 │
├──────┼──────┼──────┼────┼───┼────┤
│10001 │日本酒A│   12│  12│  0│バラ │
│10002 │焼酎B  │    6│   6│  0│バラ │
├──────┴──────┴──────┴────┴───┴────┤
│ 合計: XX品目 / 総数量 XX            │
│ [検品者サイン欄]                     │
└──────────────────────────────────────┘
```

## 影響範囲

| ファイル | 変更種別 | 影響 |
|---------|---------|------|
| `ListWaves.php` | 変更 | recordAction追加（1次・3次リスト印刷ボタン） |
| `ListWmsPickingTasks.php` | 変更 | recordAction追加（2次リスト印刷ボタン） |
| `PrintRequestService.php` | 参照 | 既存の印刷フローを参考にするが変更なし |
| `PickRouteService.php` | 参照 | walking_order計算ロジック参照のみ |
| `Wave.php` | 参照 | Wave関連リレーション参照 |
| `WmsPickingTask.php` | 参照 | タスク関連リレーション参照 |
| `WmsPickingItemResult.php` | 参照 | 明細データ取得 |

## 制約

- **FK禁止**: 新規テーブル作成時は外部キー制約を使用しない
- **migrate:fresh/refresh禁止**: 本番共有DB
- **QuantityType enum使用**: ケース→「ケース」、バラ→「バラ」の表記統一
- **テーブルデザイン仕様**: 商品CDは「CD」表記、コードと名前は別カラム
- **Filament 4パターン**: `recordActions` + `Action`（`Filament\Actions\Action`）を使用
- **既存の在庫引当・ピッキングフローに影響を与えない**: リスト生成は読み取り専用

## 対象ファイル

### 新規作成

| ファイル | 用途 |
|---------|------|
| `app/Services/PickingList/PickingListService.php` | ピッキングリストデータ取得サービス |
| `app/Services/PickingList/PickingListPdfService.php` | PDF生成サービス |
| `resources/views/picking-lists/primary.blade.php` | 1次リストPDFテンプレート |
| `resources/views/picking-lists/secondary.blade.php` | 2次リストPDFテンプレート |
| `resources/views/picking-lists/tertiary.blade.php` | 3次リストPDFテンプレート |

### 既存変更

| ファイル | 変更内容 |
|---------|---------|
| `app/Filament/Resources/Waves/Pages/ListWaves.php` | 1次・3次リスト印刷アクション追加 |
| `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingTasks.php` | 2次リスト印刷アクション追加 |

### 参照のみ

| ファイル | 参照目的 |
|---------|---------|
| `app/Services/WaveService.php` | Wave生成ロジック理解 |
| `app/Services/StockAllocationService.php` | 在庫引当ロジック理解 |
| `app/Services/Picking/PickRouteService.php` | 動線最適化ロジック理解 |
| `app/Services/Print/PrintRequestService.php` | 既存印刷フロー参考 |
| `app/Models/Wave.php` | Waveモデル・リレーション |
| `app/Models/WmsPickingTask.php` | タスクモデル・リレーション |
| `app/Models/WmsPickingItemResult.php` | 明細モデル・リレーション |

## 確認事項

1. **PDF生成ライブラリ**: 既存プロジェクトでDomPDF or Snappyのどちらを使用しているか？ `PrintRequestQueue`の基盤に合わせるべきか、独立したPDF生成にするか？
2. **印刷先**: ピッキングリストは`client_printer_course_settings`のプリンターに送信するか、ブラウザダウンロードのみか？
3. **2次リストの印刷タイミング**: ピッカー割当前でも印刷可能にするか（ピッカー名が空の状態で印刷）？
4. **3次リストの表示タイミング**: ピッキング完了後のみ表示可能にするか、ピッキング前でも予定数量ベースで印刷可能にするか？
5. **用紙サイズ**: 1次リストはA4横、2次・3次はA4縦で良いか？
6. **Wave単位 vs 配送コース単位**: 3次リストは配送コース横断で1Waveまとめるか、配送コース別に分割するか？
7. **既存の`print_count`カラム**: `wms_waves.print_count`および`wms_picking_tasks.print_requested_count`をピッキングリスト印刷時にもインクリメントするか？
