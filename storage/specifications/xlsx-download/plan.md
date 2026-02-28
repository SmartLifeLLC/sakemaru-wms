# CSV/XLSXダウンロード機能 作業計画

## 前提

- Filament 4 + Laravel 12 プロジェクト
- 約47のリストテーブルが存在（`app/Filament/Resources/*/Tables/*.php`）
- S3設定は既に完了（`config/filesystems.php` で `s3` ディスク設定済み、prefix: `kura`）
- Excel/CSVパッケージは未導入（`phpoffice/phpspreadsheet` を新規追加）
- 既存の進捗管理パターン: `WmsQueueProgress` モデル（再利用する）
- 既存のダウンロード管理パターン: `WmsOrderDataFile` モデル（参考にする）

## 設計方針

### アーキテクチャ

```
[ユーザー] → [Filament テーブル toolbarAction]
                ↓ フォーマット選択モーダル（CSV/XLSX）
           [ExportService] → 小件数: 同期ダウンロード
                            → 大件数: ProcessExportJob (Queue)
                                        ↓
                                   [S3にアップロード]
                                        ↓
                                   [WmsExportLog に記録]
                                        ↓
                                   [Notification で完了通知]
```

### 同期/非同期の閾値

- **1,000件以下**: 同期処理（即時ダウンロード）
- **1,000件超**: 非同期ジョブ（WmsQueueProgress で進捗管理、完了後S3から再ダウンロード）

### エクスポート対象データ

- テーブルに現在適用されている**フィルター条件**を反映したクエリ結果
- テーブルに定義されている**カラム**をそのままエクスポート（visible カラムのみ）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | パッケージ導入 & DB設計 | phpspreadsheet導入、wms_export_logsテーブル作成 | マイグレーション成功 |
| P1 | モデル・Enum・サービス層 | WmsExportLog、ExportFormat/ExportStatus Enum、ExportService | モデル・サービスの単体確認 |
| P2 | エクスポートジョブ実装 | ProcessExportJob（非同期処理） | ジョブがキュー経由で実行可能 |
| P3 | Filament Trait & 1テーブル適用 | HasExportAction Trait作成、WmsReceiptInspectionsTableに適用 | 1テーブルでCSV/XLSXダウンロード動作確認 |
| P4 | 全テーブルへの一括適用 | 残り約46テーブルにTrait適用 | 全テーブルにエクスポートボタン表示 |
| P5 | エクスポートログ管理画面 | WmsExportLogsリソース作成（ログ閲覧・再ダウンロード） | 管理画面で履歴確認・再ダウンロード可能 |
| P6 | テスト & 品質確認 | 全テスト通過、回帰確認 | `composer test` 全グリーン |

---

## P0: パッケージ導入 & DB設計

### 目的

PhpSpreadsheetパッケージの導入と、エクスポートログ管理テーブルの作成。

### 手順

1. **phpspreadsheet インストール**
   ```bash
   composer require phpoffice/phpspreadsheet
   ```

2. **マイグレーション作成**
   ```bash
   php artisan make:migration create_wms_export_logs_table
   ```

3. **テーブル設計: `wms_export_logs`**

   ```php
   Schema::connection('sakemaru')->create('wms_export_logs', function (Blueprint $table) {
       $table->id();
       $table->string('resource_name');          // Filamentリソース名（例: 'wms_receipt_inspections'）
       $table->string('format', 10);             // 'csv' or 'xlsx'
       $table->string('status', 20);             // pending, processing, completed, failed
       $table->string('file_name');               // ダウンロードファイル名
       $table->string('file_path')->nullable();   // S3パス
       $table->unsignedBigInteger('file_size')->nullable(); // バイト
       $table->unsignedInteger('row_count')->nullable();    // エクスポート行数
       $table->json('filters')->nullable();       // 適用されたフィルター条件
       $table->json('columns')->nullable();       // エクスポートしたカラム名
       $table->unsignedBigInteger('user_id');     // 実行ユーザー
       $table->string('error_message')->nullable(); // エラー時のメッセージ
       $table->timestamp('downloaded_at')->nullable(); // ダウンロード日時
       $table->timestamps();

       $table->index(['user_id', 'created_at']);
       $table->index(['resource_name', 'created_at']);
       $table->index('status');
   });
   ```

4. **マイグレーション実行**
   ```bash
   php artisan migrate
   ```

### 完了条件

- `composer require phpoffice/phpspreadsheet` が成功
- `php artisan migrate` が成功（`wms_export_logs` テーブル存在確認）
- `php artisan migrate:status` でマイグレーションが `Ran` 表示

---

## P1: モデル・Enum・サービス層

### 目的

エクスポート機能のコアクラスを実装する。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Enums/ExportFormat.php` | CSV/XLSX形式Enum |
| `app/Enums/ExportStatus.php` | ステータスEnum |
| `app/Models/WmsExportLog.php` | エクスポートログモデル |
| `app/Services/Export/ExportService.php` | エクスポート共通サービス |

### 実装詳細

#### ExportFormat Enum

```php
namespace App\Enums;

