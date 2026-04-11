# JX伝票番号照合修正 作業計画

## 前提

- JxIncomingParser のDレコードパース位置は正しい（送信レイアウト=品名64バイト）
- 商品コード変換（d_item_code → items.code）は正しく動作する
- **伝票番号フォーマットを11桁数字のみに統一する**（ハイフン禁止）
- DBにはまだ伝票番号データが存在しない（フォーマット変更の影響なし）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | generateSlipNumber() を11桁数字に変更 | DB保存フォーマットを `YYYYMMDDNNN`（11桁数字のみ）に変更 | `20260303001` 形式で採番される |
| P2 | HanaOrderJXFileGenerator 送信側の修正 | DB伝票番号をそのままBレコードに書き込み（変換不要に） | 11桁が直接Bレコードに収まる |
| P3 | JxIncomingParser パーサーの修正 | `formatSlipNumber()` を削除/簡素化（変換不要に） | 受信伝票番号がそのままDB照合に使える |
| P4 | 仕様書の更新 | jx-incoming-data-specification.md のDレコード位置を修正 | 仕様書と実データが一致する |
| P5 | テスト・検証 | 送受信往復の整合性を確認 | テスト全パス |

---

## P1: generateSlipNumber() を11桁数字に変更

### 目的

`WmsOrderIncomingSchedule::generateSlipNumber()` のフォーマットを `YYYYMMDD-NNNNN`（14文字ハイフン付き）から `YYYYMMDDNNN`（11桁数字のみ）に変更する。

### 現状

```php
// app/Models/WmsOrderIncomingSchedule.php L200-213
public static function generateSlipNumber(?string $orderDate = null): string
{
    $date = $orderDate ?? now()->format('Y-m-d');
    $dateStr = Carbon::parse($date)->format('Ymd');
    $prefix = $dateStr.'-';                                    // ← ハイフン付き

    $maxSlip = self::where('slip_number', 'like', $prefix.'%')
        ->orderByRaw('CAST(SUBSTRING(slip_number, 10) AS UNSIGNED) DESC')  // ← 10文字目から
        ->value('slip_number');

    $nextSeq = $maxSlip ? (int) substr($maxSlip, 9) + 1 : 1;  // ← 9文字目から
    return $prefix.str_pad($nextSeq, 5, '0', STR_PAD_LEFT);   // ← 5桁連番
}
```

### 修正方針

```php
/**
 * 伝票番号を採番
 *
 * フォーマット: {YYYYMMDD}{連番3桁} = 11桁数字のみ
 * 例: 20260305001
 * JX Bレコードの伝票番号フィールド（11バイト）にそのまま格納可能
 *
 * @param  string|null  $orderDate  発注日（Y-m-d形式）。nullの場合は今日
 */
public static function generateSlipNumber(?string $orderDate = null): string
{
    $date = $orderDate ?? now()->format('Y-m-d');
    $dateStr = Carbon::parse($date)->format('Ymd');

    $maxSlip = self::where('slip_number', 'like', $dateStr.'%')
        ->where('slip_number', 'REGEXP', '^[0-9]{11}$')
        ->orderByRaw('CAST(SUBSTRING(slip_number, 9) AS UNSIGNED) DESC')
        ->value('slip_number');

    $nextSeq = $maxSlip ? (int) substr($maxSlip, 8) + 1 : 1;

    return $dateStr.str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
}
```

### 修正対象ファイル

- `app/Models/WmsOrderIncomingSchedule.php`
  - `generateSlipNumber()` L200-213

### 呼び出し元（変更不要だが動作確認）

- `app/Services/AutoOrder/OrderExecutionService.php` L175, L198, L272
- `app/Services/AutoOrder/TransferCandidateExecutionService.php` L217

### 完了条件

- `generateSlipNumber('2026-03-03')` → `20260303001`（11桁数字のみ）
- 連番が `001`, `002`, ... `999` と採番されること

---

## P2: HanaOrderJXFileGenerator 送信側の修正

### 目的

`generateBRecord()` の伝票番号変換ロジックを修正。DB保存値が11桁数字のみになるため、ハイフン除去ロジックを簡素化する。

### 現状の問題

```php
// L346-350
if ($slipNumber) {
    $slipNumber = str_replace('-', '', $slipNumber);
    // 旧: "20260303-00001" → "2026030300001" = 13文字 → オーバーフロー
}
```

### 修正方針

DB値が既に11桁数字のみなので、そのまま使用。安全のため11桁に切り詰め。

```php
// 伝票番号: DB値（11桁数字のみ）をそのまま使用
if ($slipNumber) {
    $slipNumber = substr($slipNumber, 0, 11);
} else {
    $slipNumber = $orderDate->format('Ymd').str_pad($seq, 3, '0', STR_PAD_LEFT);
}
```

### 修正対象ファイル

- `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php`
  - `generateBRecord()` L346-350

### 完了条件

