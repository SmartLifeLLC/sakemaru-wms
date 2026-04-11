# WMS モーダルデザイン統一 作業計画

## 前提

- デザイン仕様書: `storage/specifications/20260311/modal-design/spec.md`
- 共通コンポーネントディレクトリ `resources/views/components/modal/` は未作成
- 既存モーダル8ファイルは全てデザインが不統一（Navy header、直接Tailwind、Filament標準が混在）
- WMSはダークモード対応済みのため、新規コンポーネントもダークモード対応必須

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | 共通Bladeコンポーネント作成 | 6つの共通コンポーネントを新規作成 | 全コンポーネントが作成され、Bladeコンパイルエラーなし |
| P2 | 動作検証用サンプルモーダル | 6タイプ全てのサンプルページ作成・表示確認 | ブラウザで全タイプの表示を確認、レイアウト崩れなし |
| P3 | 既存モーダル移行（高優先度） | trade-detail, stock-detail, trade-detail-modal の3ファイル | 共通コンポーネントに置換、既存機能が維持されている |
| P4 | 既存モーダル移行（中優先度） | zone-edit, add-zone, picking-logs の3ファイル | 共通コンポーネントに置換、既存機能が維持されている |
| P5 | 既存モーダル移行（低優先度） | result-modal, transmission-detail の2ファイル | 共通コンポーネントに置換、既存機能が維持されている |

---

## P1: 共通Bladeコンポーネント作成

### 目的

spec.md のデザイン仕様に基づき、再利用可能な6つのBladeコンポーネントを作成する。

### 作成対象

```
resources/views/components/modal/
├── container.blade.php    ← モーダルコンテナ（backdrop + box + transition）
├── header.blade.php       ← ヘッダー（タイトル + 閉じるボタン）
├── content.blade.php      ← コンテンツエリア（スクロール対応）
├── footer.blade.php       ← フッター（ボタン配置）
├── form-group.blade.php   ← フォームグループ（label + input ラッパー）
└── confirm.blade.php      ← 確認ダイアログ（ショートカット）
```

### 各コンポーネントの仕様

#### 1. container.blade.php

**Props:**
- `size` (string, default: 'lg') — sm/md/lg/xl/2xl/3xl/6xl
- `alpine-var` (string, required) — Alpine.js の表示制御変数名
- `z-index` (string, default: '100') — z-index値（ネストモーダル対応）

**仕様:**
- spec.md §2.1 のモーダルコンテナ構造
- Backdrop: `bg-black/40 dark:bg-black/60`
- `@click.self` でBackdropクリック閉じ
- `@keydown.escape.window` でESC閉じ
- トランジション: §2.7 のenter/leave
- `$slot` でヘッダー/コンテンツ/フッターを挿入

#### 2. header.blade.php

**Props:**
- `icon` (string, required) — FontAwesome アイコン名（fa- prefix不要）
- `title` (string, required) — モーダルタイトル
- `alpine-var` (string, required) — 閉じるボタン用の変数名

**仕様:**
- spec.md §2.3 のヘッダー構造
- `bg-slate-50 dark:bg-gray-900` + `border-b dark:border-gray-700`
- テキスト: `text-sm font-bold text-slate-700 dark:text-gray-200`

#### 3. content.blade.php

**Props:**
- `padding` (string, default: '4') — パディング（4 or 6）

**仕様:**
- spec.md §2.4
- `overflow-y-auto flex-1`
- `$slot` でコンテンツ挿入

#### 4. footer.blade.php

**Props:**
- `justify` (string, default: 'end') — end/between/center

**仕様:**
- spec.md §2.5
- `bg-slate-50 dark:bg-gray-900` + `border-t dark:border-gray-700`
- `$slot` でボタン群挿入

#### 5. form-group.blade.php

**Props:**
- `label` (string, required) — ラベルテキスト

**仕様:**
- spec.md §4.1 のラベル + `$slot` で入力要素
- ラベル: `text-xs font-medium text-slate-600 dark:text-gray-400 mb-1`

#### 6. confirm.blade.php

**Props:**
- `alpine-var` (string, required) — 表示制御変数
- `title` (string, required) — 確認タイトル
- `message` (string, required) — 確認メッセージ
- `icon` (string, default: 'exclamation-triangle') — アイコン
- `icon-color` (string, default: 'red') — アイコンカラー（red/orange/blue）
- `confirm-label` (string, default: '実行') — 確認ボタンラベル
- `confirm-color` (string, default: 'red') — 確認ボタンカラー

**仕様:**
- spec.md §5.6 の確認ダイアログをワンショットコンポーネント化
- container + header不要で直接使える

### 完了条件

- [ ] 6つのBladeファイルが `resources/views/components/modal/` に作成されている
- [ ] `php artisan view:clear && php artisan view:cache` でエラーなし
- [ ] 各コンポーネントがspec.md のデザイン仕様に準拠
- [ ] ダークモード対応クラスが全コンポーネントに適用

---

## P2: 動作検証用サンプルモーダル

