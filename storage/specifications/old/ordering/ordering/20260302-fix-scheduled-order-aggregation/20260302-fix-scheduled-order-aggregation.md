# スケジュール発注における集約先仕入先の候補生成対応

- **作成日**: 2026-03-02
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/ordering/20260302-fix-scheduled-order-aggregation/`

## 背景・目的

`wms:auto-order-scheduled` コマンドが5分ごとに実行され、仕入先別の自動発注候補を生成している。
一部の仕入先は「発注データ集約先」（`transmission_contractor_id`）が設定されており、
ファイル送信時に親仕入先のファイルに集約される仕組みになっている。

**問題:** 現在のスケジューラーは `transmission_contractor_id IS NULL` の仕入先のみを対象としており、
子仕入先（集約先が設定されている仕入先）の発注候補は**一切生成されない**。
ファイル送信フェーズで集約するロジックは存在するが、集約対象となる候補自体が存在しないため機能しない。

### 現在の集約設定（DB実データ）

| contractor_id | 仕入先名 | transmission_contractor_id | 集約先 |
|---|---|---|---|
| 1021 | カナカン酒類福井 | 1106 | カナカン食品 |
| 1029 | カナカンフローズン | 1106 | カナカン食品 |
| 1068 | カナカン酒類金沢 | 1106 | カナカン食品 |
| 1126 | カナカン日配 | 1106 | カナカン食品 |
| 1127 | カナカン菓子 | 1106 | カナカン食品 |
| 1680 | ※別仕入先 | 1106 | カナカン食品 |

## 現状の実装

### フロー全体像

```
[Phase 1: 候補生成]                    [Phase 2: ファイル送信]
AutoOrderScheduledCommand              OrderTransmissionService
  ↓ whereNull('transmission_...')        ↓
  ↓ 親仕入先のみ取得                     HanaOrderJXFileGenerator
  ↓                                       ↓ groupByTransmissionContractor()
ProcessOrderCandidateGenerationJob        ↓ transmission_contractor_id で集約
  ↓ contractorId = 親のみ                ↓ TRANSMISSION_MAPPING でフォールバック
  ↓                                       ↓
OrderCandidateCalculationService        結果: 子仕入先の候補をまとめてファイル生成
  ↓ where('contractor_id', 親ID)
  ↓
結果: 親仕入先の候補のみ生成
      子仕入先の候補は生成されない ← ★ ここが問題
```

### 問題箇所の特定

#### 1. スケジューラー（AutoOrderScheduledCommand:30-35）

```php
$settings = WmsContractorSetting::query()
    ->whereNull('transmission_contractor_id')  // ← 子仕入先を除外
    ->whereNotNull('auto_order_generation_time')
    ->where('auto_order_generation_time', '<=', $currentTime)
    ->where($currentDayColumn, true)
    ->get();
```

- 子仕入先（`transmission_contractor_id` が設定済み）はフィルタで除外される
- 親仕入先がスケジュール実行されても、子仕入先の候補生成は行われない

#### 2. 候補計算サービス（OrderCandidateCalculationService:720-723）

```php
if ($this->targetContractorId !== null) {
    $externalQuery->where('contractor_id', $this->targetContractorId);
}
```

- `targetContractorId` は完全一致フィルタ
- 親仕入先ID指定時に子仕入先IDを含めるロジックがない

#### 3. ファイル送信（HanaOrderJXFileGenerator:153-178）- 正常動作

```php
// groupByTransmissionContractor() は正しく集約する
// ただし候補が存在しないため、子仕入先分のデータがファイルに含まれない
```

### 参考: 正しいパターンの既存実装（JxTestFileGenerator:253-263）

```php
private function getAllContractorIdsForJxSetting(int $jxSettingId): array
{
    $mainContractorIds = $this->getContractorIdsForJxSetting($jxSettingId);
    $aggregatedSettings = WmsContractorSetting::whereIn('transmission_contractor_id', $mainContractorIds)->get();
    $aggregatedContractorIds = $aggregatedSettings->pluck('contractor_id')->toArray();
    return array_unique(array_merge($mainContractorIds, $aggregatedContractorIds));
}
```

テストファイル生成では「親 + 子」を正しく展開している。

## 変更内容

### 概要

スケジューラーが親仕入先を実行する際に、その仕入先に集約される子仕入先の候補も同時に生成されるようにする。
変更は **候補生成フェーズ（Phase 1）** のみ。ファイル送信フェーズ（Phase 2）は変更不要。

### 詳細設計

#### 方針: 親トリガー時に子も含めて計算

スケジューラーのトリガー対象は現状通り**親仕入先のみ**（`transmission_contractor_id IS NULL`）。
ただし、候補計算時に `targetContractorId` を**子仕入先IDも含む配列に展開**する。

#### A. WmsContractorSetting モデルに展開メソッド追加

```php
// app/Models/WmsContractorSetting.php

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
 * 親 + 子の全仕入先IDを取得
 */
public static function getContractorIdsWithChildren(int $contractorId): array
{
    $ids = [$contractorId];
    $children = self::getChildContractorIds($contractorId);
    return array_unique(array_merge($ids, $children));
}
```

#### B. AutoOrderScheduledCommand の変更

子仕入先も未処理候補チェック・実行ログに含める。

```php
// app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php

