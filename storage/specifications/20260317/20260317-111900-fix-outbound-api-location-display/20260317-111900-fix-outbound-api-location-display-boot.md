# Work Plan: fix-outbound-api-location-display

- **ID**: fix-outbound-api-location-display
- **作成日**: 2026-03-17
- **最終更新**: 2026-03-17
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260317/20260317-111900-fix-outbound-api-location-display/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260317-111900-fix-outbound-api-location-display-boot.md）
2. 20260317-111900-fix-outbound-api-location-display-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

出荷ピッキングAPIが `picking_area.name`（温度帯名）を返しており、Android側でロケーション番号の代わりに「冷凍」等が表示される問題を修正。`formatItemResult()` にロケーション情報を追加する。

## 重要な設計制約

- FK禁止: `location_id` はアプリケーションレベルの参照
- `migrate:fresh` / `migrate:refresh` 禁止
- `locations` テーブルは基幹システム（sakemaru）との共有テーブル — 変更禁止
- 後方互換: 既存フィールドの変更・削除は不可。新フィールド追加のみ

## 対象ファイル

### 既存変更
- `app/Http/Controllers/Api/PickingTaskController.php` — `formatItemResult()`, `index()`, `show()`, `showItem()`

### 参照のみ（変更禁止）
- `app/Models/WmsPickingItemResult.php` — `location()` リレーション確認
- `app/Models/Sakemaru/Location.php` — `code1`, `code2`, `code3`, `name` フィールド確認
- `app/Models/WmsPickingArea.php` — 現状の `code`, `name` 確認
- `tests/Feature/Api/PickingApiTest.php` — テスト構造の参考

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: formatItemResult にロケーション追加 | 完了 | 2026-03-17 | eager load + location フィールド追加 |
| P2: showItem にロケーション追加 | 完了 | 2026-03-17 | DB直接クエリでlocation取得追加 |
| P3: 動作確認 | 完了 | 2026-03-17 | PickingApiTest 5テスト全通過 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 現状のAPI構造
- `formatItemResult()`: Line 21-93 — ロケーション情報なし
- `index()`: Line 231-322 — `with()` に `pickingItemResults.location` なし
- `show()`: Line 359-420 — 同上
- `showItem()`: Line 457- — DBクエリ直接（Eloquent未使用）、location JOIN なし

### Location モデル情報
- テーブル: `locations`（sakemaru接続）
- ロケーションコード: `code1`, `code2`, `code3` の3分割（例: "R01" "A" "5"）
- 表示形式: `"{code1} {code2} {code3}"` （WmsPickingItemResult::getLocationDisplayAttribute 参照）

### Git ブランチ
- 作業ブランチ: release/v1.0（現在のブランチ）
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: formatItemResult にロケーション追加
- 完了日: 2026-03-17
- 実績:
  - `formatItemResult()` に `location` フィールド（code, name）追加
  - `index()` と `show()` に `pickingItemResults.location` eager load 追加

### P2: showItem にロケーション追加
- 完了日: 2026-03-17
- 実績:
  - `showItem()` に locations テーブルからのDB直接クエリ追加
  - レスポンスに `location` フィールド追加

### P3: 動作確認
- 完了日: 2026-03-17
- 実績:
  - `PickingApiTest` 5テスト全通過（25 assertions）
  - 構文エラーなし