- DB伝票番号 `20260303001` がBレコードの11バイトフィールドに正確に収まること
- fallback（DBなし）でも11桁で動作すること

---

## P3: JxIncomingParser パーサーの修正

### 目的

`formatSlipNumber()` を修正。受信データの11桁伝票番号をそのままDB保存フォーマットとして使用する。

### 現状の問題

```php
// L281-294 — ハイフンを挿入する変換を行っている
private function formatSlipNumber(string $raw): string
{
    if (str_contains($raw, '-')) {
        return $raw;
    }
    if (strlen($raw) >= 9 && ctype_digit(substr($raw, 0, 8))) {
        return substr($raw, 0, 8) . '-' . substr($raw, 8);  // ← ハイフン挿入
    }
    return $raw;
}
```

### 修正方針

ハイフン挿入を削除し、11桁数字のみをそのまま返す。

```php
/**
 * 伝票番号を正規化
 *
 * JX Bレコードの11桁をそのまま使用（ハイフンなし、数字のみ）
 * 旧形式のハイフン付きデータが来た場合はハイフンを除去
 */
private function formatSlipNumber(string $raw): string
{
    // ハイフンが含まれていれば除去
    return str_replace('-', '', $raw);
}
```

### 修正対象ファイル

- `app/Services/AutoOrder/IncomingParsers/JxIncomingParser.php`
  - `formatSlipNumber()` L281-294
  - L172 のコメントも更新

### 完了条件

- JX 11桁 `20260303001` → そのまま `20260303001` が返ること
- 旧形式 `20260303-001` → `20260303001` に正規化されること

---

## P4: 仕様書の更新

### 目的

`jx-incoming-data-specification.md` のDレコード位置定義を実データに合わせて修正する。

### 修正内容

Dレコードの位置テーブルを送信レイアウト（品名64バイト）に更新:

| 位置 | 桁数 | 型 | 項目名 | 備考 |
|------|------|-----|--------|------|
| 1 | 1 | X | レコード区分 | `D` 固定 |
| 2-3 | 2 | 9 | データ種別 | 01=発注, 02=納品 |
| 4-5 | 2 | 9 | 伝票行番号 | 01〜06 |
| 6-69 | 64 | N | 品名 | SJIS 64バイト。先頭「×」= 欠品の場合あり |
| 70-82 | 13 | X | JANコード | |
| 83-88 | 6 | X | 自社コード | items.code |
| 89-94 | 6 | 9 | 入数 | 1ケースあたり |
| 95-101 | 7 | 9 | ケース数 | |
| 102-108 | 7 | 9 | バラ数量 | |
| 109-118 | 10 | 9 | 原単価 | 整数8桁+小数2桁 |
| 119-128 | 10 | X | FILLER | |

注記追加: 「送信・受信とも同一レイアウト（品名64バイト）」

### 修正対象ファイル

- `storage/.../jx-incoming-data-specification.md`

### 完了条件

- 仕様書のDレコード位置が実データのパース結果と一致すること

---

## P5: テスト・検証

### 目的

P1-P4の修正が正しく動作し、送信→受信→照合の往復で伝票番号が一致することを検証する。

### テスト手順

1. **ユニットテスト**: `generateSlipNumber()` テスト
   - 出力が11桁数字のみであること
   - 連番が正しくインクリメントされること

2. **ユニットテスト**: `formatSlipNumber()` テスト
   - 入力 `20260303001` → 出力 `20260303001`（そのまま）
   - 入力 `20260303-001` → 出力 `20260303001`（ハイフン除去）

3. **結合テスト**: 送信→受信の往復
   - `generateSlipNumber()` で DB伝票番号を生成 → `20260303001`
   - JX送信: そのまま11桁でBレコードに格納
   - JX受信: Bレコードから11桁取得 → `formatSlipNumber()` → `20260303001`
   - DB照合: `20260303001` == `20260303001` → **一致**

4. **既存テスト回帰**: `php artisan test` で全テスト通過

### 修正対象ファイル

- `tests/Unit/Services/AutoOrder/JxIncomingParserTest.php`（新規 or 既存に追加）

### 完了条件

- 全テストパス
- 送受信往復で伝票番号が一致する

---

## 制約（厳守）

1. **DB破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh`, `db:wipe` 等は絶対に実行しない
2. **FK禁止**: 外部キーは使用しない
3. **IncomingReceiveService は変更しない**: 照合ロジック（slip_number 文字列一致）は維持
4. **伝票番号は11桁数字のみ**: ハイフン・記号禁止
5. **JX Bレコード 伝票番号フィールドは11バイト固定**: 連番は3桁（最大999件/日）
6. **既存テストを壊さない**: 全テスト回帰パスが必須

## 全体完了条件

1. DB伝票番号 = JX伝票番号 = `YYYYMMDDNNN`（11桁数字のみ）で統一されている
2. 送信→受信→照合で伝票番号が変換なしで一致する
3. 仕様書のDレコード位置が実データと一致する
4. 全テストパス
