# format_strategy_class を wms_order_jx_settings に移動

- **作成日**: 2026-03-01
- **ステータス**: ドラフト
- **ディレクトリ**: storage/specifications/ordering/20260301-move-format-strategy-to-jx-settings/

## 背景・目的

現在 `format_strategy_class`（発注ファイル生成クラス名）は `wms_contractor_settings` テーブルに存在するが、実際のファイル生成は JX設定単位（`wms_order_jx_settings`）で行われており、以下の問題がある:

1. **設定場所と利用場所の乖離**: ファイル生成は `wms_order_jx_settings` を起点に実行されるのに、generatorクラスの指定が別テーブル
2. **集約ロジックとの不整合**: 複数の発注先が1つのJX設定に集約される（例: カナカン日配→カナカン食品）ため、contractor単位でgeneratorを切り替えても意味がない
3. **未使用状態**: `wms_contractor_settings.format_strategy_class` は保存・読込のみで、実際のファイル生成では `EWMSClient` Enumからハードコードで `HanaOrderFileGenerator` を取得している

本変更により、JX設定ごとにファイル生成クラスを選択できるようにし、`EWMSClient` Enumへの依存を解消する。

## 現状の実装

### ファイル生成の呼び出しフロー

```
OrderServiceFactory::generator()
  → EWMSClient::current()       // config('wms.client') = 'hana'
    → HanaOrderFileGenerator     // システム全体で1つ固定
```

### DB構造

**wms_contractor_settings** (現在 format_strategy_class がある場所):
- `format_strategy_class` varchar(255) nullable — 保存のみ、未参照

**wms_order_jx_settings** (本来あるべき場所):
- JX接続情報 + `add_zero_record` + `auto_transmit_on_confirm` 等
- generatorクラスの指定なし

### 関連クラス

| クラス | 役割 |
|---|---|
| `OrderFileGeneratorInterface` | ファイル生成インターフェース（`generate()`, `getJxTransmissionContractorIds()` 等） |
| `HanaOrderFileGenerator` | ハナ様向け128byte固定長 `.dat` ファイル生成（実装済み・稼働中） |
| `DefaultOrderFileGenerator` | フォールバック空実装 |
| `EWMSClient` | クライアントEnum（HANA/DEFAULT） |
| `OrderServiceFactory` | `EWMSClient::current()` からgeneratorを取得 |

## 変更内容

### 概要

1. `EOrderFileGenerator` Enumを新規作成し、`HanaOrderFileGenerator` と `DefaultOrderFileGenerator` を登録
2. `wms_order_jx_settings` に `order_file_generator` カラムを追加（Enumで管理）
3. `OrderServiceFactory` を JX設定からgeneratorを解決するよう変更
4. `ContractorInitSeeder` を拡張し、JX設定に対してgeneratorを設定
5. JX設定のFilamentフォームに Select を追加
6. `wms_contractor_settings.format_strategy_class` の UI表示を削除（カラムは残す）

### 詳細設計

#### 1. EOrderFileGenerator Enum 新規作成

```php
// app/Enums/AutoOrder/EOrderFileGenerator.php

namespace App\Enums\AutoOrder;

use App\Contracts\OrderFileGeneratorInterface;
use App\Services\AutoOrder\Generators\DefaultOrderFileGenerator;
use App\Services\AutoOrder\Generators\HanaOrderFileGenerator;

enum EOrderFileGenerator: string
{
    case HANA = 'hana';
    case DEFAULT = 'default';

    public function label(): string
    {
        return match ($this) {
            self::HANA => 'ハナ様向け（128byte固定長）',
            self::DEFAULT => 'デフォルト',
        };
    }

    public function generatorClass(): string
    {
        return match ($this) {
            self::HANA => HanaOrderFileGenerator::class,
            self::DEFAULT => DefaultOrderFileGenerator::class,
        };
    }

    public function generator(): OrderFileGeneratorInterface
    {
        return app($this->generatorClass());
    }
}
```

#### 2. DB変更: wms_order_jx_settings にカラム追加

```php
// マイグレーション
Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
    $table->string('order_file_generator', 50)
        ->nullable()
        ->after('add_zero_record')
        ->comment('発注ファイル生成クラス（EOrderFileGenerator enum値）');
});
```

#### 3. WmsOrderJxSetting モデル変更

```php
// $fillable に追加
'order_file_generator',

// $casts に追加
'order_file_generator' => EOrderFileGenerator::class,
```

#### 4. OrderServiceFactory 変更

```php
class OrderServiceFactory
{
    /**
     * JX設定からファイル生成クラスを取得
     */
    public static function generatorForJxSetting(WmsOrderJxSetting $jxSetting): OrderFileGeneratorInterface
    {
        $enum = $jxSetting->order_file_generator ?? EOrderFileGenerator::DEFAULT;
        return $enum->generator();
    }

    /**
     * 既存メソッドは後方互換性のため維持（非推奨）
     * @deprecated JX設定経由で取得すること
     */
    public static function generator(): OrderFileGeneratorInterface
    {
        return EWMSClient::current()->orderFileGenerator();
    }
}
```

#### 5. OrderTransmissionService 変更

`doGenerateOrderFiles()` 内で、JX設定ごとにgeneratorを切り替える:

