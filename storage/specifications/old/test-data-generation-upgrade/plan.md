# テストデータ生成：在庫データ保存/読込機能 作業計画

## 前提

- `/admin/test-data-generator?activeTab=warehouse` タブに既存の5つのアクションがある
- `real_stocks` テーブル（在庫親）と `real_stock_lots` テーブル（ロット＝ロケーション・期限別）の2テーブル構成
- `available_quantity` は `real_stocks` 上の生成カラム（`current_quantity - reserved_quantity`）→ CSVインポート時に書き込み不要
- **S3ストレージ**: `Storage::disk('s3')` で `kura/` prefix配下に保存（`config/filesystems.php`）
- 既存のS3利用パターン: `OrderDataFileService` が `Storage::disk('s3')->put()` / `->temporaryUrl()` で保存・取得
- テスト環境限定機能のため、同期処理（Queue不使用）で十分

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | 在庫データ保存（S3エクスポート） | real_stocks + real_stock_lots を2つのCSVにしてS3に保存 | ボタン押下でS3にCSV保存、Notificationで完了表示 |
| P2 | 在庫データ読込（S3インポート） | S3に保存されたスナップショットを選択して在庫データを上書き復元 | 選択したスナップショットで在庫が完全復元 |
| P3 | UIカード追加・動作確認 | Bladeテンプレートに説明カード追加 | 倉庫タブに保存/読込の説明カードが表示される |

---

## P1: 在庫データ保存（S3エクスポート）

### 目的

現在の在庫状態をCSVとしてS3に保存する。テストを繰り返す際に初期状態に戻せるよう、スナップショットとしてサーバー上に保管する。

### 設計方針

**2つのCSVファイルをS3に保存** する方式を採用する。

- S3パス: `test-stock-snapshots/{timestamp}/real_stocks.csv` と `test-stock-snapshots/{timestamp}/real_stock_lots.csv`
- timestamp形式: `Ymd_His`（例: `20260218_143000`）
- ZIPは使わず、S3ディレクトリ（prefix）でグループ化

理由:
- `real_stocks` と `real_stock_lots` は1:N関係であり、1つのCSVにまとめると正規化が崩れて復元時に複雑になる
- S3ではprefix（ディレクトリ）でグループ管理できるのでZIPが不要
- 個別ファイルのほうがデバッグ時に確認しやすい

### CSVフォーマット

#### real_stocks.csv
```
id,client_id,warehouse_id,stock_allocation_id,item_id,current_quantity,reserved_quantity,order_rank,item_management_type,wms_lock_version,received_at,lock_version,created_at,updated_at
```

- `available_quantity` は生成カラムなので**除外**（INSERT時に自動計算される）

#### real_stock_lots.csv
```
id,real_stock_id,floor_id,location_id,expiration_date,price,content_amount,container_amount,initial_quantity,current_quantity,reserved_quantity,status,purchase_id,created_at,updated_at
```

### 実装手順

1. `TestDataGenerator.php` に `saveStockDataAction()` メソッドを追加
2. `getWarehouseActionNames()` に `'saveStockData'` を追加
3. アクション内で:
   - スナップショット名（オプション）をフォームで入力可能にする
   - `real_stocks` と `real_stock_lots` をクエリ
   - CSV文字列を生成（`fputcsv` でメモリ上に構築）
   - `Storage::disk('s3')->put()` で S3 に保存
   - 保存件数をNotificationで表示

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Filament/Pages/TestDataGenerator.php` | `saveStockDataAction()` 追加、`getWarehouseActionNames()` 更新 |

### アクションの実装詳細

```php
use Illuminate\Support\Facades\Storage;

