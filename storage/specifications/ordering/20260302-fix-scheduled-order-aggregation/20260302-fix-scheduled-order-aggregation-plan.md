# スケジュール発注 集約先候補生成対応 作業計画

## 前提

- 仕様書: `20260302-fix-scheduled-order-aggregation.md`
- 現在のブランチ: `feature/ordering-update`
- ファイル送信フェーズは変更不要（既に `groupByTransmissionContractor()` で正しく動作）
- 参考実装: `JxTestFileGenerator::getAllContractorIdsForJxSetting()` — 親+子展開の正しいパターン

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | モデル展開メソッド追加 | WmsContractorSetting に子仕入先展開メソッドを追加 | メソッド追加、tinker で動作確認 |
| P2 | 候補生成フロー修正 | CalcService / Job / ScheduledCommand を子仕入先対応 | 全3ファイル修正完了、構文エラーなし |
| P3 | 動作検証 | dry-run でカナカン系子仕入先の候補が生成されることを確認 | 候補生成数が増加、ファイル送信に影響なし |

---

## P1: モデル展開メソッド追加

### 目的

`WmsContractorSetting` に、指定仕入先IDの子仕入先（`transmission_contractor_id` で集約される仕入先）を展開するstaticメソッドを追加する。P2 以降の全変更箇所で共通利用する。

### 修正対象ファイル

- `app/Models/WmsContractorSetting.php`

### 修正内容

以下の2メソッドを追加:

```php
/**
 * 指定仕入先に集約される子仕入先IDを取得
 */
public static function getChildContractorIds(int $parentContractorId): array
{
    return self::where('transmission_contractor_id', $parentContractorId)
        ->pluck('contractor_id')
        ->toArray();
}

/**
 * 親 + 子の全仕入先IDを取得（子がいない場合は自身のみ）
 */
public static function getContractorIdsWithChildren(int $contractorId): array
{
    $ids = [$contractorId];
    $children = self::getChildContractorIds($contractorId);
    return array_unique(array_merge($ids, $children));
}
```

### 完了条件

- メソッドが追加されていること
- `php artisan tinker` で以下が正しく動作すること:
  ```php
  WmsContractorSetting::getChildContractorIds(1106);
  // → [1021, 1029, 1068, 1126, 1127, 1680]

  WmsContractorSetting::getContractorIdsWithChildren(1106);
  // → [1106, 1021, 1029, 1068, 1126, 1127, 1680]

  // 子がいない仕入先
  WmsContractorSetting::getContractorIdsWithChildren(1001);
  // → [1001]
  ```

---

## P2: 候補生成フロー修正

### 目的

親仕入先の発注候補生成時に、子仕入先の候補も同時に生成されるよう、3ファイルを修正する。

### 修正対象ファイル

1. `app/Services/AutoOrder/OrderCandidateCalculationService.php`
2. `app/Jobs/ProcessOrderCandidateGenerationJob.php`
3. `app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php`

### 修正内容

#### 2-1. OrderCandidateCalculationService

**プロパティ変更:**
- `private ?int $targetContractorId = null;` → `private ?array $targetContractorIds = null;`

**calculate() メソッド内（Line 155付近）:**
```php
// Before:
$this->targetContractorId = $contractorId;

// After:
if ($contractorId !== null) {
    $this->targetContractorIds = WmsContractorSetting::getContractorIdsWithChildren($contractorId);
} else {
    $this->targetContractorIds = null;
}
```

**INTERNAL候補フィルタ（Line 511付近）:**
```php
// Before:
if ($this->targetContractorId !== null) {
    $internalContractorIds = array_intersect($internalContractorIds, [$this->targetContractorId]);

// After:
if ($this->targetContractorIds !== null) {
    $internalContractorIds = array_intersect($internalContractorIds, $this->targetContractorIds);
```

**EXTERNAL候補フィルタ（Line 720付近）:**
```php
// Before:
if ($this->targetContractorId !== null) {
    $externalQuery->where('contractor_id', $this->targetContractorId);
}

// After:
if ($this->targetContractorIds !== null) {
    $externalQuery->whereIn('contractor_id', $this->targetContractorIds);
}
```

**他に `targetContractorId` を参照している箇所がないか grep で確認すること。**

