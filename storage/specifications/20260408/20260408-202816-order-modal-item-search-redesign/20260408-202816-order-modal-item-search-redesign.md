# 発注・移動候補追加モーダル 商品検索リデザイン

- **作成日**: 2026-04-08
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260408/20260408-202816-order-modal-item-search-redesign/`

## 背景・目的

現在の発注候補・移動候補の手動追加モーダルは、商品コード/名前/JANコードの単一テキスト検索のみで、1行ずつ商品を検索→選択→数量入力するフロー。大量の商品を追加する場合にUXが悪い。

**改善目標:**
1. 出荷実績サマリ（`stats_item_warehouse_sales_summaries`）を活用し、倉庫ごとの出荷実績を即座に確認可能にする
2. 多軸検索（商品名、商品コード、JANコード、発注先、大/中/小分類、最終出荷日、最終入荷日）でフィルタリング
3. 検索結果リストに出荷実績（3日/7日）を表示し、発注判断を支援
4. 既存発注候補の数量をプリセット表示し、重複発注を防止

## 現状の実装

### 発注候補追加モーダル（`ListWmsOrderCandidates.php`）
- ヘッダーアクション「発注追加」→ モーダル幅5xl
- 発注倉庫選択 → 入荷倉庫自動表示 → 入荷予定日
- 商品テーブル: `order-candidate-create-items.blade.php`（Alpine.js + Livewire）
- 検索: `searchItemsForOrderCreate()` — 商品コード/名前/JAN、2文字以上、limit(20)
- 選択後に1行ずつケース数量・バラ数量を入力

### 移動候補追加モーダル（`ListWmsStockTransferCandidates.php`）
- ヘッダーアクション「移動発注」→ モーダル幅4xl
- 依頼倉庫 → 移動元倉庫 → 入荷予定日 → 配送コース
- 商品テーブル: `transfer-order-create-items.blade.php`
- 同様の検索UI、数量は単一カラム

### 関連テーブル
- `items`: code, name, packaging, capacity_case, item_category_id_1/2/3
- `item_search_informations`: JAN/検索コード（quantity_type, code_type, search_string）
- `item_contractors`: warehouse_id + item_id → contractor_id, supplier_id, safety_stock
- `item_categories`: 大/中/小分類マスタ
- `item_prices`: purchase_unit_price（バラ単価）, purchase_case_price（ケース単価）

## 変更内容

### 概要

発注・移動候補追加モーダルの商品入力部分を、「検索フィルタ付き商品リスト + 数量入力」形式にリデザインする。出荷実績サマリテーブルを新規作成し、検索結果に出荷実績を即座に表示する。

### 詳細設計

#### モーダルレイアウト（新）

```
┌─────────────────────────────────────────────────────────────────┐
│ 発注追加                                               [×閉じる] │
├─────────────────────────────────────────────────────────────────┤
│ 発注倉庫: [______▼]  入荷倉庫: 自動表示  入荷予定日: [____]     │
├─────────────────────────────────────────────────────────────────┤
│ ■ 商品検索フィルタ                                              │
│ 商品名/CD/JAN: [____________]  発注先: [______▼]               │
│ 大分類: [______▼]  中分類: [______▼]  小分類: [______▼]        │
│ 最終出荷日: [____]〜[____]   最終入荷日: [____]〜[____]        │
│                                                    [検索] [リセット] │
├─────────────────────────────────────────────────────────────────┤
│ ■ 検索結果（XX件）                                              │
│ ┌────┬──────┬─────┬────┬────┬────┬───┬───┬───┬───┐            │
│ │商品CD│商品名 │規格  │入数│発注先│最終出荷│3d │7d │ケース│バラ│            │
│ ├────┼──────┼─────┼────┼────┼────┼───┼───┼───┼───┤            │
│ │1234│○○酒造│720ml│12 │△△商店│04/05 │30│120│[_]│[_]│            │
│ │5678│□□焼酎│1.8L │6  │◇◇酒販│04/07 │ 5│ 25│[_]│[2]│ ← 既存候補│
│ └────┴──────┴─────┴────┴────┴────┴───┴───┴───┴───┘            │
│                                                                 │
│ ページネーション: [< 1 2 3 4 5 >]  表示件数: [25▼]             │
├─────────────────────────────────────────────────────────────────┤
│                                    [発注せず閉じる] [追加する]    │
└─────────────────────────────────────────────────────────────────┘
```

**ポイント:**
- 検索結果は倉庫ごとの商品マスタ（item_contractors経由）をベースに表示
- 出荷実績（3d/7d）は `stats_item_warehouse_sales_summaries` から取得
- ケース/バラ数量は検索結果の各行に直接入力可能
- 既に同バッチの発注候補が存在する商品は、その数量をプリセット表示
- ページネーション付き（1ページ25件程度）

#### DB変更

**新規テーブル: `stats_item_warehouse_sales_summaries`**

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

**データソース:** 日別出荷データから集計。集計コマンドを別途作成。

#### モデル変更

**新規: `app/Models/StatsItemWarehouseSalesSummary.php`**
```php
class StatsItemWarehouseSalesSummary extends WmsModel
{
    protected $table = 'stats_item_warehouse_sales_summaries';
    protected $fillable = [
        'warehouse_id', 'item_id',
        'last_3d_qty', 'last_7d_qty', 'last_14d_qty', 'last_30d_qty',
        'avg_3d_qty', 'avg_7d_qty', 'avg_14d_qty', 'avg_30d_qty',
        'last_shipped_at', 'calculated_at',
    ];
    protected $casts = [
        'last_shipped_at' => 'date',
        'calculated_at' => 'datetime',
    ];