public function saveStockDataAction(): Action
{
    return Action::make('saveStockData')
        ->label('在庫データ保存')
        ->icon('heroicon-o-arrow-down-tray')
        ->color('success')
        ->requiresConfirmation()
        ->modalHeading('在庫データをS3に保存')
        ->modalDescription('現在のreal_stocks・real_stock_lotsデータをCSVとしてS3に保存します。')
        ->schema([
            TextInput::make('snapshot_name')
                ->label('スナップショット名（任意）')
                ->helperText('未入力の場合はタイムスタンプが使用されます')
                ->placeholder('例: 初期状態、テスト前')
                ->maxLength(50),
        ])
        ->action(function (array $data) {
            // 1. スナップショットID生成（名前 or タイムスタンプ）
            $timestamp = now()->format('Ymd_His');
            $name = $data['snapshot_name'] ?? '';
            $dirName = $name ? "{$timestamp}_{$name}" : $timestamp;
            $basePath = "test-stock-snapshots/{$dirName}";

            // 2. real_stocks → CSV文字列生成
            // 3. real_stock_lots → CSV文字列生成
            // 4. Storage::disk('s3')->put("{$basePath}/real_stocks.csv", $csv);
            // 5. Storage::disk('s3')->put("{$basePath}/real_stock_lots.csv", $csv);
            // 6. Notification で保存完了＋件数表示
        });
}
```

### S3パス構造

```
{bucket}/{prefix}/test-stock-snapshots/
├── 20260218_143000_初期状態/
│   ├── real_stocks.csv
│   └── real_stock_lots.csv
├── 20260218_150000/
│   ├── real_stocks.csv
│   └── real_stock_lots.csv
└── ...
```

### 完了条件

- [ ] 「在庫データ保存」ボタンが倉庫タブに表示される
- [ ] ボタン押下でスナップショット名入力＋確認ダイアログが表示される
- [ ] 実行後、S3に `test-stock-snapshots/{dirName}/real_stocks.csv` と `real_stock_lots.csv` が保存される
- [ ] CSVに全レコードが正しく含まれている（カラム名ヘッダー付き）
- [ ] `available_quantity` が除外されている
- [ ] Notificationで保存件数が表示される

---

## P2: 在庫データ読込（S3インポート）

### 目的

P1でS3に保存したスナップショットを選択して、在庫データを完全に上書き復元する。

### 設計方針

**S3からスナップショット一覧を取得 → 選択 → TRUNCATE → 一括INSERT** 方式を採用する。

- ファイルアップロードは不要（S3から直接取得）
- Selectコンポーネントでスナップショット一覧を表示
- 一覧は S3の `test-stock-snapshots/` 配下のディレクトリ（prefix）を列挙

### 実装手順

1. `TestDataGenerator.php` に `loadStockDataAction()` メソッドを追加
2. `getWarehouseActionNames()` に `'loadStockData'` を追加
3. アクション内で:
   - S3から `test-stock-snapshots/` 配下のディレクトリ一覧を取得
   - Selectで選択させる
   - 選択されたスナップショットの2つのCSVをS3から取得
   - トランザクション内でTRUNCATE → INSERT

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Filament/Pages/TestDataGenerator.php` | `loadStockDataAction()` 追加、`getWarehouseActionNames()` 更新 |

### アクションの実装詳細

```php
public function loadStockDataAction(): Action
{
    return Action::make('loadStockData')
        ->label('在庫データ読込')
        ->icon('heroicon-o-arrow-up-tray')
        ->color('warning')
        ->requiresConfirmation()
        ->modalHeading('在庫データをS3から読込')
        ->modalDescription('保存されたスナップショットを選択して在庫データを上書きします。既存データは全て削除されます。')
        ->modalSubmitActionLabel('読込を実行')
        ->schema([
            Select::make('snapshot')
                ->label('スナップショット')
                ->options(function () {
                    // S3の test-stock-snapshots/ 配下のディレクトリ一覧を取得
                    $directories = Storage::disk('s3')->directories('test-stock-snapshots');
                    return collect($directories)
                        ->mapWithKeys(fn ($dir) => [
                            $dir => basename($dir),  // "20260218_143000_初期状態"
                        ])
                        ->sortKeysDesc()  // 新しい順
                        ->toArray();
                })
                ->required()
                ->searchable(),
        ])
        ->action(function (array $data) {
            $snapshotPath = $data['snapshot'];

            // 1. S3からCSV取得
            $stocksCsv = Storage::disk('s3')->get("{$snapshotPath}/real_stocks.csv");
            $lotsCsv = Storage::disk('s3')->get("{$snapshotPath}/real_stock_lots.csv");

            // 2. CSVパース
            // 3. トランザクション内で:
            //    - SET FOREIGN_KEY_CHECKS=0
            //    - real_stock_lots TRUNCATE
            //    - real_stocks TRUNCATE
            //    - real_stocks INSERT（バッチ、available_quantity除外）
            //    - real_stock_lots INSERT（バッチ）
            //    - SET FOREIGN_KEY_CHECKS=1
            // 4. 成功Notification（復元件数表示）
        });
}
```