### 目的

P1で作成した共通コンポーネントの表示・動作を実際のブラウザで検証する。

### 手順

1. 検証用Bladeビューを1ファイル作成（`resources/views/filament/pages/modal-showcase.blade.php` 等）
2. 6タイプ（filter, column-select, form, detail, tabbed, confirm）のサンプルモーダルを配置
3. 各ボタンでモーダルを開閉できるようにする
4. ブラウザで以下を確認:
   - レイアウトの正確性（spec.md のスクリーンショットと比較）
   - トランジションの滑らかさ
   - スクロール動作（コンテンツがmax-heightを超える場合）
   - ESC / Backdropクリックでの閉じ動作
   - ダークモード切替時の表示
   - ネストモーダル（z-index）の動作

### 完了条件

- [ ] 6タイプ全てのサンプルが正常に表示される
- [ ] 開閉・トランジション・スクロールが正常動作
- [ ] ダークモードで視認性に問題なし
- [ ] 検証完了後、サンプルファイルは残す（開発リファレンス用）

---

## P3: 既存モーダル移行（高優先度）

### 目的

使用頻度の高いモーダル3ファイルを共通コンポーネントに移行する。

### 対象ファイル

1. `resources/views/filament/modals/trade-detail.blade.php` → Type: detail
2. `resources/views/filament/resources/real-stocks/modal/stock-detail.blade.php` → Type: detail
3. `resources/views/livewire/trade-detail-modal.blade.php` → Type: detail/form

### 手順

各ファイルについて:

1. 現在のモーダル構造を読み込む
2. 呼び出し元（Livewireコンポーネント / Filamentアクション）を特定
3. 共通コンポーネント（`x-modal.container` 等）に置換
4. 既存の `wire:model` / `wire:click` / Alpine バインディングを維持
5. 変更前後でブラウザ表示を確認

### 修正方針

- **コンテンツのみのモーダル**（外枠が呼び出し元にある場合）: 呼び出し元の外枠も共通コンポーネントに置換
- **機能変更なし**: デザインのみ変更。ロジック・データバインディングは一切変更しない
- **段階的**: 1ファイルずつ修正→確認→次のファイルへ

### 完了条件

- [ ] 3ファイル全て共通コンポーネントに置換
- [ ] 既存機能（表示/操作/データ連携）が全て正常動作
- [ ] デザインがspec.md に準拠

---

## P4: 既存モーダル移行（中優先度）

### 目的

フロアプラン関連とピッキングログのモーダル3ファイルを移行する。

### 対象ファイル

1. `resources/views/filament/pages/floor-plan-editor/zone-edit-modal.blade.php` → Type: form
2. `resources/views/filament/pages/floor-plan-editor/add-zone-modal.blade.php` → Type: form
3. `resources/views/filament/resources/wms-picking-logs/view-modal.blade.php` → Type: detail

### 手順

P3と同様の手順。特にフロアプランエディタのモーダルは:
- Navy header (`#1e3a5f`) → `bg-slate-50` に変更
- エラーメッセージ表示ロジックの維持を注意
- フロアプラン上の座標データ連携に影響しないこと

### 完了条件

- [ ] 3ファイル全て共通コンポーネントに置換
- [ ] フロアプランエディタの全操作（ゾーン追加/編集/削除）が正常動作
- [ ] ピッキングログ表示が正常

---

## P5: 既存モーダル移行（低優先度）

### 目的

残りの2ファイルを移行して全モーダルの統一を完了する。

### 対象ファイル

1. `resources/views/filament/resources/wms-auto-order-job-controls/result-modal.blade.php` → Type: tabbed
2. `resources/views/filament/components/transmission-detail-modal.blade.php` → Type: detail

### 手順

P3と同様の手順。result-modal はタブ構造を持つため `tabbed` テンプレートを参考にする。

### 完了条件

- [ ] 2ファイル全て共通コンポーネントに置換
- [ ] 既存機能が全て正常動作
- [ ] WMS全モーダルがspec.md 準拠のデザインに統一

---

## 制約（厳守）

1. **migrate:fresh / migrate:refresh / db:wipe 禁止** — CLAUDE.md 準拠
2. **既存ロジック変更禁止** — デザインのみ変更。データバインディング・イベント処理は維持
3. **段階的移行** — Phase 3-5 は1ファイルずつ修正→確認
4. **ダークモード必須** — spec.md §9 のダークモード対応クラスを全コンポーネントに適用
5. **Navy header禁止** — `#1e3a5f` は使わない。`bg-slate-50` に統一
6. **FontAwesome使用** — アイコンは `fa fa-{name}` 形式

## 全体完了条件

- [ ] 共通コンポーネント6ファイルが作成・動作確認済み
- [ ] 既存モーダル8ファイル全てが共通コンポーネントに移行
- [ ] 全モーダルのデザインが spec.md に準拠
- [ ] ダークモード表示に問題なし
- [ ] 既存機能の回帰テスト（各画面の主要操作確認）