foreach ($settings as $setting) {
    $contractorId = $setting->contractor_id;

    // 集約される子仕入先IDも取得
    $allContractorIds = WmsContractorSetting::getContractorIdsWithChildren($contractorId);

    // 当日すでに実行済みかチェック（親のみでOK）
    if (WmsAutoOrderExecutionLog::hasRunOrSucceededToday($contractorId)) {
        // ...skip
        continue;
    }

    // 未処理候補チェック（親 + 子仕入先すべて）
    $hasPendingOrders = WmsOrderCandidate::query()
        ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
        ->whereIn('contractor_id', $allContractorIds)
        ->exists();

    $hasPendingTransfers = WmsStockTransferCandidate::query()
        ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
        ->whereIn('contractor_id', $allContractorIds)
        ->exists();

    // ...以降同じ
}
```

#### C. OrderCandidateCalculationService の変更

`targetContractorId` を配列 `targetContractorIds` に変更し、子仕入先も含める。

```php
// app/Services/AutoOrder/OrderCandidateCalculationService.php

// プロパティ変更
private ?array $targetContractorIds = null;

// calculate() 内
if ($contractorId !== null) {
    $this->targetContractorIds = WmsContractorSetting::getContractorIdsWithChildren($contractorId);
} else {
    $this->targetContractorIds = null;
}

// INTERNAL候補フィルタ（Line 511付近）
if ($this->targetContractorIds !== null) {
    $internalContractorIds = array_intersect($internalContractorIds, $this->targetContractorIds);
}

// EXTERNAL候補フィルタ（Line 720付近）
if ($this->targetContractorIds !== null) {
    $externalQuery->whereIn('contractor_id', $this->targetContractorIds);
}
```

#### D. ProcessOrderCandidateGenerationJob の変更

排他チェック時も子仕入先を含める。

```php
// app/Jobs/ProcessOrderCandidateGenerationJob.php

if ($this->contractorId !== null) {
    $allContractorIds = WmsContractorSetting::getContractorIdsWithChildren($this->contractorId);

    $hasOrderCandidates = WmsOrderCandidate::query()
        ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
        ->whereIn('contractor_id', $allContractorIds)
        ->exists();

    $hasTransferCandidates = WmsStockTransferCandidate::query()
        ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
        ->whereIn('contractor_id', $allContractorIds)
        ->exists();
}
```

### 影響範囲

| コンポーネント | 影響 |
|---|---|
| `OrderCandidateCalculationService` | `targetContractorId` → `targetContractorIds` に変更、`whereIn` に変更 |
| `AutoOrderScheduledCommand` | 未処理候補チェックに子仕入先を含める |
| `ProcessOrderCandidateGenerationJob` | 排他チェックに子仕入先を含める |
| `WmsContractorSetting` | 展開メソッド2つ追加 |
| `HanaOrderJXFileGenerator` | **変更不要**（既に集約ロジックあり） |
| `OrderTransmissionService` | **変更不要** |
| 手動生成UI（ListWmsAutoOrderJobControls） | 仕入先選択で親を選ぶと子も含めて生成されるようになる |

## 制約

- FK禁止（CLAUDE.md記載の通り）
- `migrate:fresh` / `migrate:refresh` 禁止
- DB変更なし（既存の `transmission_contractor_id` カラムをそのまま利用）
- ファイル送信フェーズは変更しない（既に正しく動作している）

## 対象ファイル

### 新規作成

なし

### 既存変更

| ファイル | 変更内容 |
|---|---|
| `app/Models/WmsContractorSetting.php` | `getChildContractorIds()`, `getContractorIdsWithChildren()` 追加 |
| `app/Services/AutoOrder/OrderCandidateCalculationService.php` | `targetContractorId` → `targetContractorIds` 配列化、`whereIn` に変更 |
| `app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php` | 未処理候補チェックに子仕入先を含める |
| `app/Jobs/ProcessOrderCandidateGenerationJob.php` | 排他チェックに子仕入先を含める |

### 参照のみ

| ファイル | 参照理由 |
|---|---|
| `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php` | 集約ロジックの確認（変更不要） |
| `app/Services/AutoOrder/OrderTransmissionService.php` | 送信フローの確認（変更不要） |
| `app/Services/JX/JxTestFileGenerator.php` | 正しいパターンの参考実装 |
| `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` | 手動生成UIの動作確認 |
| `routes/console.php` | スケジュール定義の確認 |

## 確認事項

1. **子仕入先の個別スケジュール**: 子仕入先に独自の `auto_order_generation_time` が設定されている場合（例: 1680は09:30、他は11:00）、親のスケジュールで一律実行してよいか？ それとも子仕入先の時刻も考慮すべきか？

2. **実行ログ**: 親仕入先IDでのみ `WmsAutoOrderExecutionLog` を記録するか、子仕入先分も個別にログを記録するか？

3. **手動生成UI**: 仕入先選択で子仕入先を選んだ場合の挙動
   - 案A: 子仕入先のみ生成（現状の挙動を維持、親は含めない）
   - 案B: 自動的に親に展開して親+全子を生成
   - 案C: 子仕入先単体として生成（集約なし）

4. **TRANSMISSION_MAPPING との重複**: `HanaOrderJXFileGenerator` のハードコード定数と DB の `transmission_contractor_id` が同じ仕入先に対して設定されている。DB設定への一本化を検討するか？
