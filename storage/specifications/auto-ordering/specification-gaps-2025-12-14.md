# 仕様と実装の乖離レポート (Multi-Echelon Auto Order)

**作成日:** 2025年12月14日
**対象:** 多段階供給ネットワーク対応 (Multi-Echelon Supply Network)

本ドキュメントでは、最新の仕様書 (`2025-12-14-multi-echelon-changes.md`) と、現在のコードベースにおける実装の差異について報告します。

## 1. データベーススキーマの差異

### `wms_item_supply_settings` テーブル

| 項目 | **最新仕様 (Specification)** | **現状の実装 (Current Code)** | 影響・課題 |
| :--- | :--- | :--- | :--- |
| **外部発注設定カラム** | **`item_contractor_id`** | **`contractor_id`** | 仕様では既存の契約情報 (`item_contractors` テーブル) との紐付けを求めているが、実装は業者 (`contractors`) への直接参照になっており、契約マスタとの整合性が取れない可能性がある。 |
| **外部発注設定の型** | `BIGINT UNSIGNED NULL` | `BIGINT UNSIGNED NULL` | 型は一致。 |

**参照ファイル:**
- `database/migrations/2025_12_14_143048_create_wms_item_supply_settings_table.php`

---

## 2. アプリケーションロジックの差異

### `WmsItemSupplySetting` モデル

- **リレーション定義:**
    - **仕様:** `itemContractor()` メソッドで `ItemContractor` モデルへリレーションを持つべき。
    - **実装:** `contractor()` メソッドで `Contractor` モデルへ直接リレーションを持っている。

### `MultiEchelonCalculationService` サービス

- **発注候補作成ロジック (`createOrderCandidate`):**
    - **仕様:** `item_contractor_id` を使用して発注先を特定する必要がある。
    - **実装:** `$setting->contractor_id` を直接使用して `WmsOrderCandidate` を作成している。

**参照ファイル:**
- `app/Models/WmsItemSupplySetting.php`
- `app/Services/AutoOrder/MultiEchelonCalculationService.php`

---

## 3. 推奨される修正対応

以下の手順でコードベースを仕様に適合させることを推奨します。

1.  **マイグレーション修正:**
    - `2025_12_14_143048_...` ファイルを編集し、`contractor_id` カラムを削除、`item_contractor_id` カラムを追加する。

2.  **モデル修正 (`WmsItemSupplySetting`):**
    - `contractor_id`, `contractor` リレーションを削除。
    - `item_contractor_id`, `itemContractor` リレーションを追加。

3.  **サービス修正 (`MultiEchelonCalculationService`):**
    - `createOrderCandidate` メソッド内で、`$setting->itemContractor->contractor_id` を参照して発注候補を作成するように変更する。
