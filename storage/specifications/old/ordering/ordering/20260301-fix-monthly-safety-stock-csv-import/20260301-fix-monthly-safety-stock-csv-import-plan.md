# 月別発注点CSVインポート：発注点分析CSV対応 作業計画

## 前提

- 現行の `ImportMonthlySafetyStocksCsvJob` は5カラム形式（`item_code,warehouse_code,contractor_code,month,safety_stock`）のみ対応
- HanaDB分析CSV（`store_item_order_points.csv`）は11カラム形式で、フォーマットが完全に異なる
- 現行ジョブは変更せず、新しいジョブを追加する方針
- ブランチ: `feature/ordering-update`（既存）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | ImportOrderPointAnalysisCsvJob作成 | 分析CSV解析→upsertするJobクラスを新規作成 | Jobクラスが作成され、コードフォーマットが通る |
| P2 | ListPage UIアクション追加 | Filament画面に「発注点分析CSVインポート」アクションを追加 | アクションがUIに表示される |
| P3 | 動作確認 | 実際のCSVでインポートを実行し結果を確認 | データが正しくインポートされる |

---

## P1: ImportOrderPointAnalysisCsvJob作成

### 目的

HanaDB発注点分析CSVフォーマットを解析し、`wms_monthly_safety_stocks` テーブルにupsertする非同期ジョブを作成する。

### 作成ファイル

`app/Jobs/ImportOrderPointAnalysisCsvJob.php`

### 設計詳細

#### コンストラクタ

```php
public function __construct(
    protected string $filePath,      // Storageパス
    protected int $importLogId,       // WmsImportLog ID
    protected int $warehouseId,       // 対象倉庫ID（UI指定）
    protected array $months,          // [1] or [1,2,...,12]
    protected string $valueColumn,    // 'order_point' or 'safety_stock'
)
```

#### 処理フロー

1. **ファイル読み込み**: BOM除去 → Shift-JIS→UTF-8変換 → 改行で分割
2. **ヘッダースキップ**: 1行目を除外
3. **マスタデータキャッシュ**:
   - `$items = Item::query()->pluck('id', 'code');`
   - `$itemContractors = DB::connection('sakemaru')->table('item_contractors')->where('warehouse_id', $this->warehouseId)->get()->groupBy('item_id');`
     - 同一 (item_id, warehouse_id) に複数contractor がある場合、全てに適用するため `groupBy` を使用
4. **チャンク処理** (1000行/チャンク):
   - `$cols = str_getcsv($line)`
   - `$itemCode = trim($cols[1])` （index 1 が item_code）
   - `$itemId = $items[$itemCode] ?? null` → なければエラー記録してスキップ
   - `$contractorRows = $itemContractors[$itemId] ?? collect()` → 空ならエラー記録してスキップ
   - `$valueIndex = ($this->valueColumn === 'order_point') ? 7 : 6`
   - `$value = max(0, (int) round((float) $cols[$valueIndex]))`
   - 各 contractor × 各 month に対して upsert バッファに追加
5. **バッチ upsert** (500件ごと):
   ```php
   WmsMonthlySafetyStock::upsert(
       $upsertBuffer,
       ['item_id', 'warehouse_id', 'contractor_id', 'month'], // unique key
       ['safety_stock', 'updated_at']                          // update columns
   );
   ```
6. **完了処理**: `$importLog->markAsCompleted(...)` でインポートログ更新

#### エラーハンドリング

- カラム数不足（11カラム未満）→ エラー記録してスキップ
- item_code が items に存在しない → エラー記録してスキップ
- item_contractors に該当レコードなし → エラー記録してスキップ
- 例外発生 → トランザクションロールバック、`$importLog->markAsFailed()`

#### パターン参照

`ImportMonthlySafetyStocksCsvJob.php` と同じ構造を踏襲:
- `ShouldQueue` 実装
- `tries = 1`, `timeout = 1800`
- `onQueue('default')`
- `ini_set('memory_limit', '-1')`
- BOM除去 + エンコーディング変換
- ファイル削除（finally句）

### 完了条件

- [ ] `app/Jobs/ImportOrderPointAnalysisCsvJob.php` が作成されている
- [ ] `./vendor/bin/pint app/Jobs/ImportOrderPointAnalysisCsvJob.php` がエラーなく通る
- [ ] `php artisan tinker` で `new \App\Jobs\ImportOrderPointAnalysisCsvJob(...)` がインスタンス化できる

---

## P2: ListPage UIアクション追加

### 目的

`ListWmsMonthlySafetyStocks` ページに「発注点分析CSVインポート」アクションを追加する。

### 修正ファイル

`app/Filament/Resources/WmsMonthlySafetyStocks/Pages/ListWmsMonthlySafetyStocks.php`

### 設計詳細

#### 追加するアクション

既存の `importCsv` アクションと `downloadTemplate` アクションの間に、新しいアクションを挿入:

```php
Action::make('importAnalysisCsv')
    ->label('発注点分析CSVインポート')
    ->icon('heroicon-o-calculator')
    ->color('warning')
    ->schema([...])
    ->action(function (array $data) { ... })
```

