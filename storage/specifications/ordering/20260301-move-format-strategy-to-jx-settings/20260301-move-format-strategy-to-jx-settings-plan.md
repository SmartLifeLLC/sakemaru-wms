# format_strategy_class を wms_order_jx_settings に移動 — 作業計画

## 前提

- `HanaOrderFileGenerator` → `HanaOrderJXFileGenerator` リネーム完了
- `EWMSClient` Enumの参照先更新済み
- テスト・OrderTransmissionServiceのリネーム対応済み
- **`DefaultOrderFileGenerator` / `DEFAULT` ケースは不要**（ユーザー指示）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | Enum・マイグレーション・モデル | EOrderFileGenerator Enum作成、DBカラム追加、モデル更新 | Enumが正しくインスタンス化でき、モデルでcastされる |
| P2 | Factory・Service変更 | OrderServiceFactory・OrderTransmissionServiceをJX設定ベースに変更 | generatorがJX設定から解決される |
| P3 | UI変更 | JX設定フォーム/テーブル追加、ContractorForm非表示化 | Filamentフォームで選択・表示できる |
| P4 | Seeder・テスト更新 | ContractorInitSeeder拡張、テスト修正 | `composer test` 全パス |

---

## P1: Enum・マイグレーション・モデル

### 目的

`EOrderFileGenerator` Enumを新設し、JX設定テーブルに `order_file_generator` カラムを追加、モデルでEnum castする。

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Enums/AutoOrder/EOrderFileGenerator.php` | 新規作成 |
| `database/migrations/XXXX_add_order_file_generator_to_wms_order_jx_settings_table.php` | 新規作成 |
| `app/Models/WmsOrderJxSetting.php` | fillable, casts追加 |

### 実装内容

#### 1. EOrderFileGenerator Enum（HANAのみ）

```php
// app/Enums/AutoOrder/EOrderFileGenerator.php
namespace App\Enums\AutoOrder;

use App\Contracts\OrderFileGeneratorInterface;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;

enum EOrderFileGenerator: string
{
    case HANA = 'hana';

    public function label(): string
    {
        return match ($this) {
            self::HANA => 'ハナ様向け（128byte固定長）',
        };
    }

    public function generatorClass(): string
    {
        return match ($this) {
            self::HANA => HanaOrderJXFileGenerator::class,
        };
    }

    public function generator(): OrderFileGeneratorInterface
    {
        return app($this->generatorClass());
    }
}
```

#### 2. マイグレーション

```php
Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
    $table->string('order_file_generator', 50)
        ->nullable()
        ->after('add_zero_record')
        ->comment('発注ファイル生成クラス（EOrderFileGenerator enum値）');
});
```

#### 3. WmsOrderJxSetting モデル

- `$fillable` に `'order_file_generator'` 追加
- `$casts` に `'order_file_generator' => EOrderFileGenerator::class` 追加

### 完了条件

- Enum: `EOrderFileGenerator::HANA->generator()` が `HanaOrderJXFileGenerator` インスタンスを返す
- マイグレーション: `php artisan migrate` が成功
- モデル: `WmsOrderJxSetting` の `order_file_generator` が Enum にキャストされる

---

## P2: Factory・Service変更

### 目的

`OrderServiceFactory` に JX設定ベースのメソッドを追加し、`OrderTransmissionService` のgenerator取得ロジックを変更する。

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Services/AutoOrder/OrderServiceFactory.php` | メソッド追加 |
| `app/Services/AutoOrder/OrderTransmissionService.php` | generator取得ロジック変更 |
| `app/Enums/EWMSClient.php` | `@deprecated` コメント追加 |

### 実装内容

#### 1. OrderServiceFactory

```php
use App\Enums\AutoOrder\EOrderFileGenerator;
use App\Models\WmsOrderJxSetting;

class OrderServiceFactory
{
    /**
     * JX設定からファイル生成クラスを取得
     */
    public static function generatorForJxSetting(WmsOrderJxSetting $jxSetting): ?OrderFileGeneratorInterface
    {
        return $jxSetting->order_file_generator?->generator();
    }

    /**
     * @deprecated JX設定経由で取得すること
     */
    public static function generator(): OrderFileGeneratorInterface
    {
        return EWMSClient::current()->orderFileGenerator();
    }
}
```

#### 2. OrderTransmissionService

`getOrderFileGenerator()` メソッドの変更方針:

**現状**: システム全体で1つのgeneratorを取得（`OrderServiceFactory::generator()`）

**変更後**: 呼び出し元に応じてJX設定ベースのgeneratorを使用:
- `doGenerateOrderFilesWithProgress()` — 現状維持（候補は複数JX設定にまたがるため、グローバルgeneratorを使用）
- `generateEmptyFilesForMissingSettings()` — JX設定ごとにgeneratorを取得（`OrderServiceFactory::generatorForJxSetting($jxSetting)`）
- `linkCandidatesToDocument()` — 同様にグローバルgeneratorを維持

**重要**: 現時点ではgeneratorは `HanaOrderJXFileGenerator` のみ。`generate()` メソッドが内部で全JX設定を処理するアーキテクチャのため、`doGenerateOrderFilesWithProgress()` のgenerator取得は当面グローバルのまま。将来JX設定単位でgeneratorを切り替える場合は、`generate()` のシグネチャ変更が必要。

