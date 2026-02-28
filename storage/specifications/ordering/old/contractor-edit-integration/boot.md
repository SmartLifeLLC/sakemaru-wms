# Work Plan: contractor-edit-integration

- **ID**: contractor-edit-integration
- **作成日**: 2026-02-27
- **最終更新**: 2026-02-27
- **ステータス**: 進行中
- **ディレクトリ**: storage/specifications/ordering/contractor-edit-integration/
- **ブランチ**: feature/ordering-update（既存）

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

発注先編集画面（admin/contractors/{id}/edit）をタブ形式から統合レイアウトに変更する。
発注メール設定・WMS送信設定（auto_order_generation_time含む）をメインフォームに統合し、
保存ボタンを右上に配置、削除ボタンを除去する。

## 重要な設計制約

- **DB破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh` 絶対禁止
- **FK禁止**: 外部キー制約を使用しない
- **sakemaru接続**: WMSモデルは `WmsModel` を継承
- **Filament 4**: `Filament\Schemas\Components\Section` 等のv4インポートパスを使用
- **WmsContractorSetting**: メール設定とWMS送信設定は同一レコード（HasOne）
- **wms_プレフィックス**: フォームフィールド名にプレフィックスを付与してContractorカラムと衝突回避
- **findOrCreateByContractor**: 保存時にWmsContractorSettingが未作成でも自動生成される

## 対象ファイル

### 既存変更
- `app/Filament/Resources/Contractors/Schemas/ContractorForm.php` - フォームにメール設定・WMS送信設定セクション追加
- `app/Filament/Resources/Contractors/Pages/EditContractor.php` - データロード/保存、ヘッダーアクション変更
- `app/Filament/Resources/Contractors/ContractorResource.php` - RelationManager削除

### 参照のみ（変更禁止）
- `app/Models/WmsContractorSetting.php` - findOrCreateByContractor()メソッド参照
- `app/Filament/Resources/Contractors/RelationManagers/MailSettingRelationManager.php` - フィールド定義参照
- `app/Filament/Resources/Contractors/RelationManagers/WmsSettingRelationManager.php` - フィールド定義参照
- `app/Filament/Resources/WmsContractorSettings/Schemas/WmsContractorSettingForm.php` - auto_order_generation_time定義参照

## テストデータ

```bash
php artisan test --filter=AllPagesAccessibilityTest   # TP34（ListContractors）通過確認
```

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: フォーム統合 | 未着手 | - | ContractorFormにメール/WMS設定セクション追加 |
| P2: データフロー・ヘッダーアクション | 未着手 | - | EditContractorのmutate/afterSave・保存/削除ボタン |
| P3: RelationManager整理 | 未着手 | - | 不要RelationManager削除 |
| P4: 動作検証 | 未着手 | - | レイアウト確認・保存テスト・回帰テスト |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### WmsContractorSetting フィールド一覧（フォーム統合対象）
メール設定:
- order_mail, order_mail_from, order_mail_title, order_mail_content

WMS送信設定:
- transmission_type, wms_order_jx_setting_id, wms_order_ftp_setting_id
- supply_warehouse_id, transmission_contractor_id
- auto_order_generation_time, transmission_time, format_strategy_class
- is_auto_transmission
- is_transmission_sun/mon/tue/wed/thu/fri/sat

### Git ブランチ
- 作業ブランチ: feature/ordering-update
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: フォーム統合
- 完了日: -
- 実績:
  - (完了後に記入)

### P2: データフロー・ヘッダーアクション
- 完了日: -
- 実績:
  - (完了後に記入)

### P3: RelationManager整理
- 完了日: -
- 実績:
  - (完了後に記入)

### P4: 動作検証
- 完了日: -
- 実績:
  - (完了後に記入)