enum ExportFormat: string
{
    case CSV = 'csv';
    case XLSX = 'xlsx';

    public function label(): string
    {
        return match ($this) {
            self::CSV => 'CSV',
            self::XLSX => 'Excel (XLSX)',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::CSV => 'text/csv',
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }
}
```

#### ExportStatus Enum

```php
namespace App\Enums;

enum ExportStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '待機中',
            self::PROCESSING => '処理中',
            self::COMPLETED => '完了',
            self::FAILED => '失敗',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::PROCESSING => 'warning',
            self::COMPLETED => 'success',
            self::FAILED => 'danger',
        };
    }
}
```

#### WmsExportLog モデル

```php
namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;

class WmsExportLog extends WmsModel
{
    protected $table = 'wms_export_logs';

    protected $fillable = [
        'resource_name', 'format', 'status', 'file_name',
        'file_path', 'file_size', 'row_count', 'filters',
        'columns', 'user_id', 'error_message', 'downloaded_at',
    ];

    protected $casts = [
        'format' => ExportFormat::class,
        'status' => ExportStatus::class,
        'filters' => 'array',
        'columns' => 'array',
        'file_size' => 'integer',
        'row_count' => 'integer',
        'downloaded_at' => 'datetime',
    ];

    // scopeForUser, scopeForResource, markAsCompleted, markAsFailed 等
}
```

#### ExportService

ExportServiceの責務:
- テーブルのクエリ＋カラム定義を受け取り、CSV/XLSXファイルを生成
- S3にアップロード
- WmsExportLogを更新
- 小件数は同期、大件数はジョブディスパッチ

```php
namespace App\Services\Export;

class ExportService
{
    // 同期/非同期の閾値
    const SYNC_THRESHOLD = 1000;

    public function export(
        Builder $query,
        array $columns,     // ['label' => 'DBカラムまたはクロージャ']
        ExportFormat $format,
        string $resourceName,
        int $userId,
        ?array $filters = null
    ): WmsExportLog|StreamedResponse { ... }

    public function generateFile(Builder $query, array $columns, ExportFormat $format, string $filePath): int { ... }

    public function generateCsv(Builder $query, array $columns, string $filePath): int { ... }

    public function generateXlsx(Builder $query, array $columns, string $filePath): int { ... }

    public function uploadToS3(string $localPath, string $s3Path): void { ... }

    public function getDownloadUrl(WmsExportLog $log): string { ... }
}
```

### 完了条件

- 全クラスがシンタックスエラーなく作成されている
- `php artisan tinker` で `new WmsExportLog()` が正常にインスタンス化できる

---

## P2: エクスポートジョブ実装

### 目的

大量データのエクスポートを非同期で処理するジョブクラスを実装する。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Jobs/ProcessExportJob.php` | 非同期エクスポートジョブ |

### 実装詳細

```php
namespace App\Jobs;

class ProcessExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600; // 10分

    public function __construct(
        public int $exportLogId,
        public string $modelClass,    // クエリ再構築用
        public array $queryScopes,    // フィルター条件再現用
        public array $columns,        // エクスポートカラム定義
    ) {
        $this->onQueue('default');
    }

    public function handle(ExportService $exportService): void { ... }
    public function failed(\Throwable $exception): void { ... }
}
```

**注意**: Eloquent Builder はシリアライズできないため、モデルクラス名＋フィルター条件をシリアライズし、ジョブ内でクエリを再構築する。

### 完了条件

- ジョブクラスが作成されている
- `php artisan queue:listen` でジョブが処理可能な状態

---

## P3: Filament Trait & 1テーブル適用

### 目的

再利用可能なTraitを作成し、最初のテーブル（WmsReceiptInspections）で動作確認する。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Filament/Concerns/HasExportAction.php` | エクスポートアクションTrait（新規） |
| `app/Filament/Resources/WmsReceiptInspections/Tables/WmsReceiptInspectionsTable.php` | 最初の適用先 |

### 実装詳細

#### HasExportAction Trait

Traitは各テーブルの `toolbarActions` に追加する `Action` を提供する。

```php
namespace App\Filament\Concerns;

use Filament\Actions\Action;

