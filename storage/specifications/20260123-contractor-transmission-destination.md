# 発注データ送信先設定機能 仕様書

## 概要

発注先（contractors）ごとに発注データの送信先を設定できる機能を追加します。これにより、複数の発注先が同一の送信先（EOS代表発注先）を共有できるようになります。

## 変更日
2026-01-23

## 背景

### 現状の問題
Oracleシステムでは「EOS対象」フラグにより、非代表の仕入先は代表仕入先を経由して発注データを送信しています。

例：カナカングループの構成
```
仕入先コード | 名称                           | EOS代表
------------|-------------------------------|--------
1021        | カナカン(株)酒類 福井営業所     | 1106
1126        | カナカン(株)日配 福井営業所     | 1106
1680        | カナカン(株)金沢支店 酒類石川   | 1106
1106        | カナカン(株)食品 福井営業所     | 自身
```

現在のWMSでは、非代表の仕入先（1021, 1126, 1680）は`contractors`テーブルに登録されておらず、発注先別の納品曜日設定などができません。

### 解決策
1. 全ての仕入先を`contractors`テーブルに登録
2. `wms_contractor_settings`に送信先発注先を指定するカラムを追加

---

## データベース変更内容

### wms_contractor_settings テーブル

以下のカラムを**追加**してください：

```sql
ALTER TABLE wms_contractor_settings
ADD COLUMN transmission_contractor_id BIGINT UNSIGNED NULL
COMMENT '発注データ送信先の発注先ID（NULLの場合は自身の設定を使用）'
AFTER contractor_id;

-- 外部キー制約（任意）
ALTER TABLE wms_contractor_settings
ADD CONSTRAINT fk_wcs_transmission_contractor
FOREIGN KEY (transmission_contractor_id) REFERENCES contractors(id)
ON DELETE SET NULL;
```

### カラム仕様

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|-----------|------|
| transmission_contractor_id | BIGINT UNSIGNED | YES | NULL | 発注データ送信先の発注先ID |

### 動作仕様

| transmission_contractor_id | 動作 |
|---------------------------|------|
| NULL | 自身の設定（transmission_type, wms_order_jx_setting_id等）を使用して送信 |
| 他の発注先ID | 指定された発注先の設定を使用して送信 |

---

## Laravel マイグレーション

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_contractor_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('transmission_contractor_id')
                ->nullable()
                ->after('contractor_id')
                ->comment('発注データ送信先の発注先ID（NULLの場合は自身の設定を使用）');

            $table->foreign('transmission_contractor_id')
                ->references('id')
                ->on('contractors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wms_contractor_settings', function (Blueprint $table) {
            $table->dropForeign(['transmission_contractor_id']);
            $table->dropColumn('transmission_contractor_id');
        });
    }
};
```

---

## モデル変更

### WmsContractorSetting モデル

```php
// app/Models/WmsContractorSetting.php

// fillable に追加
protected $fillable = [
    // ... existing fields
    'transmission_contractor_id',
];

// リレーション追加
public function transmissionContractor(): BelongsTo
{
    return $this->belongsTo(Contractor::class, 'transmission_contractor_id');
}

/**
 * 実際の送信設定を取得（自身 or 送信先発注先の設定）
 */
public function getEffectiveTransmissionSettings(): self
{
    if ($this->transmission_contractor_id) {
        return $this->transmissionContractor->wmsContractorSetting;
    }
    return $this;
}
```

---

## 発注データ送信ロジックの変更

### 変更が必要な箇所

発注データ送信処理で、送信設定を取得する際に`transmission_contractor_id`を考慮する必要があります。

**変更前：**
```php
$settings = $contractor->wmsContractorSetting;
$transmissionType = $settings->transmission_type;
```

**変更後：**
```php
$settings = $contractor->wmsContractorSetting;
$effectiveSettings = $settings->getEffectiveTransmissionSettings();
$transmissionType = $effectiveSettings->transmission_type;
```

### 影響を受ける可能性のあるファイル

- 発注データ送信Job/Service
- 発注候補生成処理
- 発注先設定画面（Filament Resource）

---

## 管理画面（Filament）変更

### WmsContractorSettingResource

発注先設定画面に「発注データ送信先」フィールドを追加：

```php
// Form
Forms\Components\Select::make('transmission_contractor_id')
    ->label('発注データ送信先')
    ->relationship('transmissionContractor', 'name')
    ->searchable()
    ->preload()
    ->placeholder('自身の設定を使用')
    ->helperText('別の発注先の送信設定を使用する場合に選択'),

// Table
Tables\Columns\TextColumn::make('transmissionContractor.name')
    ->label('送信先')
    ->placeholder('自身')
    ->sortable(),
```

---

## データ移行

HanaDBTransfer側で以下のデータ移行を実施予定：

1. **contractors テーブル**: 非代表発注先（1021, 1126, 1680等）を追加
2. **wms_contractor_settings テーブル**: 追加した発注先の設定を作成
3. **transmission_contractor_id**: EOS代表発注先のIDを設定

### 移行後のデータ例

```
wms_contractor_settings:
contractor_id | transmission_contractor_id | transmission_type
-------------|---------------------------|------------------
1021         | 1106                      | NULL (使用しない)
1126         | 1106                      | NULL (使用しない)
1680         | 1106                      | NULL (使用しない)
1106         | NULL                      | JX_FINET
```

---

## テスト観点

1. **マイグレーション**: カラム追加が正常に完了すること
2. **送信設定取得**: `transmission_contractor_id`が設定されている場合、指定先の設定が取得されること
3. **NULL時の動作**: `transmission_contractor_id`がNULLの場合、自身の設定が使用されること
4. **管理画面**: 送信先発注先の選択・保存が正常に動作すること
5. **発注データ送信**: 設定に従って正しい送信先に送信されること

---

## 関連テーブル

| テーブル | 関係 |
|---------|------|
| contractors | 発注先マスタ |
| wms_contractor_settings | 発注先設定（1:1） |
| wms_contractor_suppliers | 発注先-仕入先マッピング（1:N） |
| wms_contractor_warehouse_delivery_days | 発注先-倉庫別納品曜日 |

---

## 備考

- この変更はHanaDBTransfer（データ移行システム）の改修と連動しています
- Laravel側でマイグレーション完了後、データ移行を実施します
- 納品曜日設定（`wms_contractor_warehouse_delivery_days`）も発注先別に設定可能になります