    public function item(): BelongsTo { ... }
    public function warehouse(): BelongsTo { ... }
}
```

#### サービス変更

**新規: `app/Console/Commands/Stats/SyncSalesSummariesCommand.php`**
- 日別出荷データ（出荷元テーブル要確認）からN日分の出荷数量を集計
- `stats_item_warehouse_sales_summaries` に upsert
- スケジューラーで毎日実行

#### UI変更

**変更: `ListWmsOrderCandidates.php`**
- ヘッダーアクション「発注追加」のスキーマをリデザイン
- 既存の `searchItemsForOrderCreate()` を拡張 → 多軸検索メソッドに変更
- 新規メソッド: `searchItemsForModal($filters)` — 検索フィルタを受け取り、ページネーション対応の結果を返す
- 検索結果に出荷実績サマリを JOIN

**変更: `order-candidate-create-items.blade.php`**
- 現在の1行ずつ検索→選択UIを廃止
- 検索フィルタフォーム + 検索結果テーブル + 数量入力カラムに変更
- Alpine.js でフィルタ変更→Livewire検索→テーブル描画

**変更: `ListWmsStockTransferCandidates.php`**
- 同様のリデザイン（移動候補版）

**変更: `transfer-order-create-items.blade.php`**
- 同様のリデザイン（数量は単一カラム）

### 影響範囲

| 対象 | 影響 |
|------|------|
| 発注候補手動追加 | モーダルUI全面変更 |
| 移動候補手動追加 | モーダルUI全面変更 |
| 日次バッチ処理 | サマリ集計コマンド追加 |
| Livewire通信 | 検索メソッドのシグネチャ変更 |

## 制約

- FK禁止: `stats_item_warehouse_sales_summaries` に外部キーは設定しない
- `migrate:fresh`/`migrate:refresh` 禁止
- 検索パフォーマンス: ページネーション必須（item_contractorsは数万件）
- 全角→半角変換: `mb_convert_kana($search, 'as')` を検索時に適用
- モーダルデザイン: `storage/specifications/20260311/modal-design/spec.md` に準拠
- 照合順序: `utf8mb4_general_ci` を指定（既存テーブルとの不一致対策）

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_create_stats_item_warehouse_sales_summaries_table.php`
- `app/Models/StatsItemWarehouseSalesSummary.php`
- `app/Console/Commands/Stats/SyncSalesSummariesCommand.php`

### 既存変更
- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php`
- `resources/views/filament/components/order-candidate-create-items.blade.php`
- `app/Filament/Resources/WmsStockTransferCandidates/Pages/ListWmsStockTransferCandidates.php`
- `resources/views/filament/components/transfer-order-create-items.blade.php`

### 参照のみ
- `app/Models/Sakemaru/Item.php`
- `app/Models/Sakemaru/ItemContractor.php`
- `app/Models/Sakemaru/ItemSearchInformation.php`
- `app/Models/Sakemaru/ItemCategory.php`
- `app/Models/WmsOrderCandidate.php`
- `app/Models/WmsStockTransferCandidate.php`

## 確認事項

1. **出荷データソース**: `stats_item_warehouse_sales_summaries` の集計元となる日別出荷テーブルは何か？ 基幹システム側の `outgoing_details` / `shipping_details` 等のテーブル構造を確認する必要がある
stats_item_warehouse_daily_sales
3. **最終入荷日**: `incoming_schedules` または `wms_incoming_schedules` から取得するか？どのテーブルを参照するか要確認
これは対応しない。（検索項目に入れない）
3. **移動候補のケース/バラ分別**: 移動候補でもケース/バラ分別入力にするか、現行の単一数量のままか？
ケース・バラ分別する。移動候補で必要な数量をケースで割る。ケース＋あまりはバラで（自動計算時）
4. **検索結果の範囲**: item_contractors に登録されている商品のみか、全商品（items全件）か？
全商品。選択された倉庫のみ。
5. **サマリ集計タイミング**: 毎日何時に実行するか？ 出荷データの確定タイミングとの兼ね合い
日時更新時
6. **既存候補のプリセット表示**: 同一バッチコードの候補のみか、全PENDINGの候補数量を表示するか？
全ペンディング
