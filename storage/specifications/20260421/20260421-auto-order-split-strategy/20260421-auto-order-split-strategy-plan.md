# 発注計算の分離 作業計画

## 前提

- 仕様書: `20260421-auto-order-split-strategy.md`（確認済み）
- 現在の `OrderCandidateCalculationService::calculate()` は安全在庫ベースと実績ベースが混在
- `safety_stock = 0` の商品は `last_3d_qty` をしきい値として使用している
- `is_auto_order` フラグは `item_contractors` テーブルに既に存在

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | 発注OFFボタン追加 | 移動候補テーブルに「発注OFF」レコードアクション追加 | ボタンクリックで `is_auto_order=false` + PENDING候補削除 |
| P2 | 既存計算から実績ベース除去 | `safety_stock=0` 時の `last_3d_qty` 計算を除去 | 安全在庫ベースのみで候補生成される |
| P3 | JobProcessName enum 追加 | `SALES_BASED_CALC` を enum に追加 | enum が正しく定義されている |
| P4 | 実績ベースサービス新規作成 | `SalesBasedOrderCandidateService` を作成 | 実績ベースで候補を生成し既存batch_codeに追加できる |
| P5 | 実績ベースジョブ新規作成 | `ProcessSalesBasedOrderCandidateJob` を作成 | Queue経由でサービスを実行できる |
| P6 | 実績ベース生成UIボタン追加 | `ListWmsAutoOrderJobControls` にアクション追加 | モーダルから実績ベース候補生成を実行できる |
| P7 | 動作確認 | 全機能の結合テスト | 全フローが正常動作 |

---

## P1: 発注OFFボタン追加

### 目的

移動候補一覧（`admin/wms-stock-transfer-candidates`）に「発注OFF」レコードアクションを追加し、該当商品の `item_contractors.is_auto_order` を `false` にする。同時にPENDING候補を自動削除する。

### 修正対象ファイル

- `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php`
  - recordActions（lines 385-570）に新規アクション追加

### 修正内容

既存の `recordActions` 配列（`Action::make('edit')` と `Action::make('delete')` の間）に以下を追加:

```php
Action::make('toggleAutoOrder')
    ->label('発注OFF')
    ->icon('heroicon-o-no-symbol')
    ->color('danger')
    ->requiresConfirmation()
    ->modalHeading('自動発注対象から除外')
    ->modalDescription(fn ($record) => "[{$record->item_code}] {$record->item_name}\nこの商品を自動発注対象から除外しますか？")
    ->action(function ($record) {
        // 1. item_contractors.is_auto_order = false
        \App\Models\Sakemaru\ItemContractor::where('item_id', $record->item_id)
            ->where('contractor_id', $record->contractor_id)
            ->where('warehouse_id', $record->satellite_warehouse_id)
            ->update(['is_auto_order' => false]);

        // 2. 同一商品のPENDING移動候補を削除
        \App\Models\WmsStockTransferCandidate::where('item_id', $record->item_id)
            ->where('contractor_id', $record->contractor_id)
            ->where('satellite_warehouse_id', $record->satellite_warehouse_id)
            ->where('status', \App\Enums\AutoOrder\CandidateStatus::PENDING)
            ->delete();

        // 3. 対応するPENDING発注候補も削除
        \App\Models\WmsOrderCandidate::where('item_id', $record->item_id)
            ->where('contractor_id', $record->contractor_id)
            ->where('warehouse_id', $record->satellite_warehouse_id)
            ->where('status', \App\Enums\AutoOrder\CandidateStatus::PENDING)
            ->delete();

        Notification::make()->title('自動発注対象から除外しました')->success()->send();
    })
    ->visible(fn ($record) => $record->status === \App\Enums\AutoOrder\CandidateStatus::PENDING)
```

### 注意

- `item_contractors` の特定には `item_id` + `contractor_id` + `warehouse_id` の3つを使用
- 移動候補テーブルでは `satellite_warehouse_id` が `item_contractors.warehouse_id` に対応する
- APPROVED 候補は削除しない（承認済みのため手動対応）

### 完了条件

- [ ] 移動候補一覧のPENDINGレコードに「発注OFF」ボタンが表示される
- [ ] クリック→確認→実行で `item_contractors.is_auto_order` が `false` になる
- [ ] 同一商品のPENDING候補（移動+発注）が自動削除される
- [ ] APPROVED候補は削除されない
- [ ] `./vendor/bin/pint` でフォーマット通過

---

## P2: 既存計算から実績ベース除去

### 目的

`OrderCandidateCalculationService` の INTERNAL/EXTERNAL 計算から `safety_stock = 0` 時の `last_3d_qty` ベース計算を除去する。

### 修正対象ファイル

- `app/Services/AutoOrder/OrderCandidateCalculationService.php`

### 修正内容

#### INTERNAL 計算（lines 653-660 付近）

