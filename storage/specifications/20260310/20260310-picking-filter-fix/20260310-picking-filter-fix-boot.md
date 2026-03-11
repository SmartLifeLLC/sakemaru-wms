# Work Plan: picking-filter-fix

- **ID**: picking-filter-fix
- **作成日**: 2026-03-10
- **最終更新**: 2026-03-10
- **ステータス**: 進行中
- **ディレクトリ**: `storage/specifications/202503100000/20260310-picking-filter-fix/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260310-picking-filter-fix-boot.md）
2. 20260310-picking-filter-fix-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

出荷・ピッキング対象のフィルタ欠落を修正。trade_items.is_active、trades.trade_direction（NORMAL以外を除外、ただしSPONSORは対象）、trade_items.quantity > 0 のフィルタを波動生成・配送コース変更画面に追加する。

## 重要な設計制約

- FK禁止
- `migrate:fresh/refresh/reset` 絶対禁止
- DB変更なし
- `whereHas` 使用禁止 → `earnings.trade_id` を利用した JOIN で高速化
- ピッキング対象: `trade_direction` が `NORMAL` または `SPONSOR` のみ
- `RETURN`（返品）、`INVENTORY`（在庫調整）、`ITEM_SET`（セット調整）は除外
- `is_active = true` で統一（`is_deleted` は使わない）
- `quantity > 0` で除外（発注数量0は除外、出荷数量0は除外しない）

## 確認済み回答

1. Earning モデルの trade リレーション: 存在する。ただし `whereHas` ではなく `trade_id` JOIN を使う
2. SPONSOR（協賛）: **出荷対象である**（除外しない）
3. INVENTORY / ITEM_SET: **除外する**
4. is_active vs is_deleted: `is_active` を使用（統一）
5. マイナス数量: `quantity > 0` で除外。数量0は除外しない

## 対象ファイル

### 既存変更
- `app/Console/Commands/GenerateWavesCommand.php` — 4箇所修正
- `app/Filament/Resources/Waves/Pages/ListWaves.php` — 4箇所修正
- `app/Livewire/TradeDetailModal.php` — 1箇所修正
- `app/Filament/Resources/DeliveryCourseChangeResource.php` — 1箇所修正

### 参照のみ（変更禁止）
- `app/Models/Sakemaru/Earning.php`
- `app/Enums/Partners/ETradeDirection.php`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: GenerateWavesCommand.php 修正 | 未着手 | - | |
| P1: ListWaves.php 修正 | 未着手 | - | |
| P2: 配送コース変更画面修正 | 未着手 | - | |
| P3: 検証・Pint | 未着手 | - | |

---

## 作業中コンテキスト

### trade_direction フィルタ条件
- 対象: `NORMAL`, `SPONSOR`
- 除外: `RETURN`, `INVENTORY`, `ITEM_SET`
- フィルタ方法: `JOIN trades ON earnings.trade_id = trades.id` + `WHERE trades.trade_direction IN ('NORMAL', 'SPONSOR')`

### is_deleted 調査結果
- `is_deleted` は GenerateWavesCommand.php L418 の1箇所のみ使用
- `is_active` に統一する

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P0: GenerateWavesCommand.php 修正
- 完了日: -
- 実績:
  - (完了後に記入)

### P1: ListWaves.php 修正
- 完了日: -
- 実績:
  - (完了後に記入)

### P2: 配送コース変更画面修正
- 完了日: -
- 実績:
  - (完了後に記入)

### P3: 検証・Pint
- 完了日: -
- 実績:
  - (完了後に記入)
