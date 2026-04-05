# 仕入先別の発注候補生成 作業計画

## 前提

- スナップショット廃止済み（`OrderCandidateCalculationService` は `wms_v_stock_available` + `wms_order_incoming_schedules` から直接読み込み）
- `calculate()` は既に `$contractorId` パラメータをサポート（単一仕入先指定）
- `getForceGenerateByContractorAction()` が管理者メニューに既存（単一Select、全倉庫対象）
- 今回は「倉庫別生成ボタン」に仕入先複数選択を追加する
- スケジューラー修正はスコープ外

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | WmsAutoOrderJobControl の拡張 | batch_code再利用メソッド、target_scope保存 | findPendingSettlementForWarehouse()が動作 |
| P2 | OrderCandidateCalculationService の拡張 | contractorIds配列対応、batchCode外部指定、INTERNAL仕入先フィルタ、移動候補ロードPENDING全件化 | 仕入先配列でフィルタされた候補のみ生成 |
| P3 | ProcessOrderCandidateGenerationJob の拡張 | contractorIds配列対応、batchCode受け渡し、仕入先別PENDING削除 | ジョブが仕入先配列+batchCodeで正しく動作 |
| P4 | 仕入先選択Bladeコンポーネント作成 | 検索付きチェックボックスリスト、全選択デフォルト、選択状態維持 | UIが正しく表示され選択状態をLivewireに同期 |
| P5 | getGenerateByWarehouseAction() の統合 | 仕入先選択UIを組み込み、batch_code再利用、仕入先別PENDING削除 | 倉庫別生成ボタンで仕入先選択が機能 |
| P6 | 排他制御・result_data の調整 | 仕入先単位の排他制御、仕入先別集計 | APPROVED仕入先をブロックせず他の仕入先を生成可能 |
| P7 | 動作確認・回帰テスト | 構文チェック、テスト実行、既存機能の動作確認 | 全テストパス、既存メニュー・スケジューラー影響なし |

---

## P1: WmsAutoOrderJobControl の拡張

### 目的

仕入先別生成で同日同倉庫の batch_code を再利用するために、倉庫指定でPENDINGジョブを検索するメソッドを追加する。また、生成時の対象仕入先を `target_scope` に保存する。

### 修正方針

1. `findPendingSettlementForWarehouse(int $warehouseId)` メソッドを追加:
   - `settlement_status = PENDING`
   - `process_name = ORDER_CALC`
   - `warehouse_id = $warehouseId`
   - `whereDate('started_at', today())`
   - `orderBy('id', 'desc')->first()`

2. `startJob()` に `$targetScope` パラメータを追加（既存の `$scope` パラメータを活用）:
   - 呼び出し元で `['contractor_ids' => [...], 'source' => 'warehouse_contractor_specific']` を渡す

### 修正対象ファイル

- `app/Models/WmsAutoOrderJobControl.php`

### 完了条件

- `findPendingSettlementForWarehouse()` が倉庫IDで絞り込んでPENDINGジョブを返す
- `php -l` 構文チェック OK

---

## P2: OrderCandidateCalculationService の拡張

### 目的

`calculate()` を以下の点で拡張する:
1. 仕入先IDを**配列**で受け取れるようにする（現状は単一IDのみ）
2. `batchCode` を外部から指定可能にする（再利用のため）
3. INTERNAL仕入先のフィルタリング（選択仕入先にINTERNALが含まれない場合はスキップ）
4. `loadTransferCandidatesToMemory()` を同バッチ限定からPENDINGステータス全件に拡張

### 修正方針

#### 2-1: calculate() シグネチャ変更

```php
// Before
public function calculate(?int $contractorId = null, ...)

// After
public function calculate(?int $contractorId = null, bool $transferOnly = false, ?int $warehouseId = null, ?int $createdBy = null, ?array $contractorIds = null, ?string $batchCode = null): WmsAutoOrderJobControl
```

- `$contractorIds` (配列) が指定された場合、`$contractorId` (単一) より優先する
- `$contractorIds` が指定された場合、`getContractorIdsWithChildren()` で各IDを展開してマージ
- 配列 → `$this->targetContractorIds` に設定

#### 2-2: batchCode 外部指定