主な変更箇所:
- `generateEmptyFilesForMissingSettings()` 内: `instanceof` チェックを `$jxSetting->order_file_generator` の存在チェックに変更
- `getOrderFileGenerator()`: 引き続き `OrderServiceFactory::generator()` を使用（`@deprecated`だが当面維持）

### 完了条件

- `OrderServiceFactory::generatorForJxSetting()` が正しくgeneratorを返す
- `generateEmptyFilesForMissingSettings()` がJX設定の `order_file_generator` を参照する
- 既存の呼び出しフローが壊れない

---

## P3: UI変更

### 目的

JX設定画面にgenerator選択UIを追加し、発注先編集の `format_strategy_class` を非表示化する。

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Filament/Resources/WmsOrderJxSettingResource.php` | フォーム・テーブルにフィールド追加 |
| `app/Filament/Resources/Contractors/Schemas/ContractorForm.php` | `wms_format_strategy_class` 非表示化 |
| `app/Filament/Resources/Contractors/RelationManagers/WmsSettingRelationManager.php` | `format_strategy_class` 非表示化 |

### 実装内容

#### 1. WmsOrderJxSettingResource フォーム — 「基本情報」セクション

```php
use App\Enums\AutoOrder\EOrderFileGenerator;
use Filament\Forms\Components\Select;

Select::make('order_file_generator')
    ->label('ファイル生成クラス')
    ->options(collect(EOrderFileGenerator::cases())->mapWithKeys(
        fn ($e) => [$e->value => $e->label()]
    ))
    ->nullable()
    ->helperText('発注ファイルの生成フォーマットを選択'),
```

#### 2. WmsOrderJxSettingResource テーブル — `add_zero_record` の後に追加

```php
TextColumn::make('order_file_generator')
    ->label('生成クラス')
    ->formatStateUsing(fn ($state) => $state?->label() ?? '-')
    ->alignCenter(),
```

#### 3. ContractorForm — `wms_format_strategy_class` を `visible(false)`

```php
TextInput::make('wms_format_strategy_class')
    ->label('フォーマット戦略クラス')
    ->visible(false),
```

#### 4. WmsSettingRelationManager — `format_strategy_class` を `visible(false)`

```php
TextInput::make('format_strategy_class')
    ->label('フォーマット戦略クラス')
    ->visible(false),
```

### 完了条件

- JX設定の作成・編集でgenerator Selectが表示される
- JX設定一覧に生成クラスカラムが表示される
- 発注先編集で `format_strategy_class` が非表示

---

## P4: Seeder・テスト更新

### 目的

ContractorInitSeederにJX設定のgenerator初期値設定を追加し、テストを更新する。

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `database/seeders/ContractorInitSeeder.php` | JX設定のgenerator設定追加 |
| `tests/Unit/Services/AutoOrder/OrderTransmissionServiceTest.php` | テスト修正 |

### 実装内容

#### 1. ContractorInitSeeder

`run()` メソッドの末尾に追加:

```php
use App\Enums\AutoOrder\EOrderFileGenerator;
use App\Models\WmsOrderJxSetting;

// JX設定に対してデフォルトのgeneratorを設定
$jxSettings = WmsOrderJxSetting::where('is_active', true)->get();
$jxUpdated = 0;
foreach ($jxSettings as $jxSetting) {
    if ($jxSetting->order_file_generator === null) {
        $jxSetting->update([
            'order_file_generator' => EOrderFileGenerator::HANA,
        ]);
        $jxUpdated++;
    }
}
$this->command->info("JX設定generator設定: {$jxUpdated}件");
```

#### 2. OrderTransmissionServiceTest

- `it_can_get_generator_from_factory` — `OrderServiceFactory::generatorForJxSetting()` のテスト追加
- `it_returns_hana_generator_when_configured` — JX設定ベースのテストに更新
- 各テストで `WmsOrderJxSetting` の `order_file_generator` を設定

### 完了条件

- `composer test` が全パス
- `ContractorInitSeeder` がJX設定のgeneratorを設定する

---

## 制約（厳守）

- `php artisan migrate:fresh` / `migrate:refresh` / `db:wipe` **絶対禁止**
- 外部キー（FK）使用禁止
- `wms_contractor_settings.format_strategy_class` カラムは削除しない（UIのみ非表示）
- `EWMSClient` Enum は `@deprecated` 扱い（削除しない）
- `HanaOrderJXFileGenerator` 内のハードコード定数は変更しない（スコープ外）
- `DefaultOrderFileGenerator` / `DEFAULT` ケースは作成しない

## 全体完了条件

1. `EOrderFileGenerator` Enum が `HANA` ケースのみで正しく動作
2. `wms_order_jx_settings.order_file_generator` カラムが追加されている
3. JX設定画面でgeneratorの選択・表示ができる
4. 発注先編集で `format_strategy_class` が非表示
5. `OrderTransmissionService` がJX設定ベースでgeneratorを解決できる
6. `composer test` 全パス
7. `ContractorInitSeeder` でJX設定にHANAが設定される
