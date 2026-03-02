# 月別発注点CSVインポート：発注点分析CSVフォーマット対応

- **作成日**: 2026-03-01
- **ステータス**: ドラフト
- **ディレクトリ**: storage/specifications/ordering/20260301-fix-monthly-safety-stock-csv-import/

## 背景・目的

HanaDBTransferの発注点分析結果CSV（`store_item_order_points.csv`）を `admin/wms-monthly-safety-stocks` 画面からインポートしたいが、CSVフォーマットが現行インポーターの期待する形式と完全に異なるためエラーになっている。

### エラーの原因

現行インポーターは以下のフォーマットを期待:
```
item_code,warehouse_code,contractor_code,month,safety_stock
```

しかし、発注点分析CSVは以下のフォーマット:
```
store_code,item_code,avg_daily_sales,std_daily_sales,avg_daily_orders,lead_time_days,safety_stock,order_point,...
```

インポーターが `$cols[0]`（=`store_code` の値 `1`）を `item_code` として解釈し、全行で「商品コード 1 が見つかりません」エラーが発生。

### 問題の構造

| 項目 | 現行インポート期待値 | 分析CSV | 差異 |
|------|---------------------|---------|------|
| 1列目 | `item_code` | `store_code` | フィールド自体が異なる |
| 2列目 | `warehouse_code` | `item_code` | フィールド自体が異なる |
| 3列目 | `contractor_code` | `avg_daily_sales` | **分析CSVに存在しない** |
| 4列目 | `month` | `std_daily_sales` | **分析CSVに存在しない** |
| 5列目 | `safety_stock` | `avg_daily_orders` | 別の列（7列目）に存在 |

## 現状の実装

### インポートジョブ
- **ファイル**: `app/Jobs/ImportMonthlySafetyStocksCsvJob.php`
- 5カラム固定（`item_code,warehouse_code,contractor_code,month,safety_stock`）
- マスタデータは `items.code`, `warehouses.code`, `contractors.code` でルックアップ
- 1000行ごとにトランザクションでチャンク処理

### UI（ListPage）
- **ファイル**: `app/Filament/Resources/WmsMonthlySafetyStocks/Pages/ListWmsMonthlySafetyStocks.php`
- `CSVインポート` アクション（FileUpload + Job dispatch）
- `テンプレート` ダウンロードアクション

### データ関連
- `store_code` → `warehouses.code` のマッピングは `ret_stores.code = warehouses.code` で成立
- `item_code` → `items.code` で商品ID取得
- `contractor_id` は `item_contractors` テーブルから `(item_id, warehouse_id)` で逆引き可能
- `month` は分析CSVに存在しない → UI側でユーザーが指定する or 全月(1-12)一括適用

## 変更内容

### 概要

発注点分析CSVフォーマットに対応する専用インポートアクション「発注点分析CSVインポート」を追加する。既存の5カラム形式インポートは維持し、分析CSV用に別アクションを用意する。

### 詳細設計

#### 新しいインポートフロー

1. ユーザーがUIで以下を指定:
   - CSVファイル（発注点分析CSV形式）
   - **対象月**（1-12のセレクト or 「全月一括」）
   - **倉庫**（セレクト。CSVの `store_code` → `warehouses.code` で自動解決も可能だが、明示指定が安全）
   - **インポート値**: `order_point`（発注点）or `safety_stock`（安全在庫）どちらの列を使うか

2. インポートジョブの処理:
   - CSVの `item_code`（2列目）で `items.code` → `item_id` を取得
   - 指定された倉庫の `warehouse_id` を使用
   - `item_contractors` テーブルから `(item_id, warehouse_id)` で `contractor_id` を逆引き
   - 指定された月（or 全月）に対して `wms_monthly_safety_stocks` を upsert
   - 値は `order_point`（8列目）or `safety_stock`（7列目）を使用（小数は四捨五入で整数化）

#### 分析CSVカラムマッピング