- `$batchCode` が渡された場合、`WmsAutoOrderJobControl::startJob()` にそのまま渡す
- 渡されなかった場合は従来通り自動生成

#### 2-3: INTERNAL仕入先フィルタ

`createInternalTransferCandidatesBulk()` 内:
```php
// Before: 常に全INTERNAL仕入先を処理
$internalContractorIds = $this->internalContractorIds;

// After: targetContractorIdsが指定されている場合、INTERNALもフィルタ
$internalContractorIds = $this->internalContractorIds;
if ($this->targetContractorIds !== null) {
    $internalContractorIds = array_intersect($internalContractorIds, $this->targetContractorIds);
    if (empty($internalContractorIds)) {
        Log::info('選択仕入先にINTERNAL発注先が含まれないため、移動候補をスキップ');
        return 0;
    }
}
```

#### 2-4: loadTransferCandidatesToMemory() 拡張

```php
// Before: 同バッチのみ
private function loadTransferCandidatesToMemory(string $batchCode): array
{
    $candidates = DB::connection('sakemaru')
        ->table('wms_stock_transfer_candidates')
        ->where('batch_code', $batchCode)
        ...

// After: PENDINGステータスの全移動候補（バッチ横断）+ 同バッチの候補
private function loadTransferCandidatesToMemory(string $batchCode): array
{
    $candidates = DB::connection('sakemaru')
        ->table('wms_stock_transfer_candidates')
        ->where(function ($query) use ($batchCode) {
            $query->where('batch_code', $batchCode)
                  ->orWhere('status', CandidateStatus::PENDING->value);
        })
        ...
```

これにより、先に生成された他の仕入先のINTERNAL移動候補の影響がEXTERNAL計算に反映される。

### 修正対象ファイル

- `app/Services/AutoOrder/OrderCandidateCalculationService.php`

### 完了条件

- `$contractorIds` 配列指定時に該当仕入先の候補のみ生成される
- `$batchCode` 指定時にそのコードで候補が生成される
- INTERNALフィルタが機能し、EXTERNAL選択時は移動候補がスキップされる
- `php -l` 構文チェック OK

---

## P3: ProcessOrderCandidateGenerationJob の拡張

### 目的

ジョブに `$contractorIds` (配列) と `$batchCode` パラメータを追加し、仕入先別のPENDING候補削除とcalculate()呼び出しを実装する。

### 修正方針

#### 3-1: コンストラクタ拡張

```php
public function __construct(
    public string $jobId,
    public bool $deletePending = false,
    public ?int $contractorId = null,
    public ?int $executionLogId = null,
    public bool $transferOnly = false,
    public ?int $warehouseId = null,
    public ?int $createdBy = null,
    public ?array $contractorIds = null,   // NEW: 複数仕入先指定
    public ?string $batchCode = null,       // NEW: batch_code外部指定
) {}
```

#### 3-2: PENDING削除の仕入先スコープ

`deletePending` が true で `contractorIds` が指定されている場合:
```php
if ($this->contractorIds) {
    $allIds = $this->expandContractorIds($this->contractorIds);
    $deleteOrderQuery->whereIn('contractor_id', $allIds);
    $deleteTransferQuery->whereIn('contractor_id', $allIds);
}
```

#### 3-3: calculate() 呼び出し変更

```php
$calcJob = $calculationService->calculate(
    contractorId: $this->contractorId,
    transferOnly: $this->transferOnly,
    warehouseId: $this->warehouseId,
    createdBy: $this->createdBy,
    contractorIds: $this->contractorIds,
    batchCode: $this->batchCode,
);
```

### 修正対象ファイル

- `app/Jobs/ProcessOrderCandidateGenerationJob.php`

### 完了条件

- `contractorIds` 指定時にPENDING削除が該当仕入先のみに限定される
- `batchCode` がcalculate()に正しく渡される
- 既存の `contractorId` (単一) での動作が変わらない
- `php -l` 構文チェック OK

---

## P4: 仕入先選択Bladeコンポーネント作成

### 目的

カスタムBladeコンポーネントで、検索付きチェックボックスリストを実装する。

### 要件