#### モーダルフォーム構成

1. **FileUpload** `csv_file` — CSVファイルアップロード
   - acceptedFileTypes: `['text/csv', 'text/plain', 'application/vnd.ms-excel']`
   - maxSize: 512000 (500MB)
   - disk: local, directory: csv-imports

2. **Select** `warehouse_id` — 対象倉庫
   - options: `Warehouse::pluck('name', 'id')`
   - required

3. **Select** `month_mode` — 対象月モード
   - options: `['all' => '全月（1〜12月）一括', 'single' => '特定の月のみ']`
   - default: `'all'`
   - live (reactive)

4. **Select** `month` — 月（month_mode === 'single' のときのみ表示）
   - options: 1月〜12月
   - visible: `fn (callable $get) => $get('month_mode') === 'single'`

5. **Select** `value_column` — インポートする値
   - options: `['order_point' => '発注点（order_point）', 'safety_stock' => '安全在庫（safety_stock）']`
   - default: `'order_point'`

#### アクション処理

```php
->action(function (array $data) {
    $months = $data['month_mode'] === 'all'
        ? range(1, 12)
        : [(int) $data['month']];

    $importLog = WmsImportLog::create([
        'type' => WmsImportLog::TYPE_MONTHLY_SAFETY_STOCKS,
        'status' => WmsImportLog::STATUS_PENDING,
        'file_name' => basename($data['csv_file']),
        'user_id' => auth()->id(),
    ]);

    ImportOrderPointAnalysisCsvJob::dispatch(
        $data['csv_file'],
        $importLog->id,
        (int) $data['warehouse_id'],
        $months,
        $data['value_column'],
    );

    Notification::make()
        ->title('発注点分析CSVインポートを開始しました')
        ->body('バックグラウンドで処理中です。')
        ->info()
        ->send();
})
```

#### 必要な use 文追加

```php
use App\Jobs\ImportOrderPointAnalysisCsvJob;
use App\Models\Sakemaru\Warehouse;
use Filament\Forms\Components\Select;
```

### 完了条件

- [ ] 新しいアクション「発注点分析CSVインポート」がUIに表示される
- [ ] モーダルフォームが正しく表示される（倉庫選択、月モード、値選択）
- [ ] `month_mode` の切り替えで月セレクトの表示/非表示が動作する
- [ ] `./vendor/bin/pint` がエラーなく通る

---

## P3: 動作確認

### 目的

実際のCSVファイルでインポートを実行し、データが正しく登録されることを確認する。

### 手順

1. **キューワーカー起動確認**
   ```bash
   # composer dev で起動中であれば不要
   php artisan queue:work --once
   ```

2. **テストCSVでインポート実行**
   - ブラウザで `admin/wms-monthly-safety-stocks` を開く
   - 「発注点分析CSVインポート」ボタンをクリック
   - CSVファイル: `store_item_order_points.csv` をアップロード
   - 倉庫: 適切な倉庫を選択
   - 対象月: 「特定の月のみ」→ 3月 を選択（テスト用に1月分だけ）
   - インポートする値: 「発注点（order_point）」
   - 実行

3. **結果確認**
   ```bash
   # ログ確認
   tail -f storage/logs/laravel.log | grep -i "発注点"

   # DB確認（tinkerまたはUIで）
   # wms_monthly_safety_stocks に month=3 のデータが入っているか
   # wms_import_logs の最新レコードのステータスとエラー数
   ```

4. **エラーケースの確認**
   - items テーブルに存在しない item_code のエラーログ
   - item_contractors に未設定の商品のエラーログ
   - 負の値が0にクランプされているか

### 完了条件

- [ ] インポートが正常完了し、`wms_import_logs` のステータスが `completed`
- [ ] `wms_monthly_safety_stocks` に期待するデータが登録されている
- [ ] エラー行が適切にログに記録されている
- [ ] 既存の「CSVインポート」アクション（5カラム形式）が引き続き動作する

---

## 制約（厳守）

1. **DB破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` は絶対に実行しない
2. **FK禁止**: テーブルにFKを追加しない
3. **既存ジョブ変更禁止**: `ImportMonthlySafetyStocksCsvJob.php` は一切変更しない
4. **負の値クランプ**: `max(0, ...)` で0以上にする
5. **Filament 4パターン遵守**:
   - `use Filament\Actions\Action` （NOT `Filament\Tables\Actions\Action`）
   - モーダルフォームは `->schema([...])` （NOT `->form([...])`）
   - `use Filament\Schemas\Components\Section` （セクション使用時）

## 全体完了条件

1. 新規ジョブ `ImportOrderPointAnalysisCsvJob` が作成され、分析CSVを正しくパース・インポートできる
2. UIに「発注点分析CSVインポート」アクションが追加され、倉庫・月・値の選択が可能
3. 既存の「CSVインポート」アクション（5カラム形式）に影響がない
4. `./vendor/bin/pint` がエラーなく通る
