# 発注先編集画面 統合リファクタリング 作業計画

## 前提

- 発注先編集画面は現在タブ形式（基本情報 | メール | WMS | 仕入先 | 納品曜日 | 倉庫コード）
- メール設定/WMS送信設定は同一 `wms_contractor_settings` レコード（HasOne）の RelationManager
- 仕入先/納品曜日/倉庫コードは HasMany の RelationManager
- `WmsContractorSetting::findOrCreateByContractor()` で未作成でも自動生成可能
- 現在ブランチ: feature/ordering-update

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | フォーム統合 | ContractorFormにメール設定・WMS送信設定セクション追加 | フォーム定義に全フィールドが存在 |
| P2 | データフロー・ヘッダーアクション | EditContractorでデータ連携・保存/削除ボタン変更 | 保存で両テーブル更新、右上に保存ボタン |
| P3 | RelationManager整理 | 不要RM削除、残りのタブ表示調整 | 仕入先/納品曜日/倉庫コードの3タブのみ |
| P4 | 動作検証 | レイアウト確認・保存テスト・回帰テスト | テスト通過・画面表示正常 |

---

## P1: フォーム統合

### 目的

ContractorForm に「発注メール設定」「WMS送信設定」セクションを追加し、
1画面で基本情報と設定を一括編集できるようにする。

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Filament/Resources/Contractors/Schemas/ContractorForm.php` | 変更 |

### 作業手順

#### 1-1. フォームに「発注メール設定」セクション追加

既存の「設定」セクションの後に追加。フィールド名は `wms_` プレフィックスを付与:

```php
Section::make('発注メール設定')
    ->icon('heroicon-o-envelope')
    ->schema([
        Grid::make(2)->schema([
            Section::make('メール設定')
                ->schema([
                    TextInput::make('wms_order_mail')->label('発注先メールアドレス')->email(),
                    TextInput::make('wms_order_mail_from')->label('送信名'),
                    TextInput::make('wms_order_mail_title')->label('メールタイトル'),
                    // 変数ヘルプ Placeholder
                ]),
            Section::make('メール本文')
                ->schema([
                    Textarea::make('wms_order_mail_content')->label('本文')->rows(12),
                ]),
        ]),
    ]),
```

#### 1-2. フォームに「WMS送信設定」セクション追加

MailSettingRelationManager / WmsSettingRelationManager のフィールドを参照し再現。
全フィールドに `wms_` プレフィックスを付与:

```php
Section::make('WMS送信設定')
    ->icon('heroicon-o-paper-airplane')
    ->schema([
        Select::make('wms_transmission_type')
            ->label('送信方式')
            ->options(...)
            ->required()->live()->default('MANUAL_CSV'),

        Select::make('wms_order_jx_setting_id')...    // JX_FINET時のみ表示
        Select::make('wms_order_ftp_setting_id')...    // FTP時のみ表示
        Select::make('wms_supply_warehouse_id')...     // INTERNAL時のみ表示
        Select::make('wms_transmission_contractor_id')...  // 送信先発注先

        TextInput::make('wms_auto_order_generation_time')
            ->label('自動発注生成時刻')
            ->placeholder('HH:MM')
            ->maxLength(5)
            ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
            ->helperText('仕入先別の自動発注候補生成時刻（HH:MM形式）'),

        TextInput::make('wms_transmission_time')...
        Fieldset::make('送信曜日')... // 7曜日トグル
        Toggle::make('wms_is_auto_transmission')...
        TextInput::make('wms_format_strategy_class')...
    ]),
```

### 完了条件

- ContractorFormにメール設定4フィールド + WMS設定15フィールド（曜日7つ含む）が定義されている
- `wms_` プレフィックスがContractorのカラム名と衝突しない

---

## P2: データフロー・ヘッダーアクション

### 目的

EditContractor で WmsContractorSetting のデータをフォームにロード/保存する。
保存ボタンを右上に移動、削除ボタンを除去する。

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Filament/Resources/Contractors/Pages/EditContractor.php` | 変更 |

### 作業手順

#### 2-1. mutateFormDataBeforeFill() でWMS設定データをロード

```php
protected function mutateFormDataBeforeFill(array $data): array
{
    $wmsSetting = WmsContractorSetting::where('contractor_id', $data['id'])->first();

    if ($wmsSetting) {
        // WmsContractorSettingの全フィールドをwms_プレフィックスでマッピング
        $wmsFields = [
            'order_mail', 'order_mail_from', 'order_mail_title', 'order_mail_content',
            'transmission_type', 'wms_order_jx_setting_id', 'wms_order_ftp_setting_id',
            'supply_warehouse_id', 'transmission_contractor_id',
            'auto_order_generation_time', 'transmission_time', 'format_strategy_class',
            'is_auto_transmission',
            'is_transmission_sun', 'is_transmission_mon', 'is_transmission_tue',
            'is_transmission_wed', 'is_transmission_thu', 'is_transmission_fri',
            'is_transmission_sat',
        ];
        foreach ($wmsFields as $field) {
            $data["wms_{$field}"] = $wmsSetting->{$field};
        }
        // transmission_type はEnum→文字列変換
        $data['wms_transmission_type'] = $wmsSetting->transmission_type?->value;
    }

    return $data;
}
```