1. **全選択デフォルト**: 初期表示で全仕入先にチェックが入っている
2. **複数選択可能**: チェックボックスで複数の仕入先を選択/解除
3. **選択なし不可**: 最低1つは選択必須（全てか複数か）
4. **検索フィルタ**: 仕入先コード・名前で絞り込み可能
5. **フィルタ変更時チェック維持**: 検索条件を変えてもチェック状態は保持
6. **親仕入先のみ表示**: `transmission_contractor_id IS NULL` の仕入先のみ（子は自動包含）
7. **倉庫の仕入先のみ**: 該当倉庫の `item_contractors` に存在する仕入先のみ表示
8. **INTERNAL/EXTERNAL区別表示**: transmission_typeでアイコンやラベルを区別
9. **締切時間表示**: `auto_order_generation_time` があれば表示

### 実装方針

Alpine.js + Livewire パターン（`order-candidate-create-items.blade.php` と同様）:

- `x-data` でローカルステート管理（searchQuery, selectedIds, contractors）
- `$wire.getContractorsForWarehouse(warehouseId)` でLivewireからデータ取得
- `$wire.set('selectedContractorIds', ids)` で選択状態をLivewire同期
- フィルタリングはAlpine.jsでクライアントサイド実行（全データはinitで取得済み）

### 新規作成ファイル

- `resources/views/filament/components/contractor-selection.blade.php`

### ListWmsAutoOrderJobControls に追加するメソッド

```php
public array $selectedContractorIds = [];

public function getContractorsForWarehouse(?int $warehouseId): array
{
    // item_contractors に存在する仕入先を取得
    // transmission_contractor_id IS NULL (親のみ)
    // INTERNAL/EXTERNAL区別、auto_order_generation_time 含む
}
```

### 完了条件

- Bladeコンポーネントが正しくレンダリングされる
- 検索・チェック・全選択/解除が動作する
- フィルタ変更時にチェック状態が保持される
- `selectedContractorIds` がLivewireプロパティに同期される

---

## P5: getGenerateByWarehouseAction() の統合

### 目的

倉庫別生成ボタンに仕入先選択UIを組み込み、以下のフローを実装:

1. ボタン押下 → 仕入先選択モーダル表示
2. 仕入先を選択して確定
3. 選択仕入先のPENDING候補のみ削除
4. 同日同倉庫のPENDINGジョブがあればbatch_code再利用
5. ジョブディスパッチ（contractorIds + batchCode指定）

### 修正方針

`getGenerateByWarehouseAction()` を `requiresConfirmation()` から `schema()` + カスタムViewに変更:

```php
return Action::make('generateByWarehouse')
    ->label('発注・移動候補生成')
    ->schema([
        ViewField::make('contractor_selector')
            ->view('filament.components.contractor-selection')
            ->viewData(['warehouseId' => $selectedWarehouseId])
            ->hiddenLabel(),
    ])
    ->modalHeading("発注・移動候補生成（{$selectedWarehouseName}）")
    ->modalSubmitActionLabel('生成開始')
    ->action(function () use ($selectedWarehouseId, $selectedWarehouseName) {
        $contractorIds = $this->selectedContractorIds;
        
        // batch_code 再利用チェック
        $existingJob = WmsAutoOrderJobControl::findPendingSettlementForWarehouse($selectedWarehouseId);
        $batchCode = $existingJob?->batch_code;
        
        // 選択仕入先のPENDING候補を削除（展開後のIDsで）
        $allContractorIds = $this->expandContractorIds($contractorIds);
        WmsOrderCandidate::where('status', CandidateStatus::PENDING)
            ->where('warehouse_id', $selectedWarehouseId)
            ->whereIn('contractor_id', $allContractorIds)
            ->delete();
        // ... 同様にtransferも
        
        // ジョブディスパッチ
        ProcessOrderCandidateGenerationJob::dispatch(
            jobId: $queueProgress->job_id,
            contractorIds: $contractorIds,
            batchCode: $batchCode,
            warehouseId: $selectedWarehouseId,
            createdBy: auth()->id(),
        );
    });
```

### 修正対象ファイル

- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php`

### 完了条件

- 倉庫別生成ボタン押下で仕入先選択モーダルが表示される
- 仕入先を選択して生成できる
- PENDING削除が選択仕入先のみに限定される
- batch_code が再利用される（同日同倉庫のPENDINGジョブ存在時）
- `php -l` 構文チェック OK

---

## P6: 排他制御・result_data の調整

### 目的

仕入先別生成における排他制御を仕入先単位に変更し、result_dataに仕入先別集計を追加する。

### 修正方針

#### 6-1: 排他制御（OrderCandidateCalculationService）

`$contractorIds` 配列指定時の排他制御:
- PENDING/APPROVED候補の存在チェックを `$contractorIds` の展開後IDsで実施
- 他の仕入先にAPPROVED候補があってもブロックしない

#### 6-2: getGenerateByWarehouseAction() の排他制御

```php
// Before: 倉庫全体でAPPROVED候補をチェック
$hasApprovedOrders = WmsOrderCandidate::query()
    ->where('status', CandidateStatus::APPROVED)
    ->where('warehouse_id', $warehouseId)
    ->exists();

// After: 選択仕入先のAPPROVED候補のみチェック
$hasApprovedOrders = WmsOrderCandidate::query()
    ->where('status', CandidateStatus::APPROVED)
    ->where('warehouse_id', $warehouseId)
    ->whereIn('contractor_id', $allContractorIds)
    ->exists();
```

#### 6-3: result_data に仕入先別集計追加

`OrderCandidateCalculationService::buildResultData()` に仕入先別集計を追加:
```json
{
  "summary": { ... },
  "byContractor": [
    {"contractor_code": "001", "contractor_name": "仕入先A", "order_count": 80, "transfer_count": 20},
    {"contractor_code": "002", "contractor_name": "仕入先B", "order_count": 40, "transfer_count": 10}
  ]
}
```

### 修正対象ファイル

- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — 排他制御 + buildResultData
- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` — UI排他制御

### 完了条件

- 仕入先AがAPPROVEDでも仕入先Bの生成が可能
- result_dataに仕入先別集計が含まれる
- `php -l` 構文チェック OK

---

## P7: 動作確認・回帰テスト

### 目的

全変更の構文チェック、テスト実行、既存機能への影響がないことを確認する。

### 確認手順

1. **構文チェック**: 変更した全ファイルに `php -l` を実行
2. **grep確認**: 
   - `grep -r 'StockSnapshotService' app/` → 0件（スナップショット廃止の維持確認）
3. **テスト実行**:
   - `php artisan test --filter=Order`
   - `php artisan test --filter=Transfer`
   - `php artisan test --filter=Calculation`
4. **既存機能の確認**:
   - 管理者メニュー > 全倉庫ウィザード（`getOrderGenerationWizardAction`）が変更なく動くこと
   - 管理者メニュー > 仕入先別強制生成（`getForceGenerateByContractorAction`）が変更なく動くこと
   - 管理者メニュー > 移動候補生成（`getGenerateTransferCandidatesAction`）が変更なく動くこと
   - スケジューラー（`AutoOrderScheduledCommand`）がコンパイルエラーなく動くこと

### 完了条件

- 全ファイル構文エラーなし
- テスト全パス（既存の無関係な失敗を除く）
- 既存の3つの管理者メニュー + スケジューラーに影響なし

---

## 制約（厳守）

1. **FK禁止**: 全リレーションはアプリケーションレベル管理
2. **migrate:fresh/refresh/reset/db:wipe 禁止**: 共有DB
3. **計算ロジック変更禁止**: 不足数計算（`safety_stock - (effective + incoming + transferIn - transferOut)`）および単位切り上げロジックは変更しない
4. **既存エントリポイント維持**: 
   - `getOrderGenerationWizardAction()` — 変更しない
   - `getForceGenerateByContractorAction()` — 変更しない
   - `getGenerateTransferCandidatesAction()` — 変更しない
   - `AutoOrderScheduledCommand` — 変更しない（単一contractorIdでの動作を維持）
5. **後方互換性**: `calculate()` の既存パラメータ（`$contractorId` 単一）での動作を壊さない

## 全体完了条件

1. 倉庫別生成ボタンで仕入先を選択して発注候補を生成できる
2. 選択した仕入先のPENDING候補のみが削除・再生成される
3. 同日同倉庫でbatch_codeが再利用される
4. 他の仕入先のAPPROVED候補に影響せず生成可能
5. 全テストパス、既存機能に影響なし
