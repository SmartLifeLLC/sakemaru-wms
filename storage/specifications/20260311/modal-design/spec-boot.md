# Work Plan: modal-design

- **ID**: modal-design
- **作成日**: 2026-03-11
- **最終更新**: 2026-03-11
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/20260311/modal-design/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（spec-boot.md）
2. spec-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから spec-plan.md の該当セクションを読んで作業再開

## 概要

WMS全モーダルのデザインを統一するため、共通Bladeコンポーネント（`components/modal/*`）を新規作成し、既存モーダルを段階的に移行する。デザインベースは sakemaru-ai-core `/earnings` 画面のモーダル。

## 重要な設計制約

- 既存モーダルの変更は Phase 3 以降（Phase 1 は共通コンポーネント作成のみ）
- FK禁止、migrate:fresh 禁止（CLAUDE.md 準拠）
- ダークモード対応必須（既存WMSがダークモード対応済み）
- Alpine.js + Livewire 3 連携を維持
- Navy背景ヘッダー (`#1e3a5f`) は使わない → `bg-slate-50` + `border-b` に統一

## 対象ファイル

### 新規作成
- `resources/views/components/modal/container.blade.php` — モーダルコンテナ（backdrop + box + transition）
- `resources/views/components/modal/header.blade.php` — ヘッダー（タイトル + 閉じるボタン）
- `resources/views/components/modal/content.blade.php` — コンテンツエリア（スクロール対応）
- `resources/views/components/modal/footer.blade.php` — フッター（ボタン配置）
- `resources/views/components/modal/form-group.blade.php` — フォームグループ（label + input ラッパー）
- `resources/views/components/modal/confirm.blade.php` — 確認ダイアログ（ショートカット）

### 既存変更（Phase 3）
| ファイル | 種別 | 優先度 |
|----------|------|--------|
| `filament/modals/trade-detail.blade.php` | detail | 高 |
| `filament/resources/real-stocks/modal/stock-detail.blade.php` | detail | 高 |
| `livewire/trade-detail-modal.blade.php` | detail/form | 高 |
| `filament/pages/floor-plan-editor/zone-edit-modal.blade.php` | form | 中 |
| `filament/pages/floor-plan-editor/add-zone-modal.blade.php` | form | 中 |
| `filament/resources/wms-picking-logs/view-modal.blade.php` | detail | 中 |
| `filament/resources/wms-auto-order-job-controls/result-modal.blade.php` | tabbed | 低 |
| `filament/components/transmission-detail-modal.blade.php` | detail | 低 |

### 参照のみ（変更禁止）
- `storage/specifications/20260311/modal-design/spec.md` — デザイン仕様書

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: 共通Bladeコンポーネント作成 | ✅完了 | 2026-03-11 | container/header/content/footer/form-group/confirm |
| P2: 動作検証用サンプルモーダル | ✅完了 | 2026-03-11 | 6タイプ全てのサンプル作成・表示確認 |
| P3: 既存モーダル移行（高優先度） | ✅完了 | 2026-03-11 | trade-detail, stock-detail, trade-detail-modal |
| P4: 既存モーダル移行（中優先度） | ✅完了 | 2026-03-11 | zone-edit, add-zone, picking-logs |
| P5: 既存モーダル移行（低優先度） | ✅完了 | 2026-03-11 | result-modal, transmission-detail |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 既存モーダルのパス一覧
- `resources/views/filament/pages/floor-plan-editor/zone-edit-modal.blade.php`
- `resources/views/filament/pages/floor-plan-editor/add-zone-modal.blade.php`
- `resources/views/filament/modals/trade-detail.blade.php`
- `resources/views/filament/resources/real-stocks/modal/stock-detail.blade.php`
- `resources/views/filament/resources/wms-picking-logs/view-modal.blade.php`
- `resources/views/filament/resources/wms-auto-order-job-controls/result-modal.blade.php`
- `resources/views/filament/components/transmission-detail-modal.blade.php`
- `resources/views/livewire/trade-detail-modal.blade.php`

### 現状の実装パターン
- Navy header (`#1e3a5f`) がエディタモーダルで使用中
- ほとんどのモーダルはコンテンツのみ（外枠ラッパーは呼び出し元で定義）
- Alpine.js `x-show` + `fixed inset-0` パターン

### Git ブランチ
- 作業ブランチ: (Phase開始時に決定)
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。
> セッション再開時にここを見れば何が終わっているかわかる。

### P1: 共通Bladeコンポーネント作成
- 完了日: 2026-03-11
- 実績:
  - 6ファイル作成: container/header/content/footer/form-group/confirm
  - ダークモード対応クラス全コンポーネントに適用
  - `php artisan view:cache` でコンパイルエラーなし確認
  - confirmコンポーネントはcontainer+footerを内部利用するショートカット実装

### P2: 動作検証用サンプルモーダル
- 完了日: 2026-03-11
- 実績:
  - `ModalShowcase.php` Filamentページクラス作成
  - `modal-showcase.blade.php` に7種サンプル配置（filter/column-select/form/detail/tabbed/confirm/nested）
  - ダークモード対応、ネストモーダルz-indexテスト含む
  - `view:cache` コンパイルエラーなし

### P3: 既存モーダル移行（高優先度）
- 完了日: 2026-03-11
- 成果物: 3ファイルのスタイル統一
- 実績:
  - `stock-detail.blade.php`: gray→slate系統一、テーブルヘッダをslate-50に変更
  - `trade-detail.blade.php`: gray→slate系統一、空状態にFA icon追加
  - `trade-detail-modal.blade.php`(Livewire版): 同上+wire:click等のバインディング維持
  - 外枠はFilamentのmodalContent()管理のため内部スタイルのみ統一
  - バッジのdark modeを`-900/30`パターンに統一

### P4: 既存モーダル移行（中優先度）
- 完了日: 2026-03-11
- 成果物: 3ファイルのスタイル統一
- 実績:
  - `zone-edit-modal.blade.php`: Navy header→x-modal.container+slate-50ヘッダーに完全置換、x-modal.footerも使用
  - `add-zone-modal.blade.php`: Navy header→x-modal.container/header/content/footer/form-groupに完全置換
  - `view-modal.blade.php`: gray→slate系統一、見出しtext-sm font-bold化、preコードブロックにborder追加

### P5: 既存モーダル移行（低優先度）
- 完了日: 2026-03-11
- 成果物: 2ファイルのスタイル統一
- 実績:
  - `result-modal.blade.php`: gray→slate系統一、タブスタイルをspec.md §5.5準拠に変更
  - `transmission-detail-modal.blade.php`: gray→slate系統一、空状態にFAアイコン追加、サマリーカードにborder追加
