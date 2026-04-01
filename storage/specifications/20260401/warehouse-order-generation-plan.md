# 倉庫別発注・移動候補生成 作業計画

## 前提

- 既存の「発注・移動候補生成」は全有効倉庫を対象に一括生成する
- 「移動候補生成」は INTERNAL 移動のみ一括生成する
- 「仕入先別発注候補生成」は特定仕入先のみ対象にする
- 今回追加する「倉庫別発注・移動候補生成」は、**選択した1倉庫のみ**を対象にする独立機能
- HUB倉庫を選んでもサテライト倉庫は巻き込まない

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | 調査・設計確認 | 既存コードの倉庫フィルタ箇所を特定、影響範囲を確認 | フィルタ挿入ポイントが明確 |
| P2 | バックエンド（Job/Service倉庫フィルタ対応） | Job/Service に `warehouseId` パラメータを追加し倉庫単位で生成可能にする | 倉庫IDを渡すと該当倉庫のみ生成される |
| P3 | フロントエンド（倉庫別生成ボタン・モーダル） | ListPage に倉庫選択付き生成ボタンを追加 | UIから倉庫を選んで生成→進捗表示→完了表示 |
| P4 | 動作確認 | 構文チェック・ルート確認 | エラーなし |

---

## P1: 調査・設計確認

### 目的

既存の `OrderCandidateCalculationService` と `StockSnapshotService` で倉庫フィルタを挿入すべきポイントを特定する。

### 調査手順

1. `OrderCandidateCalculationService::calculate()` の倉庫取得箇所を確認
   - `WmsWarehouseAutoOrderSetting::enabled()` の呼び出し箇所
   - `$enabledWarehouseIds` の使用箇所
2. `StockSnapshotService::generateAll()` の倉庫取得箇所を確認
3. `ProcessOrderCandidateGenerationJob` のコンストラクタとdispatch箇所を確認
4. PENDING候補削除時の倉庫スコープを確認

### 設計方針

**倉庫別生成の動作仕様:**

1. ユーザーが倉庫を1つ選択して「生成」をクリック
2. PENDING候補の削除は**選択倉庫分のみ**（他倉庫のPENDING候補は保持）
3. スナップショットは**選択倉庫分のみ**生成
4. 発注候補（EXTERNAL）は **選択倉庫のみ**の在庫不足分を生成
   - HUB倉庫でもサテライトの需要は含めない
5. 移動候補（INTERNAL）は **選択倉庫がサテライトの場合のみ**生成
   - 選択倉庫 = satellite_warehouse_id のもののみ
   - HUB倉庫を選んだ場合、移動候補は生成しない
6. 自動確定レベルは通常通り適用

### 完了条件

- フィルタ挿入ポイントが特定できている
- 設計方針がコードレベルで確認されている

---

## P2: バックエンド（Job/Service倉庫フィルタ対応）

### 目的

`ProcessOrderCandidateGenerationJob` と関連サービスに `warehouseId` パラメータを追加し、倉庫単位での生成を可能にする。

### 修正対象ファイル

1. **`app/Jobs/ProcessOrderCandidateGenerationJob.php`**
   - コンストラクタに `?int $warehouseId = null` を追加
   - `$warehouseId` をサービスに渡す

2. **`app/Services/AutoOrder/StockSnapshotService.php`**
   - `generateAll(?int $warehouseId = null)` に引数追加
   - `$warehouseId` 指定時はその倉庫のみスナップショット生成

3. **`app/Services/AutoOrder/OrderCandidateCalculationService.php`**
   - `calculate()` に `?int $warehouseId = null` 引数追加
   - `$warehouseId` 指定時:
     - `$enabledWarehouseIds` を `[$warehouseId]` に絞り込む（ただし有効チェックは維持）
     - INTERNAL移動: 選択倉庫がサテライトの場合のみ、その倉庫分を生成
     - EXTERNAL発注: 選択倉庫分のみ生成（サテライト需要は含めない）