#### 2-2. afterSave() でWMS設定データを保存

```php
protected function afterSave(): void
{
    $formData = $this->form->getState();
    $wmsSetting = WmsContractorSetting::findOrCreateByContractor($this->record->id);

    $wmsData = [];
    $wmsFields = [...]; // 2-1と同じリスト
    foreach ($wmsFields as $field) {
        $key = "wms_{$field}";
        if (array_key_exists($key, $formData)) {
            $wmsData[$field] = $formData[$key];
        }
    }

    $wmsSetting->update($wmsData);
}
```

#### 2-3. ヘッダーアクション変更

```php
protected function getHeaderActions(): array
{
    return [
        Actions\Action::make('save')
            ->label('保存')
            ->icon('heroicon-o-check')
            ->action('save')
            ->color('primary'),
        // DeleteAction 削除
    ];
}
```

#### 2-4. 下部の保存ボタンを非表示

```php
protected function getFormActions(): array
{
    return [];
}
```

### 完了条件

- 編集画面の右上に「保存」ボタンが表示される
- 削除ボタンがない
- 基本情報変更 → contractors テーブル更新
- メール/WMS設定変更 → wms_contractor_settings テーブル更新
- WmsContractorSetting未作成の発注先でも正常表示・保存可能

---

## P3: RelationManager整理

### 目的

メインフォームに統合したメール設定/WMS送信設定のRelationManagerを削除し、
残りの3つのRelationManager（仕入先/納品曜日/倉庫コード）をタブ表示する。

### 修正対象ファイル

| ファイル | 操作 |
|---------|------|
| `app/Filament/Resources/Contractors/ContractorResource.php` | 変更 |
| `app/Filament/Resources/Contractors/Pages/EditContractor.php` | 変更（タブ設定調整） |

### 作業手順

#### 3-1. ContractorResource の getRelations() からRM削除

```php
public static function getRelations(): array
{
    return [
        // MailSettingRelationManager 削除
        // WmsSettingRelationManager 削除
        ContractorSuppliersRelationManager::class,
        DeliveryDaysRelationManager::class,
        WarehouseSettingsRelationManager::class,
    ];
}
```

#### 3-2. EditContractor のタブ設定調整

`hasCombinedRelationManagerTabsWithContent()` を `true` に維持し、
コンテンツタブ（フォーム全体）のラベルを適切に設定:

```php
public function getContentTabLabel(): ?string
{
    return '基本情報・設定';
}

public function getContentTabIcon(): ?string
{
    return 'heroicon-o-building-office';
}
```

### 完了条件

- タブが4つ: 基本情報・設定 | 仕入先 | 倉庫別納品可能曜日 | 倉庫別納入先指定コード
- HasManyの3タブが正常に動作（追加/編集/削除）

---

## P4: 動作検証

### 目的

統合後の画面が正常に動作し、回帰テストが通ることを確認する。

### 作業手順

#### 4-1. レイアウト確認

- `admin/contractors/{id}/edit` にアクセス
- 上部: 基本情報・設定セクション
- 下部: メール設定・WMS送信設定セクション
- 右上: 保存ボタン
- 削除ボタンなし
- 3つのRelationManagerタブが下に表示

#### 4-2. 保存テスト

- 基本情報を変更して保存 → contractors テーブル更新確認
- メール設定を変更して保存 → wms_contractor_settings テーブル更新確認
- WMS送信設定（auto_order_generation_time含む）を変更して保存 → 同上
- WmsContractorSetting未作成の発注先で編集 → 保存時に自動作成される

#### 4-3. 回帰テスト

```bash
php artisan test --filter=AllPagesAccessibilityTest
# TP34（ListContractors）が通過すること
```

#### 4-4. Pint

```bash
./vendor/bin/pint app/Filament/Resources/Contractors/
```

### 完了条件

- 全レイアウト要件が満たされている
- 保存テスト4パターンが成功
- AllPagesAccessibilityTest TP34 通過
- Pint修正なし or 修正済み

---

## 制約（厳守）

1. **DB破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh` は絶対に実行しない
2. **FK禁止**: 外部キー制約を使用しない
3. **sakemaru接続**: WMSモデルは `WmsModel` 継承
4. **Filament 4**: 正しいインポートパス使用
5. **wms_プレフィックス**: フォームフィールド名衝突を防止
6. **RelationManagerファイル残置**: MailSettingRM/WmsSettingRMのファイル自体は削除不要（getRelationsから外すだけ）

## 全体完了条件

1. 全4 Phaseが完了
2. 画面レイアウトが要件通り（上部:基本情報 → 下部:設定 → タブ:HasMany）
3. 保存で contractors + wms_contractor_settings が正しく更新される
4. AllPagesAccessibilityTest 通過