変更前:
```php
if ((int) $ic->safety_stock === 0) {
    $sales3dQty = $this->salesSummaries3d[$ic->warehouse_id][$ic->item_id] ?? 0;
    if ($sales3dQty <= 0 || $projectedStock >= $sales3dQty) {
        continue;
    }
    $isSalesBasedOrder = true;
    $shortageQty = $sales3dQty - $projectedStock;
}
```

変更後:
```php
if ((int) $ic->safety_stock === 0) {
    continue;
}
```

#### EXTERNAL 計算（lines 879-886 付近）

同様の変更:
```php
if ((int) $ic->safety_stock === 0) {
    continue;
}
```

#### salesSummaries3d のロード（lines 554-569）

`salesSummaries3d` のロード処理はそのまま残す。実績ベースサービス（P4）で再利用するため、またはテーブル表示で参照するため。

#### $isSalesBasedOrder 変数

この変数が `safety_stock = 0` のブロック以外で使われていないか確認。使われていなければ宣言ごと削除。

### 完了条件

- [ ] `safety_stock = 0` の商品が候補生成対象から除外される
- [ ] `safety_stock > 0` かつ `is_auto_order = true` の商品は従来通り候補生成される
- [ ] `./vendor/bin/pint` でフォーマット通過

---

## P3: JobProcessName enum 追加

### 目的

実績ベース計算のジョブ履歴を識別するための enum 値を追加する。

### 修正対象ファイル

- `app/Enums/AutoOrder/JobProcessName.php`

### 修正内容

```php
case SALES_BASED_CALC = 'SALES_BASED_CALC';
```

`label()` メソッドに追加:
```php
self::SALES_BASED_CALC => '実績ベース発注計算',
```

### 完了条件

- [ ] `JobProcessName::SALES_BASED_CALC` が定義されている
- [ ] `label()` で「実績ベース発注計算」が返される

---

## P4: 実績ベースサービス新規作成

### 目的

過去3日間の出荷実績に基づく発注候補生成サービスを新規作成する。

### 新規作成ファイル

- `app/Services/AutoOrder/SalesBasedOrderCandidateService.php`

### 設計方針

`OrderCandidateCalculationService` の構造を参考にするが、以下が異なる:

1. **対象**: `is_auto_order` フラグに関係なく、`safety_stock = 0` かつ `last_3d_qty > 0` の全商品
2. **しきい値**: `last_3d_qty` を使用
3. **batch_code**: 既存のPENDING settlement の `batch_code` を再利用する
4. **process_name**: `JobProcessName::SALES_BASED_CALC`

### 主要メソッド

```php
public function calculate(
    ?int $warehouseId,
    int $createdBy,
    ?array $contractorIds = null,
    ?string $batchCode = null,
): WmsAutoOrderJobControl
```

### 計算ロジック

1. **batch_code 解決**: 引数の `$batchCode` があればそれを使用。なければ `WmsAutoOrderJobControl::findPendingSettlementForWarehouse($warehouseId)` から取得。見つからなければ新規生成
2. **データロード**: 在庫スナップショット、入荷予定、3日実績、移動候補の影響を `OrderCandidateCalculationService` と同じ方法でロード
3. **INTERNAL移動候補生成**: `item_contractors` から `safety_stock = 0` の商品を取得（`is_auto_order` は条件に含めない）。`last_3d_qty > 0` で不足分を計算
4. **EXTERNAL発注候補生成**: 同様の条件で外部発注候補を生成
5. **候補テーブルに INSERT**: 既存と同じ `wms_stock_transfer_candidates` / `wms_order_candidates` テーブルに格納
6. **ジョブレコード更新**: `WmsAutoOrderJobControl` の status を SUCCESS に

### is_auto_order フィルタを外す方法

`OrderCandidateCalculationService` では `->where('item_contractors.is_auto_order', true)` だが、この新サービスではこの条件を**除外**する。ただし他の除外条件（`items.is_ended = false`, `items.end_of_sale_type = 'NORMAL'` 等）は維持する。

### 完了条件

- [ ] サービスクラスが作成されている
- [ ] 既存 PENDING settlement の batch_code を再利用できる
- [ ] `safety_stock = 0` かつ `last_3d_qty > 0` の商品が対象
- [ ] `is_auto_order = false` の商品も対象に含まれる
- [ ] 候補が `wms_stock_transfer_candidates` / `wms_order_candidates` に正しく格納される
- [ ] `./vendor/bin/pint` でフォーマット通過

---

## P5: 実績ベースジョブ新規作成

### 目的

Queue経由で `SalesBasedOrderCandidateService` を実行するジョブを作成する。

### 新規作成ファイル

- `app/Jobs/ProcessSalesBasedOrderCandidateJob.php`

### 設計方針

`ProcessOrderCandidateGenerationJob` の構造を参考にする。

