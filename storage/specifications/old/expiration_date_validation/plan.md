# 賞味期限アラート表示 作業計画

## 前提

- `items.expiration_alert_days` / `items.default_expiration_days` カラムは存在する
- `real_stock_lots.alert_date` (nullable date) は存在する
- `alert_date` は入荷確定時に計算済み: `expiration_date - expiration_alert_days`
- 現在の `isExpirationNear()` は30日ハードコードで `alert_date` を使用していない
- FloorPlanController の getZoneStocks() は `alert_date` をクエリに含めていない

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | APIクエリに alert_date 追加 | getZoneStocks() のSELECTに alert_date を追加 | APIレスポンスに alert_date が含まれる |
| P1 | アラート判定ロジック変更 | isExpirationNear() を alert_date ベースに書き換え | today >= alert_date で true を返す |
| P2 | UI表示改善 | アラート中・期限切れ・通常の3段階表示 | 視覚的に区別できる |
| P3 | 動作確認 | 構文チェック・Pint | エラーなし |

---

## P0: APIクエリに alert_date 追加

### 目的

FloorPlanController の getZoneStocks() が返す在庫データに `alert_date` を含める。

### 修正対象ファイル

- `app/Http/Controllers/Api/FloorPlanController.php`

### 修正内容

`getZoneStocks()` メソッド（line 330-398）の select 配列に追加:

```php
'rsl.alert_date',
```

### 完了条件

- `alert_date` がAPIレスポンスのstock itemに含まれる

---

## P1: アラート判定ロジック変更

### 目的

ハードコード30日判定を `alert_date` ベースに置き換える。

### 修正対象ファイル

- `resources/views/filament/pages/floor-plan-editor.blade.php`

### 修正内容

`isExpirationNear()` 関数を以下に変更:

```javascript
isExpirationNear(alertDate) {
    if (!alertDate) return false;
    const alert = new Date(alertDate);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return today >= alert;
}
```

- `alert_date` が NULL → アラートなし（false）
- today >= alert_date → アラート表示（true）
- today < alert_date → まだ余裕あり（false）

### 完了条件

- `alert_date` を基準にアラート判定される
- ハードコード30日の判定が削除されている

---

## P2: UI表示改善

### 目的

在庫リストの賞味期限カラムで以下の3段階を視覚的に区別する:

1. **期限切れ**（today > expiration_date）: 赤背景 + 太字
2. **アラート期間中**（today >= alert_date かつ期限内）: オレンジ/黄色 + 太字
3. **通常**（alert_date 未到達 or NULL）: グレー通常表示

### 修正対象ファイル

- `resources/views/filament/pages/floor-plan-editor/zone-edit-modal.blade.php`
- `resources/views/filament/pages/floor-plan-editor.blade.php`（必要に応じて追加関数）

### 修正内容

zone-edit-modal.blade.php の賞味期限セル（line 160-162）:

```html
<td class="px-3 py-2.5 text-center"
    :class="isExpired(item.expiration_date) ? 'text-red-600 bg-red-50 font-bold' :
            isExpirationNear(item.alert_date) ? 'text-amber-600 font-bold' : 'text-gray-500'"
    x-text="item.expiration_date || '-'"></td>
```

floor-plan-editor.blade.php に `isExpired()` 関数を追加:

```javascript
isExpired(expirationDate) {
    if (!expirationDate) return false;
    const expDate = new Date(expirationDate);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return today > expDate;
}
```

### 完了条件

- 期限切れ: 赤背景
- アラート期間中: オレンジ太字
- 通常: グレー
- NULL: `-` 表示（スタイルなし）

---

## P3: 動作確認

### 手順

1. `php -l` で全変更ファイルの構文チェック
2. `./vendor/bin/pint --dirty` でフォーマットチェック

### 完了条件

- 構文エラーなし
- Pint PASS

---

## 制約（厳守）

- DB破壊コマンド禁止（migrate:fresh等）
- FK作成禁止
- `alert_date` の計算ロジックは入荷確定時に実装済み。本タスクではUIのみ変更
- 既存の在庫リスト機能を壊さない

## 全体完了条件

- フロアプランエディタの在庫リストで `alert_date` ベースのアラート表示が動作
- 期限切れ・アラート中・通常の3段階が視覚的に区別可能
- `alert_date` が NULL の場合はアラート表示なし