#### 2-2. ProcessOrderCandidateGenerationJob

**排他チェック（Line 99-118付近）:**

`$this->contractorId` を `WmsContractorSetting::getContractorIdsWithChildren()` で展開し、`whereIn` に変更。

```php
if ($this->contractorId !== null) {
    $allContractorIds = WmsContractorSetting::getContractorIdsWithChildren($this->contractorId);

    if (! $this->transferOnly) {
        $hasOrderCandidates = WmsOrderCandidate::query()
            ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
            ->whereIn('contractor_id', $allContractorIds)
            ->exists();
    }

    $hasTransferCandidates = WmsStockTransferCandidate::query()
        ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
        ->whereIn('contractor_id', $allContractorIds)
        ->exists();

    if ($hasOrderCandidates || $hasTransferCandidates) {
        throw new \RuntimeException("仕入先ID:{$this->contractorId}（+子仕入先）に未処理の候補が存在します");
    }
}
```

#### 2-3. AutoOrderScheduledCommand

**未処理候補チェック（Line 60-76付近）:**

```php
$allContractorIds = WmsContractorSetting::getContractorIdsWithChildren($contractorId);

$hasPendingOrders = WmsOrderCandidate::query()
    ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
    ->whereIn('contractor_id', $allContractorIds)
    ->exists();

$hasPendingTransfers = WmsStockTransferCandidate::query()
    ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
    ->whereIn('contractor_id', $allContractorIds)
    ->exists();
```

### 完了条件

- 3ファイル全ての修正が完了
- `php artisan tinker` で構文エラーなし
- `targetContractorId` への参照が残っていないこと（grep で確認）

---

## P3: 動作検証

### 目的

修正後に実際の候補生成を実行し、子仕入先の候補が正しく生成されることを確認する。

### 検証手順

#### 3-1. 修正前の状態確認

```sql
-- カナカン系子仕入先の既存候補数を確認
SELECT contractor_id, COUNT(*) as cnt
FROM wms_order_candidates
WHERE contractor_id IN (1021, 1029, 1068, 1126, 1127, 1680)
AND status = 'PENDING'
GROUP BY contractor_id;
```

#### 3-2. 候補生成の実行

手動生成UIまたはコマンドで、親仕入先（1106）を指定して候補生成を実行。

```
仕入先別発注候補生成 → contractor_id = 1106
```

#### 3-3. 修正後の確認

```sql
-- 子仕入先の候補が生成されているか
SELECT contractor_id, COUNT(*) as cnt
FROM wms_order_candidates
WHERE contractor_id IN (1106, 1021, 1029, 1068, 1126, 1127, 1680)
AND batch_code = '{最新バッチコード}'
GROUP BY contractor_id;
```

**期待結果:**
- 1106（親）の候補が生成される（修正前と同じ）
- 1021, 1029, 1068, 1126, 1127, 1680（子）の候補が**新たに生成される**

#### 3-4. ファイル送信への影響確認

ファイル送信フェーズは変更していないが、候補が増えたことで `groupByTransmissionContractor()` が正しく動作するか確認。

- 子仕入先の候補が親（1106）のファイルに正しく集約されること
- 子仕入先用に別ファイルが生成されないこと

### 完了条件

- 子仕入先（1021等）の候補が生成されること
- 候補の `contractor_id` が正しく子仕入先のIDであること（親IDに書き換えられていないこと）
- ファイル送信時に親の1ファイルに集約されること
- 子が存在しない仕入先（例: 1001）の動作が変わらないこと

---

## 制約（厳守）

- FK禁止（CLAUDE.md記載）
- `migrate:fresh` / `migrate:refresh` / `db:wipe` 禁止
- DB変更（マイグレーション）なし
- ファイル送信フェーズ（`HanaOrderJXFileGenerator`, `OrderTransmissionService`）は変更禁止
- `TRANSMISSION_MAPPING` ハードコードの変更は今回のスコープ外

## 全体完了条件

1. 親仕入先のスケジュールトリガーで子仕入先の候補も生成される
2. 子仕入先が存在しない仕入先の動作に影響がない
3. ファイル送信で子仕入先の候補が親のファイルに集約される
4. 手動生成UI（仕入先別発注候補生成）でも同じ展開が機能する
