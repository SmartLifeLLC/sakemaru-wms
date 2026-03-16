# 納入先指定コード管理UI 作業計画

## 前提

- `wms_contractor_warehouse_settings` テーブルは作成済み（fax-designated-code Phase完了）
- `WmsContractorWarehouseSetting` モデルは作成済み
- FAX発注書PDFへの描画は実装済み
- 現状、指定コードをDBに登録するUI画面がない

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | Contractorモデルにリレーション追加 | `warehouseSettings()` HasMany追加 | リレーション呼び出しでクエリが発行される |
| P2 | RelationManager作成 | 倉庫Select + 指定コードTextInputのCRUD | フォーム・テーブルが正しく表示される |
| P3 | ContractorResourceにRelationManager登録 | getRelations()に追加 | Contractor編集画面にタブが表示される |
| P4 | 動作確認 | 実際の画面操作で確認 | CRUD全操作が正常動作 |

---

## P1: Contractorモデルにリレーション追加

### 目的

ContractorモデルにwarehouseSettings()リレーションを追加し、RelationManagerから参照可能にする。

### 修正対象ファイル

- `app/Models/Sakemaru/Contractor.php`

### 修正方針

`warehouseDeliveryDays()` の直後に以下を追加:

```php
use App\Models\WmsContractorWarehouseSetting;

public function warehouseSettings(): HasMany
{
    return $this->hasMany(WmsContractorWarehouseSetting::class, 'contractor_id');
}
```

### 完了条件

- 構文エラーなし
- `Contractor::find(1)->warehouseSettings` がコレクションを返す

---

## P2: RelationManager作成

### 目的

Contractor編集画面で倉庫×指定コードを管理するRelationManagerを作成する。

### 新規作成ファイル

- `app/Filament/Resources/Contractors/RelationManagers/WarehouseSettingsRelationManager.php`

### 実装方針

`DeliveryDaysRelationManager` をベースに以下の構成:

**フォーム:**
- `Select::make('warehouse_id')` - 倉庫選択（`[code] name` 形式、searchable、編集時disabled）
- `TextInput::make('designated_code')` - 納入先指定コード（nullable, max 255）

**テーブル:**
- `warehouse.code` - 倉庫CD（sortable, searchable）
- `warehouse.name` - 倉庫名（sortable, searchable）
- `designated_code` - 納入先指定コード（placeholder: "-"）

**アクション:**
- headerActions: Create（contractor_id自動セット）
- recordActions: Edit, Delete

**ソート:**
- defaultSort: `warehouse.code`

### 完了条件

- 構文エラーなし
- Pint通過

---

## P3: ContractorResourceにRelationManager登録

### 目的

作成したRelationManagerをContractorResource.phpに登録する。

### 修正対象ファイル

- `app/Filament/Resources/Contractors/ContractorResource.php`

### 修正方針

1. use文追加: `use App\Filament\Resources\Contractors\RelationManagers\WarehouseSettingsRelationManager;`
2. `getRelations()` 配列に `WarehouseSettingsRelationManager::class` を追加

### 完了条件

- Contractor編集画面に新しいタブが表示される

---

## P4: 動作確認

### 目的

実際のブラウザ操作でCRUD全操作を確認する。

### 確認手順

1. Contractor編集画面にアクセス → 「倉庫別納入先指定コード」タブが表示される
2. 新規作成 → 倉庫選択 + 指定コード入力 → 保存成功
3. 編集 → 指定コード変更 → 保存成功
4. 削除 → レコード削除成功
5. 同一倉庫の重複作成 → ユニーク制約エラー

### 完了条件

- CRUD全操作が正常動作
- ユニーク制約が機能する

---

## 制約（厳守）

- FK作成禁止（index対応のみ）
- `migrate:fresh` / `migrate:refresh` / `migrate:reset` 実行禁止
- Filament 4のインポートパスを使用（`Filament\Schemas\Schema` 等）
- 既存のRelationManager（DeliveryDays）を壊さない

## 全体完了条件

- Contractor編集画面で倉庫別の納入先指定コードをCRUD管理できる
- 設定した指定コードがFAX発注書PDFに反映される（既存実装で対応済み）