trait HasExportAction
{
    public static function getExportAction(): Action
    {
        return Action::make('export')
            ->label('ダウンロード')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->schema([
                \Filament\Forms\Components\Select::make('format')
                    ->label('ファイル形式')
                    ->options([
                        'csv' => 'CSV',
                        'xlsx' => 'Excel (XLSX)',
                    ])
                    ->default('xlsx')
                    ->required(),
            ])
            ->action(function (array $data, Table $table) {
                // ExportService を呼び出し
                // フィルター条件を取得してエクスポート実行
            });
    }
}
```

#### WmsReceiptInspectionsTable への適用

```php
// 既存の toolbarActions にエクスポートアクションを追加
->toolbarActions([
    static::getExportAction(),  // ← 追加
    BulkActionGroup::make([
        DeleteBulkAction::make(),
    ]),
])
```

### 動作確認手順

1. `composer dev` でサーバー起動
2. WmsReceiptInspections リストページを開く
3. ツールバーに「ダウンロード」ボタンが表示されること
4. クリック → モーダルでCSV/XLSX選択 → ダウンロード実行
5. ファイルが正しくダウンロードされること
6. `wms_export_logs` にログが記録されること

### 完了条件

- WmsReceiptInspections でCSV/XLSXダウンロードが動作する
- モーダルでフォーマット選択ができる
- ダウンロードしたファイルの内容がテーブル表示データと一致する
- `wms_export_logs` にログが記録される

---

## P4: 全テーブルへの一括適用

### 目的

P3で確認済みのパターンを残り約46テーブルに適用する。

### 対象テーブル一覧

以下の全 `*Table.php` ファイル（約47件）:

```
app/Filament/Resources/*/Tables/*.php
```

### 適用手順

各テーブルファイルに対して:

1. `use App\Filament\Concerns\HasExportAction;` を追加
2. `use HasExportAction;` をクラス内に追加
3. `toolbarActions` に `static::getExportAction()` を追加
   - `toolbarActions` が未定義のテーブルには新規追加

### 注意事項

- テーブルごとにカラム定義が異なるため、Traitが汎用的にカラムを取得できることを確認
- 一部のテーブルは複数のリストページで使われている場合がある（WmsShortageAllocations等）
- `toolbarActions` の既存アクションを壊さないこと

### 完了条件

- 全テーブルにエクスポートアクションが追加されている
- 各リストページでエクスポートボタンが表示される
- 既存のツールバーアクション（BulkAction, Create等）が引き続き動作する

---

## P5: エクスポートログ管理画面

### 目的

エクスポート履歴を確認し、過去のファイルを再ダウンロードできる管理画面を作成する。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Filament/Resources/WmsExportLogs/WmsExportLogResource.php` | リソースクラス |
| `app/Filament/Resources/WmsExportLogs/Pages/ListWmsExportLogs.php` | リストページ |
| `app/Filament/Resources/WmsExportLogs/Tables/WmsExportLogsTable.php` | テーブル定義 |
| `resources/views/livewire/mega-menu.blade.php` | メニュー追加（必要に応じて） |

### テーブルカラム

| カラム | ラベル | 説明 |
|--------|--------|------|
| resource_name | 対象画面 | リソース名を日本語ラベルに変換 |
| format | 形式 | CSV / XLSX |
| status | ステータス | バッジ表示 |
| file_name | ファイル名 | - |
| row_count | 件数 | - |
| file_size | サイズ | human readable |
| user_id | 実行者 | ユーザー名表示 |
| created_at | 実行日時 | - |

### レコードアクション

- **ダウンロード**: S3からファイルをダウンロード（completedステータスのみ）

### フィルター

- resource_name（対象画面）
- format（形式）
- status（ステータス）
- user_id（実行者）

### 完了条件

- エクスポートログ管理画面が正常に表示される
- 過去のエクスポートファイルを再ダウンロードできる
- フィルターが正常に動作する

---

## P6: テスト & 品質確認

### 目的

全機能の動作確認と回帰テスト。

### 手順

1. **コードフォーマット**
   ```bash
   ./vendor/bin/pint
   ```

2. **全テスト実行**
   ```bash
   composer test
   ```

3. **回帰確認**
   - 既存の全リストページが正常に表示されること
   - 既存のツールバーアクション（Create, BulkAction等）が動作すること
   - `AllPagesAccessibilityTest` が通ること

4. **機能テスト**
   - CSV/XLSXダウンロードが各テーブルで動作すること（サンプル3-5テーブル手動確認）
   - フィルター適用状態でのエクスポートが正しいこと
   - 大量データ時の非同期処理が動作すること
   - エクスポートログ管理画面が動作すること
   - S3へのアップロード・ダウンロードが動作すること

### 完了条件

- `composer test` 全グリーン
- `./vendor/bin/pint` 差分なし
- 手動確認で主要テーブル3-5件のエクスポートが動作

---

## 制約（厳守）

1. **DB破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` 一切禁止
2. **FK禁止**: 外部キー制約は使用しない
3. **Filament 4パターン**: `toolbarActions()` を使用、`actions()` は不可
4. **インポートパス**: `Filament\Actions\Action`（NOT `Filament\Tables\Actions\Action`）
5. **モーダルスキーマ**: `->schema([...])` を使用（NOT `->form([...])`）
6. **既存機能の維持**: 既存のtoolbarActions, recordActions, bulkActionsを壊さない
7. **テーブルデザイン仕様準拠**: `storage/specifications/table-design-specification.md`

## 全体完了条件

- 全リストページ（約47テーブル）にCSV/XLSXダウンロードボタンが追加されている
- ダウンロードモーダルでフォーマット（CSV/XLSX）を選択できる
- 小件数は同期、大件数は非同期でエクスポートされる
- ファイルはS3にアップロードされる
- `wms_export_logs` にエクスポート履歴が記録される
- エクスポートログ管理画面で履歴確認・再ダウンロードが可能
- `composer test` 全グリーン