### コンストラクタ引数

```php
public function __construct(
    public int $jobId,          // WmsQueueProgress ID
    public ?int $warehouseId,
    public int $createdBy,
    public ?array $contractorIds = null,
    public ?string $batchCode = null,
)
```

### handle() の流れ

1. `WmsQueueProgress` の状態を更新（processing）
2. `SalesBasedOrderCandidateService::calculate()` を呼び出す
3. 結果に応じて `WmsQueueProgress` を completed / failed に更新

### 完了条件

- [ ] ジョブクラスが作成されている
- [ ] Queue dispatch で実行できる
- [ ] `WmsQueueProgress` の進捗が正しく更新される
- [ ] `./vendor/bin/pint` でフォーマット通過

---

## P6: 実績ベース生成UIボタン追加

### 目的

`admin/wms-auto-order-job-controls` に「実績ベース発注候補生成」ボタンを追加する。

### 修正対象ファイル

- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php`

### 設計方針

既存の `getGenerateByWarehouseAction()`（lines 132-298）を参考にした新規アクションを追加する。

### UIの配置

既存の「発注・移動候補生成」ボタンの隣に配置。ヘッダーアクションまたはツールバーアクションとして追加。

### モーダルの内容

- アイコン: `heroicon-o-chart-bar` 等（安全在庫ベースと区別できるもの）
- 色: `warning`（通常の `info` と区別）
- ラベル: 「実績ベース発注候補生成」
- モーダル内容:
  - 倉庫表示（現在選択中の倉庫を表示）
  - 発注先選択（既存モーダルと同じ contractor-selection コンポーネント）
  - 既存PENDING settlement の batch_code があれば表示（「既存バッチに追加されます」）
- 実行: `ProcessSalesBasedOrderCandidateJob` を dispatch

### batch_code 共有ロジック

1. `WmsAutoOrderJobControl::findPendingSettlementForWarehouse($warehouseId)` で既存のPENDING settlement を検索
2. 見つかれば → その `batch_code` を再利用（モーダルに「既存バッチ batch_code に追加」と表示）
3. 見つからなければ → 新規 `batch_code` を生成

### 完了条件

- [ ] 「実績ベース発注候補生成」ボタンが表示される
- [ ] モーダルで倉庫・発注先を選択できる
- [ ] 既存バッチがある場合、同一 batch_code に候補が追加される
- [ ] ジョブが正常に dispatch される
- [ ] `./vendor/bin/pint` でフォーマット通過

---

## P7: 動作確認

### 目的

全機能の結合テストを行い、運用フロー全体が正常に動作することを確認する。

### テストシナリオ

#### シナリオ1: 発注OFFボタン
1. 移動候補一覧でPENDINGレコードの「発注OFF」をクリック
2. 確認 → `item_contractors.is_auto_order` が `false` になること
3. 同一商品のPENDING候補が削除されること
4. `admin/item-contractors/{id}/edit` で Toggle が OFF になっていること

#### シナリオ2: 安全在庫ベース候補生成（既存フロー）
1. 「発注・移動候補生成」を実行
2. `safety_stock > 0` かつ `is_auto_order = true` の商品のみ候補が生成されること
3. `safety_stock = 0` の商品が候補に含まれないこと

#### シナリオ3: 実績ベース候補生成（新規フロー）
1. シナリオ2の後に「実績ベース発注候補生成」を実行
2. `safety_stock = 0` かつ `last_3d_qty > 0` の商品が候補に追加されること
3. 同じ `batch_code` が使用されていること
4. `is_auto_order = false` の商品も含まれていること

#### シナリオ4: batch_code 共有確認
1. 安全在庫ベース → 実績ベースの順で生成
2. 両方の候補が同一 `batch_code` に属すること
3. 一括確定で全候補が処理されること

### 完了条件

- [ ] シナリオ1〜4が全て正常に動作する
- [ ] `./vendor/bin/pint` で全ファイルフォーマット通過

---

## 制約（厳守）

1. **FK禁止**: `item_contractors` は基幹システム共有テーブル。FK追加不可
2. **migrate:fresh/refresh 禁止**: 本番データ保護
3. **APPROVED候補を自動削除しない**: 発注OFF操作はPENDINGのみ対象
4. **既存計算ロジックの安全在庫ベース部分を変更しない**: `safety_stock > 0` の計算は一切変更不可
5. **実績ベース計算は `is_auto_order` を参照しない**: 実績があれば全て対象

## 全体完了条件

- 全Phase（P1〜P7）が完了している
- 全ファイルが `./vendor/bin/pint` でフォーマット通過
- 発注OFFボタンが正常動作
- 安全在庫ベース候補生成が `safety_stock > 0` のみ対象
- 実績ベース候補生成が `safety_stock = 0` + `last_3d_qty > 0` 対象
- batch_code が安全在庫ベースと実績ベースで共有される