4. **PENDING削除の倉庫スコープ**
   - `ListWmsAutoOrderJobControls::executeStep1Delete()` 相当の処理で、`warehouseId` 指定時は該当倉庫のPENDING候補のみ削除

### 修正方針

```php
// ProcessOrderCandidateGenerationJob コンストラクタ
public function __construct(
    public string $jobId,
    public bool $deletePending = false,
    public ?int $contractorId = null,
    public ?int $executionLogId = null,
    public bool $transferOnly = false,
    public ?int $warehouseId = null,  // ← 追加
)
```

```php
// OrderCandidateCalculationService::calculate()
public function calculate(
    ?int $snapshotJobId = null,
    ?int $contractorId = null,
    bool $transferOnly = false,
    ?int $warehouseId = null,  // ← 追加
): WmsAutoOrderJobControl
```

```php
// 倉庫フィルタ適用箇所
if ($warehouseId) {
    $enabledWarehouseIds = array_intersect($enabledWarehouseIds, [$warehouseId]);
}
```

### 完了条件

- `warehouseId` を渡すとその倉庫のみ候補が生成される
- `warehouseId` を渡さない場合は既存動作と同一
- 構文チェック通過

---

## P3: フロントエンド（倉庫別生成ボタン・モーダル）

### 目的

`ListWmsAutoOrderJobControls` に「倉庫別発注・移動候補生成」ボタンを追加し、倉庫選択→生成→進捗表示→完了の一連のUIを実装する。

### 修正対象ファイル

1. **`app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php`**
   - 新しいヘッダーアクション「倉庫別発注・移動候補生成」追加
   - Livewireプロパティ: `$selectedWarehouseIdForGeneration`
   - メソッド: `startWarehouseGenerationJob($warehouseId)`

2. **`resources/views/filament/components/order-generation-wizard.blade.php`**
   - 倉庫別生成用のセクション追加（または別のBladeビューを作成）

### UI仕様

**ボタン:**
- ラベル: 「倉庫別候補生成」
- アイコン: `heroicon-o-building-storefront`
- 色: `warning`（既存ボタンと区別）

**モーダルフロー:**
1. 倉庫選択画面: ドロップダウンから倉庫を1つ選択
   - `WmsWarehouseAutoOrderSetting::enabled()` の倉庫のみ表示
   - 選択倉庫のPENDING候補数を表示
2. 確認画面: 「{倉庫名}の発注・移動候補を生成しますか？」
   - 既存PENDING候補がある場合は削除確認
3. 進捗画面: 既存のプログレスバーを再利用
4. 完了画面: 生成結果表示

### 実装方針

既存のウィザードモーダルの仕組み（`$wizardStep`, `pollJobProgress`等）を再利用する。
倉庫別生成用に `$warehouseGenerationMode` フラグを追加し、ウィザード内で分岐する。

### 完了条件

- 「倉庫別候補生成」ボタンが表示される
- 倉庫を選択して生成→プログレス→完了表示が動作する
- 既存ボタンの動作に影響がない

---

## P4: 動作確認

### 確認項目

1. 構文チェック
   - [ ] `php -l` で全変更ファイルがパス
2. ルート・キャッシュ
   - [ ] `php artisan route:clear` が正常
   - [ ] `php artisan view:clear` が正常
3. 既存機能への影響
   - [ ] 既存の「発注・移動候補生成」ボタンが動作する
   - [ ] 既存の「移動候補生成」ボタンが動作する
   - [ ] 既存の「仕入先別発注候補生成」ボタンが動作する

### 完了条件

- 上記全チェック項目がパス

---

## 制約（厳守）

- `migrate:fresh` / `migrate:refresh` / `db:wipe` 禁止
- FK禁止
- 既存の3つの生成ボタンの動作を変更しない
- `$warehouseId = null` の場合は既存動作と完全互換
- HUB倉庫選択時にサテライト倉庫を巻き込まない

## 全体完了条件

- 倉庫別候補生成ボタンから倉庫を選択して生成できる
- 選択倉庫のみの候補が生成される
- HUB倉庫でもサテライトの候補は生成しない
- 既存の一括生成機能に影響がない
- エラーなし
