# Work Plan: unify-megamenu-layout-design

- **ID**: unify-megamenu-layout-design
- **作成日**: 2026-04-07
- **最終更新**: 2026-04-07
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260407/20260407-114358-unify-megamenu-layout-design/`

## セッション再開手順

1. このファイルを読む
2. `20260407-114358-unify-megamenu-layout-design-plan.md` を読む
3. 下記「進捗」テーブルで現在の Phase を確認
4. 「Phase完了記録」で完了済み Phase の実績を確認
5. 「作業中コンテキスト」で途中データを確認
6. 未完了の最初の Phase から plan.md の該当セクションを読んで作業再開

## 概要

酒丸シリーズ全6システムの MegaMenu（PHP + Blade）・ページレイアウト CSS を WMS 標準に統一。アクセント色 indigo 統一、システム日付バッジ全システム導入、酒丸シリーズタブ全システム追加、スクロールバー CSS 展開。

## 重要な設計制約

- WMS の MegaMenu が標準リファレンス（WMS 自体は変更済み）
- 各システム固有のメニュータブ定義（EMenuCategory 分類）は維持
- WMS 固有の倉庫セレクタは他システムに移植しない
- Insights のカスタムフォント・カラーパレットは維持（MegaMenu アクセント色のみ indigo）
- Trade の精算ページ用 CSS は維持
- Split View 対応（base.blade.php, index.blade.php）は前タスクで完了済み
- データベース破壊コマンド禁止

## 対象ファイル

### 新規作成
- `sakemaru-delivery/app/Models/ClientSetting.php`
- `sakemaru-insights/app/Models/ClientSetting.php`
- `sakemaru-documents/app/Models/ClientSetting.php`

### 既存変更

**MegaMenu PHP:**
- `sakemaru-trade/app/Livewire/MegaMenu.php`
- `sakemaru-delivery/app/Livewire/MegaMenu.php`
- `sakemaru-insights/app/Livewire/MegaMenu.php`
- `sakemaru-search/app/Livewire/MegaMenu.php`
- `sakemaru-documents/app/Livewire/MegaMenu.php`
- `sakemaru-documents/app/Enums/EMenuCategory.php`

**MegaMenu Blade:**
- `sakemaru-trade/resources/views/livewire/mega-menu.blade.php`
- `sakemaru-delivery/resources/views/livewire/mega-menu.blade.php`
- `sakemaru-insights/resources/views/livewire/mega-menu.blade.php`
- `sakemaru-search/resources/views/livewire/mega-menu.blade.php`
- `sakemaru-documents/resources/views/livewire/mega-menu.blade.php`

**Filament Resources（Documents navigationGroup 追加）:**
- `sakemaru-documents/app/Filament/Resources/Documents/DocumentResource.php`
- `sakemaru-documents/app/Filament/Resources/Users/UserResource.php`

**theme.css:**
- `sakemaru-trade/resources/css/filament/admin/theme.css`
- `sakemaru-delivery/resources/css/filament/admin/theme.css`
- `sakemaru-insights/resources/css/filament/admin/theme.css`
- `sakemaru-search/resources/css/filament/search/theme.css`
- `sakemaru-documents/resources/css/filament/admin/theme.css`

### 参照のみ（変更禁止）
- `sakemaru-wms/app/Livewire/MegaMenu.php`
- `sakemaru-wms/resources/views/livewire/mega-menu.blade.php`
- `sakemaru-wms/resources/css/filament/admin/theme.css`
- `sakemaru-wms/app/Models/Sakemaru/ClientSetting.php`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: WMS MegaMenu 標準 Blade テンプレート整理 | 完了 | 2026-04-07 | WMS blade 構造を文書化、標準パターン確認 |
| P2: ClientSetting モデル追加（Delivery/Insights/Documents） | 完了 | 2026-04-07 | 3システムに ClientSetting モデル新規作成 |
| P3: Documents MegaMenu 刷新 | 完了 | 2026-04-07 | CSS hover→Alpine mega-menu、EMenuCategory label修正、navigationGroup追加 |
| P4: Trade MegaMenu 統一 | 完了 | 2026-04-07 | amber→indigo、日付バッジ、z-[35]、text-base、flex wrap+divider |
| P5: Delivery MegaMenu 統一 | 完了 | 2026-04-07 | amber→indigo、日付バッジ、z-[35]、text-base、fa-table-columns |
| P6: Insights MegaMenu 統一 | 完了 | 2026-04-07 | 日付バッジ追加、text-base、fa-table-columns |
| P7: Search MegaMenu 統一 | 完了 | 2026-04-07 | 外部リンクロジック廃止、buildSakemaruSeriesTab追加、日付バッジ |
| P8: ページスクロールバー CSS 全システム展開 | 完了 | 2026-04-07 | 全5システムtheme.cssにスクロールバーCSS追加 |
| P9: npm build & 最終確認 | 完了 | 2026-04-07 | 全5システムビルド成功 |

---

## 作業中コンテキスト

> Phase 作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 前タスクで完了済みの変更
- WMS MegaMenu.php: `external` → `openInSplitView` 変更済み
- WMS mega-menu.blade.php: `external` 分岐削除、`fa-table-columns` 統一済み
- Trade MegaMenu.php: `buildSakemaruSeriesTab()` 追加済み
- Trade mega-menu.blade.php: desc 表示追加済み
- Delivery MegaMenu.php: `buildSakemaruSeriesTab()` 追加済み
- Delivery mega-menu.blade.php: desc 表示追加済み
- 全5システム: Split View 対応（base.blade.php, index.blade.php）完了済み

### Documents の現状
- MegaMenu: CSS hover dropdown、ハードコード URL
- EMenuCategory: Documents='帳票' / Master='マスタ' / System='システム'
- Filament Resources の navigationGroup: '帳票管理'(2件) / '同期運用'(4件) / なし(DocumentResource, UserResource)
- EMenuCategory label と navigationGroup 文字列が不一致 → EMenuCategory label を修正必要

### 各システムの ClientSetting モデル名前空間
- WMS: `App\Models\Sakemaru\ClientSetting`
- Trade: `App\Models\BZCore\ClientSetting`
- Search: `App\Models\Sakemaru\ClientSetting`
- Delivery: なし → 新規 `App\Models\ClientSetting` (sakemaru 接続)
- Insights: なし → 新規 `App\Models\ClientSetting` (sakemaru 接続)
- Documents: なし → 新規 `App\Models\ClientSetting` (sakemaru 接続)

### Git ブランチ
- 各システムで個別ブランチを作成予定（Phase 実行時に決定）

---

## Phase完了記録

### P1: WMS MegaMenu 標準 Blade テンプレート整理
- 完了日: 2026-04-07
- 実績:
  - WMS blade の標準構造（header/nav/dropdown）を確認・文書化
  - 各システムのカスタマイズポイント特定

### P2: ClientSetting モデル追加
- 完了日: 2026-04-07
- 実績:
  - Delivery/Insights/Documents に `App\Models\ClientSetting` 新規作成（sakemaru接続）
  - `systemDate()` メソッド実装、php -l 通過

### P3: Documents MegaMenu 刷新
- 完了日: 2026-04-07
- 実績:
  - EMenuCategory label修正: 帳票→帳票管理、システム→同期運用、icon()メソッド追加
  - DocumentResource/UserResource に navigationGroup 追加
  - MegaMenu.php: ハードコード→NavigationGroup連動、buildSakemaruSeriesTab()追加
  - mega-menu.blade.php: CSS hover→Alpine mega-menu に完全置換

### P4: Trade MegaMenu 統一
- 完了日: 2026-04-07
- 実績:
  - MegaMenu.php: systemDateDisplay/systemDayOfWeek プロパティ追加
  - mega-menu.blade.php: amber→indigo、日付バッジ、z-[35]、text-base、flex wrap+divider

### P5: Delivery MegaMenu 統一
- 完了日: 2026-04-07
- 実績:
  - MegaMenu.php: ClientSetting import + 日付プロパティ追加
  - mega-menu.blade.php: amber→indigo、日付バッジ、z-[35]、text-base、fa-table-columns

### P6: Insights MegaMenu 統一
- 完了日: 2026-04-07
- 実績:
  - MegaMenu.php: ClientSetting import + 日付プロパティ追加
  - mega-menu.blade.php: 日付バッジ追加、text-base、fa-table-columns

### P7: Search MegaMenu 統一
- 完了日: 2026-04-07
- 実績:
  - MegaMenu.php: $externalItems集約ロジック廃止、buildSakemaruSeriesTab()追加、日付バッジ
  - mega-menu.blade.php: 日付バッジ、z-[35]、text-base、fa-table-columns、desc対応

### P8: ページスクロールバー CSS 全システム展開
- 完了日: 2026-04-07
- 実績:
  - 全5システムのtheme.cssにスクロールバーCSS追加
  - html.fi overflow-x:hidden/overflow-y:auto、fi-body min-height、fi-main/fi-page/fi-page-content padding

### P9: npm build & 最終確認
- 完了日: 2026-04-07
- 実績:
  - Trade: ビルド成功（3.29s）
  - Delivery: ビルド成功（1.33s）
  - Insights: ビルド成功（1.05s）
  - Search: ビルド成功（786ms）
  - Documents: ビルド成功（1.17s）
