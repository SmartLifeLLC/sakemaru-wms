# Work Plan: designated-code-ui

- **ID**: designated-code-ui
- **作成日**: 2026-02-13
- **最終更新**: 2026-02-13
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/designated-code-ui/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

発注先×倉庫ごとの「納入先指定コード」を管理するUI画面を作成する。
Contractor編集画面にRelationManagerタブとして追加する（DeliveryDaysRelationManagerと同パターン）。

## 重要な設計制約

- FK禁止（index対応のみ）
- `migrate:fresh` / `migrate:refresh` 禁止
- Filament 4パターンに従う（Schema, not Form）
- DB接続は`sakemaru`

## 対象ファイル

### 新規作成
- `app/Filament/Resources/Contractors/RelationManagers/WarehouseSettingsRelationManager.php` - RelationManager

### 既存変更
- `app/Models/Sakemaru/Contractor.php` - `warehouseSettings()` リレーション追加
- `app/Filament/Resources/Contractors/ContractorResource.php` - RelationManager登録

### 参照のみ（変更禁止）
- `app/Filament/Resources/Contractors/RelationManagers/DeliveryDaysRelationManager.php` - パターン参考
- `app/Models/WmsContractorWarehouseSetting.php` - 既存モデル（P1で作成済み）

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: Contractorモデルにリレーション追加 | 完了 | 2026-02-13 | warehouseSettings() HasMany追加 |
| P2: RelationManager作成 | 完了 | 2026-02-13 | WarehouseSettingsRelationManager.php作成 |
| P3: ContractorResourceにRelationManager登録 | 完了 | 2026-02-13 | getRelations()に追加 |
| P4: 動作確認 | 完了 | 2026-02-13 | tinkerで検証OK、ブラウザ確認待ち |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 前提（fax-designated-code Phase完了済み）
- テーブル `wms_contractor_warehouse_settings` 作成済み（マイグレーション実行済み）
- モデル `WmsContractorWarehouseSetting` 作成済み
- カラム: id, warehouse_id, contractor_id, designated_code, timestamps
- ユニークインデックス: (warehouse_id, contractor_id)

### UIパターン参考
- `DeliveryDaysRelationManager` が最も近いパターン
- 倉庫Select + designated_code TextInput のシンプルフォーム
- テーブルは 倉庫CD / 倉庫名 / 納入先指定コード の3カラム

### Git ブランチ
- 作業ブランチ: feature/fax-designated-code
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: Contractorモデルにリレーション追加
- 完了日: 2026-02-13
- 実績:
  - `app/Models/Sakemaru/Contractor.php` に `warehouseSettings()` HasMany追加
  - `WmsContractorWarehouseSetting` のuse文追加

### P2: RelationManager作成
- 完了日: 2026-02-13
- 実績:
  - `app/Filament/Resources/Contractors/RelationManagers/WarehouseSettingsRelationManager.php` 新規作成
  - フォーム: 倉庫Select + 指定コードTextInput
  - テーブル: 倉庫CD / 倉庫名 / 納入先指定コード
  - アクション: Create(contractor_id自動セット) / Edit / Delete

### P3: ContractorResourceにRelationManager登録
- 完了日: 2026-02-13
- 実績:
  - `app/Filament/Resources/Contractors/ContractorResource.php` の `getRelations()` に追加

### P4: 動作確認
- 完了日: 2026-02-13
- 実績:
  - tinkerでリレーション動作確認OK（Collection返却）
  - RelationManagerクラスのロード確認OK
  - ContractorResourceへの登録確認OK
  - 全ファイル構文チェック・Pint通過
