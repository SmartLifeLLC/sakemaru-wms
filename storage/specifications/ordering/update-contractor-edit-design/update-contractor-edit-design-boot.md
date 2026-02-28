# Work Plan: update-contractor-edit-design

- **ID**: update-contractor-edit-design
- **作成日**: 2026-03-01
- **最終更新**: 2026-03-01
- **ステータス**: P4待ち（動作確認）
- **ディレクトリ**: storage/specifications/ordering/update-contractor-edit-design/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（update-contractor-edit-design-boot.md）
2. update-contractor-edit-design-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから update-contractor-edit-design-plan.md の該当セクションを読んで作業再開

## 概要

発注先編集ページ（admin/contractors/{id}/edit）のデザイン変更。タブ名変更、セクション統合、レイアウト再構成、ヘルパーテキスト修正を行う。

## 重要な設計制約

- データベース破壊コマンド（migrate:fresh, migrate:refresh等）禁止
- FK禁止（アプリケーションレベルで関連管理）
- Filament 4 のインポートパス・APIに従う
- 既存の保存ロジック（EditContractor.php の afterSave）は変更しない
- 発注メール設定タブはRelationManagerではなくTabsコンポーネントで実装

## 対象ファイル

### 既存変更
- `app/Filament/Resources/Contractors/Schemas/ContractorForm.php` — フォーム全体のレイアウト変更
- `app/Filament/Resources/Contractors/Pages/EditContractor.php` — タブ名変更（「編集」→「基本情報」）

### 参照のみ（変更禁止）
- `app/Models/WmsContractorSetting.php`
- `app/Models/Sakemaru/Contractor.php`
- `app/Filament/Resources/Contractors/ContractorResource.php`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: タブ名変更＆セクション統合 | 完了 | 2026-03-01 | 設定セクション統合、contentTabLabel維持 |
| P2: WMS送信設定の右側配置＆ヘルパーテキスト修正 | 完了 | 2026-03-01 | Grid(2)左右配置、ヘルパーテキスト更新 |
| P3: 発注メール設定を別タブに分離 | 完了 | 2026-03-01 | Tabs/Tab で基本情報・発注メール設定を分離 |
| P4: 動作確認 | 未着手 | - | ブラウザ確認が必要 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 現在のフォーム構造
- ContractorForm.php: 4セクション（基本情報、設定、発注メール設定、WMS送信設定）
- EditContractor.php: `contentTabLabel` で「編集」タブ名設定、combinedRelationManagerTabsWithContent() 使用
- タブ構成: 編集 / 仕入先 / 倉庫別納品可能日 / 倉庫別納入先指定コード

### Git ブランチ
- 作業ブランチ: feature/ordering-update
- ベースブランチ: main

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: タブ名変更＆セクション統合
- 完了日: 2026-03-01
- 実績:
  - EditContractor.php: `getContentTabLabel()` メソッド追加で外側タブ名「編集」を維持
  - ContractorForm.php: 「設定」セクション（lead_time_id, is_active）を「基本情報」セクション末尾に統合
  - 「設定」セクションヘッダー削除

### P2: WMS送信設定の右側配置＆ヘルパーテキスト修正
- 完了日: 2026-03-01
- 実績:
  - Grid(2) で基本情報（左）とWMS送信設定（右）を横並び配置
  - WMS送信設定の collapsible 解除
  - `wms_transmission_contractor_id` のヘルパーテキストを「指定した発注先の送信データに本発注先の発注データを集約する（一つのファイルで送信したい場合）」に更新

### P3: 発注メール設定を別タブに分離
- 完了日: 2026-03-01
- 実績:
  - `Filament\Schemas\Components\Tabs` / `Tab` を使用してフォーム内タブ化
  - Tab 1: 基本情報（基本情報セクション + WMS送信設定セクション）
  - Tab 2: 発注メール設定（メール設定 + メール本文）
  - 外側タブ（RelationManager用）は「編集」のまま維持

### P4: 動作確認
- 完了日: -
- 実績:
  - (完了後に記入)