```
Index | CSV Header          | 用途
------|---------------------|---------------------------
0     | store_code          | → warehouse_code として解決（または無視してUI指定を使用）
1     | item_code           | → items.code でルックアップ
2     | avg_daily_sales     | 参照のみ（インポート不要）
3     | std_daily_sales     | 参照のみ（インポート不要）
4     | avg_daily_orders    | 参照のみ（インポート不要）
5     | lead_time_days      | 参照のみ（インポート不要）
6     | safety_stock        | ← インポート候補値①（安全在庫）
7     | order_point         | ← インポート候補値②（発注点）
8     | total_sales_qty_2y  | 参照のみ（インポート不要）
9     | total_order_qty_2y  | 参照のみ（インポート不要）
10    | sales_days_count    | 参照のみ（インポート不要）
```

#### DB変更

なし（既存の `wms_monthly_safety_stocks` テーブルをそのまま使用）

#### モデル変更

なし

#### サービス変更

##### 新規: `app/Jobs/ImportOrderPointAnalysisCsvJob.php`

```php
class ImportOrderPointAnalysisCsvJob implements ShouldQueue
{
    public function __construct(
        protected string $filePath,
        protected int $importLogId,
        protected int $warehouseId,
        protected array $months,        // [1] or [1,2,...,12]
        protected string $valueColumn,  // 'order_point' or 'safety_stock'
    ) {}

    protected function processFile(string $fullPath, WmsImportLog $importLog): array
    {
        // マスタデータキャッシュ
        $items = Item::query()->pluck('id', 'code');

        // item_contractors キャッシュ（warehouse_id + item_id → contractor_id）
        $itemContractors = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $this->warehouseId)
            ->pluck('contractor_id', 'item_id');

        // CSV解析 (ヘッダースキップ、BOM除去、エンコーディング変換は既存と同様)
        foreach ($lines as $lineIndex => $line) {
            $cols = str_getcsv($line);

            // item_code は2列目（index 1）
            $itemCode = trim($cols[1]);
            $itemId = $items[$itemCode] ?? null;

            if (!$itemId) {
                $errors[] = "行{$lineNum}: 商品コード {$itemCode} が見つかりません";
                continue;
            }

            // contractor_id は item_contractors から逆引き
            $contractorId = $itemContractors[$itemId] ?? null;
            if (!$contractorId) {
                $errors[] = "行{$lineNum}: 商品 {$itemCode} の発注先が未設定です";
                continue;
            }

            // 値の取得（order_point=index7, safety_stock=index6）
            $valueIndex = $this->valueColumn === 'order_point' ? 7 : 6;
            $value = max(0, (int) round((float) $cols[$valueIndex]));

            // 指定月ごとに upsert
            foreach ($this->months as $month) {
                WmsMonthlySafetyStock::updateOrCreate(
                    [
                        'item_id' => $itemId,
                        'warehouse_id' => $this->warehouseId,
                        'contractor_id' => $contractorId,
                        'month' => $month,
                    ],
                    ['safety_stock' => $value]
                );
            }
        }
    }
}
```

#### UI変更

##### `ListWmsMonthlySafetyStocks.php` に新しいアクション追加

```php
Action::make('importAnalysisCsv')
    ->label('発注点分析CSVインポート')
    ->icon('heroicon-o-calculator')
    ->color('warning')
    ->schema([
        FileUpload::make('csv_file')
            ->label('発注点分析CSVファイル')
            ->acceptedFileTypes(['text/csv', 'text/plain'])
            ->required()
            ->disk('local')
            ->directory('csv-imports')
            ->helperText('HanaDB発注点分析CSV（store_code,item_code,...,safety_stock,order_point,...）'),

        Select::make('warehouse_id')
            ->label('対象倉庫')
            ->options(Warehouse::pluck('name', 'id'))
            ->required()
            ->helperText('インポート先の倉庫を選択'),

        Select::make('month_mode')
            ->label('対象月')
            ->options([
                'all' => '全月（1〜12月）一括',
                'single' => '特定の月のみ',
            ])
            ->required()
            ->default('all')
            ->reactive(),

        Select::make('month')
            ->label('月')
            ->options(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => $m.'月']))
            ->visible(fn (callable $get) => $get('month_mode') === 'single')
            ->requiredIf('month_mode', 'single'),

        Select::make('value_column')
            ->label('インポートする値')
            ->options([
                'order_point' => '発注点（order_point）',
                'safety_stock' => '安全在庫（safety_stock）',
            ])
            ->default('order_point')
            ->required()
            ->helperText('発注点 = リードタイム × 平均日販 + 安全在庫'),
    ])
    ->action(function (array $data) {
        $months = $data['month_mode'] === 'all'
            ? range(1, 12)
            : [(int) $data['month']];

        $importLog = WmsImportLog::create([...]);

        ImportOrderPointAnalysisCsvJob::dispatch(
            $data['csv_file'],
            $importLog->id,
            (int) $data['warehouse_id'],
            $months,
            $data['value_column'],
        );
    }),
```

