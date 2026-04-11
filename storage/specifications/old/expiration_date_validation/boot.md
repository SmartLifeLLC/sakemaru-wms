# Work Plan: expiration-date-alert

- **ID**: expiration-date-alert
- **作成日**: 2026-02-25
- **最終更新**: 2026-02-25
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/expiration_date_validation/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

フロアプランエディタの在庫リストで、`real_stock_lots.alert_date` を使った賞味期限アラート表示を実装する。現在のハードコード30日判定を、商品ごとに設定された `alert_date`（入荷確定時に `expiration_date - items.expiration_alert_days` で計算済み）ベースの判定に置き換える。

## 重要な設計制約

- **FK禁止**: 全テーブルにForeignKeyは作成しない
- **DB破壊禁止**: `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` は絶対禁止
- **alert_date は入荷確定時に設定済み**: バックエンドでの計算ロジック追加は不要
- **既存UIの動作を壊さない**: alert_date が NULL の場合は従来通りアラートなし

## データ確認結果

### items テーブル
- `uses_expiration_date` (tinyint, nullable) - 賞味期限を使うか
- `default_expiration_days` (int unsigned, nullable) - デフォルト賞味期限日数
- `expiration_alert_days` (int unsigned, nullable) - アラート基準日数

### real_stock_lots テーブル
- `expiration_date` (date, nullable) - 賞味期限
- `alert_date` (date, nullable) - アラート日（入荷確定時に計算済み）
  - 計算式: `expiration_date - expiration_alert_days`
  - 例: 賞味期限 12/30, alert_days 30 → alert_date = 11/30
  - 賞味期限なし or alert_days が null → alert_date = null

### 現在の実装
- `isExpirationNear()`: 30日ハードコードで判定（alert_date 未使用）
- FloorPlanController::getZoneStocks(): `alert_date` をクエリに含めていない
- zone-edit-modal.blade.php: expiration_date のみ表示

## 対象ファイル

### 新規作成
- なし

### 既存変更
- `app/Http/Controllers/Api/FloorPlanController.php` - getZoneStocks() に alert_date 追加
- `resources/views/filament/pages/floor-plan-editor.blade.php` - isExpirationNear() を alert_date ベースに変更
- `resources/views/filament/pages/floor-plan-editor/zone-edit-modal.blade.php` - アラート表示の改善

### 参照のみ（変更禁止）
- `app/Models/Sakemaru/Item.php`
- `app/Models/Sakemaru/RealStockLot.php`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: APIクエリに alert_date 追加 | 完了 | 2026-02-25 | FloorPlanController |
| P1: アラート判定ロジック変更 | 完了 | 2026-02-25 | isExpirationNear → alert_date ベース |
| P2: UI表示改善 | 完了 | 2026-02-25 | 期限切れ・アラート中の視覚的区別 |
| P3: 動作確認 | 完了 | 2026-02-25 | php -l, Pint |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### FloorPlanController クエリ位置
- ファイル: `app/Http/Controllers/Api/FloorPlanController.php`
- メソッド: `getZoneStocks()` (line 330-398)
- selectに `rsl.alert_date` を追加する箇所: line 360付近

### isExpirationNear 関数位置
- ファイル: `resources/views/filament/pages/floor-plan-editor.blade.php`
- 行: 2216-2223
- 現在: 30日ハードコード → alert_date との比較に変更

### zone-edit-modal 表示位置
- ファイル: `resources/views/filament/pages/floor-plan-editor/zone-edit-modal.blade.php`
- 賞味期限カラム: line 138 (header), line 160-162 (data)

### Git ブランチ
- 作業ブランチ: feature/stock-transfer-map-view（現在のブランチで継続 or 新ブランチ作成）
- ベースブランチ: main

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P0: APIクエリに alert_date 追加
- 完了日: 2026-02-25
- 実績:
  - `FloorPlanController::getZoneStocks()` の SELECT に `rsl.alert_date` を追加
  - レスポンス配列に `alert_date` フィールドを追加

### P1: アラート判定ロジック変更
- 完了日: 2026-02-25
- 実績:
  - `isExpirationNear()` を `alertDate` 引数ベースに書き換え（30日ハードコード削除）
  - `isExpired()` 関数を新規追加（today > expiration_date で判定）

### P2: UI表示改善
- 完了日: 2026-02-25
- 実績:
  - zone-edit-modal の賞味期限セルを3段階表示に変更
    - 期限切れ: `text-red-600 bg-red-50 font-bold`
    - アラート期間中: `text-amber-600 font-bold`
    - 通常: `text-gray-500`

### P3: 動作確認
- 完了日: 2026-02-25
- 実績:
  - `php -l FloorPlanController.php` → 構文エラーなし
  - `./vendor/bin/pint --dirty` → PASS (1 file)