**注意点:**
- `available_quantity` は生成カラムなので INSERT 時に含めない
- バッチINSERT（1000件ずつ `chunk` して `insert`）でメモリ効率を考慮
- TRUNCATE順序: 子テーブル（lots）→ 親テーブル（stocks）
- INSERT順序: 親テーブル（stocks）→ 子テーブル（lots）
- S3からのCSV取得は `Storage::disk('s3')->get()` で文字列として取得
- CSVパースは `str_getcsv()` で各行を処理

### 完了条件

- [ ] 「在庫データ読込」ボタンが倉庫タブに表示される
- [ ] スナップショット選択フォームが表示され、S3上の一覧が新しい順に並ぶ
- [ ] スナップショットが存在しない場合は空の選択肢でエラーにならない
- [ ] P1で保存したスナップショットを選択すると在庫データが完全に復元される
- [ ] 復元後の `available_quantity` が正しく計算されている（生成カラム）
- [ ] 復元後の `real_stock_lots` のロケーション・期限等が正しい
- [ ] Notificationで復元件数が表示される

---

## P3: UIカード追加・動作確認

### 目的

倉庫タブのBladeテンプレートに「在庫データ保存」「在庫データ読込」の説明カードを追加し、全体の動作確認を行う。

### 実装手順

1. `test-data-generator.blade.php` の倉庫テストデータタブ内のグリッドに2枚のカードを追加
2. カードのデザインは既存の「酒類在庫生成」「在庫データ生成」カードに合わせる

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `resources/views/filament/pages/test-data-generator.blade.php` | 倉庫タブに2枚の説明カード追加 |

### カードの内容

#### 在庫データ保存カード
- アイコン: `heroicon-o-arrow-down-tray` (success色)
- タイトル: 在庫データ保存
- 説明: 現在のreal_stocks・real_stock_lotsデータをCSVとしてS3に保存します。
- チェックポイント:
  - real_stocks + real_stock_lots の全データ
  - スナップショット名を付けて管理
  - S3にタイムスタンプ付きで保存

#### 在庫データ読込カード
- アイコン: `heroicon-o-arrow-up-tray` (warning色)
- タイトル: 在庫データ読込
- 説明: S3に保存されたスナップショットを選択して在庫データを復元します。
- チェックポイント:
  - 既存データを完全上書き
  - real_stocks + real_stock_lots を同時復元
  - 復元確認ダイアログ付き

### 動作確認手順

1. 倉庫タブで既存の在庫生成機能（酒類在庫生成 or 在庫データ生成）でデータ作成
2. 「在庫データ保存」でS3に保存（スナップショット名: 「テスト初期状態」）
3. 「WMSデータTRUNCATE」で全データ削除
4. 「在庫データ読込」でスナップショット選択→復元
5. 在庫管理画面で復元されたデータが正しいことを確認

### 完了条件

- [ ] 倉庫タブに「在庫データ保存」「在庫データ読込」カードが表示される
- [ ] カードのデザインが既存カードと統一されている
- [ ] 上記の動作確認手順が全て成功する

---

## 制約（厳守）

1. **DB破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` は絶対に実行しない
2. **FK禁止**: 外部キー制約は使用しない
3. **DB接続**: 必ず `sakemaru` コネクションを使用
4. **生成カラム**: `available_quantity` は INSERT 時に含めない（DB側で自動計算）
5. **Filament 4パターン**: `use Filament\Actions\Action`（`Filament\Tables\Actions\Action` ではない）、モーダルは `->schema()` を使用
6. **テスト環境限定**: この機能は `local`/`development`/`staging` でのみ利用可能（既存の `canAccess()` で制御済み）
7. **トランザクション安全性**: インポート処理はトランザクション内で実行し、失敗時はロールバック
8. **S3ストレージ**: `Storage::disk('s3')` を使用、パスは `test-stock-snapshots/` 配下

## 全体完了条件

- 倉庫タブに「在庫データ保存」「在庫データ読込」の2つのアクションが追加されている
- S3にCSV保存→全削除→S3から読込の往復で在庫データが完全に復元される
- UIカードで各機能の説明が表示される
- 既存の5つのアクション（WMSマスタ生成、商品温度帯設定、酒類在庫生成、在庫データ生成、WMSデータTRUNCATE）に影響がない