### 影響範囲

| ファイル | 影響 |
|---------|------|
| `ListWmsMonthlySafetyStocks.php` | 新アクション追加（既存アクションは変更なし）|
| `ImportMonthlySafetyStocksCsvJob.php` | **変更なし**（既存フォーマットは維持）|
| `wms_monthly_safety_stocks` テーブル | 大量データが upsert される（119,266行 × 12月 = 最大1,431,192行）|

### パフォーマンス考慮

- 119,266行 × 12月 = 最大1,431,192件の upsert
- チャンクサイズは1000行を維持
- `item_contractors` キャッシュを事前ロードしてN+1を防止
- `updateOrCreate` の代わりに `upsert()` の一括実行も検討（パフォーマンス重視の場合）

## 制約

1. **FK禁止**: `wms_monthly_safety_stocks` テーブルにはFKを追加しない
2. **migrate:fresh禁止**: DB破壊コマンドは使用しない
3. **既存機能維持**: 現行の5カラム形式CSVインポートは変更しない
4. **負の発注点**: 分析CSVに負の値が存在する（例: `item_code=100001` の `avg_daily_sales=-2.02`）→ `safety_stock` は `max(0, ...)` で0にクランプ
5. **item_contractors未設定商品**: 発注先が設定されていない商品はスキップしてエラーログに記録

## 対象ファイル

### 新規作成
- `app/Jobs/ImportOrderPointAnalysisCsvJob.php` — 発注点分析CSV用インポートジョブ

### 既存変更
- `app/Filament/Resources/WmsMonthlySafetyStocks/Pages/ListWmsMonthlySafetyStocks.php` — 新アクション追加

### 参照のみ
- `app/Jobs/ImportMonthlySafetyStocksCsvJob.php` — 既存インポートジョブ（変更なし、パターン参照）
- `app/Models/WmsMonthlySafetyStock.php` — モデル定義
- `app/Models/WmsImportLog.php` — インポートログ
- `app/Models/Sakemaru/Item.php` — 商品マスタ
- `app/Models/Sakemaru/Warehouse.php` — 倉庫マスタ
- `app/Models/Sakemaru/Contractor.php` — 発注先マスタ
- `app/Models/Sakemaru/ItemContractor.php` — 商品-発注先マスタ

## 確認事項

1. **インポート値の選択**: `order_point`（発注点）と `safety_stock`（安全在庫）のどちらをデフォルトで使うべきか？
   - `order_point` = リードタイム日数 × 平均日販 + 安全在庫（推奨: こちらが発注トリガー値）
   - `safety_stock` = 標準偏差ベースの安全在庫のみ

2. **月の適用方針**: 全月一括で同じ値を入れるか、特定の月だけに入れるか？
   - 全月一括の場合、季節変動が反映されない点に注意
   - 月別に異なるCSVが存在する場合は単月指定が適切

3. **store_code → warehouse マッピング**: `store_code=1` に対応する `warehouse` は固定値か？
   - UI側で倉庫を明示選択させる設計としたが、CSVの `store_code` から自動解決するべきか？

4. **item_contractors に複数レコード**: 同一 `(item_id, warehouse_id)` で複数の発注先が存在する場合の挙動
   - 最初にヒットしたものを使用？全てに適用？エラーにする？

5. **大量データのパフォーマンス**: 119,266行の処理にかかる時間
   - `updateOrCreate` × 12月 = 最大1.4M件 → `upsert()` 一括実行に変更すべきか？