```php
// 変更前（システム全体で1つ）
$generator = OrderServiceFactory::generator();
$files = $generator->generate($candidates);

// 変更後（JX設定ごと）
// JX設定を取得し、それぞれのgeneratorでファイル生成
// 具体的な実装は Phase で詳細化
```

**注意**: `HanaOrderFileGenerator` は内部で `JX_CONTRACTOR_CODES` や `TRANSMISSION_MAPPING` をハードコードしている。これらは将来的にDB管理に移行する候補だが、本変更のスコープ外。

#### 6. ContractorInitSeeder 拡張

既存の `ContractorInitSeeder` に、JX設定への generator 設定を追加:

```php
// JX設定に対してHanaOrderFileGeneratorを設定
$jxSettings = WmsOrderJxSetting::where('is_active', true)->get();
foreach ($jxSettings as $jxSetting) {
    $jxSetting->update([
        'order_file_generator' => EOrderFileGenerator::HANA,
    ]);
}
```

#### 7. UI変更

**WmsOrderJxSettingResource フォーム** — 「基本情報」セクションに Select 追加:

```php
Select::make('order_file_generator')
    ->label('ファイル生成クラス')
    ->options(collect(EOrderFileGenerator::cases())->mapWithKeys(
        fn ($e) => [$e->value => $e->label()]
    ))
    ->nullable()
    ->helperText('発注ファイルの生成フォーマットを選択'),
```

**WmsOrderJxSettingResource テーブル** — カラム追加:

```php
TextColumn::make('order_file_generator')
    ->label('生成クラス')
    ->formatStateUsing(fn ($state) => $state?->label() ?? '-')
    ->alignCenter(),
```

**ContractorForm（発注先編集）** — `wms_format_strategy_class` フィールドを非表示化:

```php
// 削除 or visible(false) にする
TextInput::make('wms_format_strategy_class')
    ->visible(false),  // JX設定側に移動したため非表示
```

### 影響範囲

| 影響箇所 | 内容 |
|---|---|
| `OrderTransmissionService::doGenerateOrderFiles()` | generatorの取得方法を変更 |
| `OrderTransmissionService::generateEmptyFilesForMissingSettings()` | JX設定からgeneratorを取得 |
| `WmsOrderJxSettingResource` | フォーム・テーブルにgeneratorフィールド追加 |
| `ContractorForm` | `format_strategy_class` フィールド非表示化 |
| `WmsSettingRelationManager` | 同上 |
| `JxTestData` ページ | generator取得方法の変更（影響確認要） |
| `ContractorInitSeeder` | JX設定への初期値設定追加 |

## 制約

- `php artisan migrate:fresh` / `migrate:refresh` / `db:wipe` 禁止
- 外部キー（FK）使用禁止
- `wms_contractor_settings.format_strategy_class` カラムは**削除しない**（後方互換性のため残す、UIのみ非表示）
- 既存の `EWMSClient` Enum と `OrderServiceFactory::generator()` は削除せず `@deprecated` 扱い
- `HanaOrderFileGenerator` 内のハードコード定数（`JX_CONTRACTOR_CODES`, `TRANSMISSION_MAPPING`）は本変更のスコープ外

## 対象ファイル

### 新規作成

- `app/Enums/AutoOrder/EOrderFileGenerator.php` — ファイル生成クラスEnum
- `database/migrations/XXXX_add_order_file_generator_to_wms_order_jx_settings_table.php` — カラム追加

### 既存変更

- `app/Models/WmsOrderJxSetting.php` — fillable, casts 追加
- `app/Services/AutoOrder/OrderServiceFactory.php` — JX設定からgenerator取得メソッド追加
- `app/Services/AutoOrder/OrderTransmissionService.php` — generator取得ロジック変更
- `app/Filament/Resources/WmsOrderJxSettingResource.php` — フォーム・テーブルにフィールド追加
- `app/Filament/Resources/Contractors/Schemas/ContractorForm.php` — format_strategy_class 非表示化
- `app/Filament/Resources/Contractors/RelationManagers/WmsSettingRelationManager.php` — 同上
- `database/seeders/ContractorInitSeeder.php` — JX設定への初期値設定追加

### 参照のみ

- `app/Contracts/OrderFileGeneratorInterface.php` — 変更なし
- `app/Services/AutoOrder/Generators/HanaOrderFileGenerator.php` — 変更なし
- `app/Services/AutoOrder/Generators/DefaultOrderFileGenerator.php` — 変更なし
- `app/Enums/EWMSClient.php` — deprecated化のみ（後日削除検討）
- `app/Models/WmsContractorSetting.php` — format_strategy_class カラムは残す

## 確認事項

1. **EWMSClient の完全廃止タイミング**: 本変更では `@deprecated` にとどめるが、全参照箇所の移行完了後に削除するか？
2. **HanaOrderFileGenerator のハードコード定数**: `JX_CONTRACTOR_CODES` / `TRANSMISSION_MAPPING` を `wms_contractor_settings.transmission_contractor_id` に完全移行するタスクを別途作成するか？
3. **FTP送信の format_strategy**: FTP送信時もJX設定と同様にEnum管理にするか？（現在FTP専用のgeneratorは未実装）
