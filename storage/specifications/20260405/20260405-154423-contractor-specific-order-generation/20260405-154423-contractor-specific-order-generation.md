# 仕入先別の発注候補生成対応

- **作成日**: 2026-04-05
- **ステータス**: ドラフト
- **ディレクトリ**: storage/specifications/20260405/20260405-154423-contractor-specific-order-generation/

## 背景・目的

### 現状の問題

倉庫別の「発注・移動候補生成」ボタン（`getGenerateByWarehouseAction()`）では、選択した倉庫のすべての仕入先の発注候補を一括生成している。`contractorId: null` で `ProcessOrderCandidateGenerationJob` をディスパッチするため、仕入先の絞り込みが行われない。

しかし**仕入先別に発注締切時間が異なる**。例えば:
- 仕入先A: 締切 10:00 → 10:00に発注データ生成が必要
- 仕入先B: 締切 15:00 → 15:00に発注データ生成が必要

現状では全仕入先を一括生成するため、早い締切の仕入先に合わせてすべて生成するか、遅い締切に合わせて全部遅延するかの二択になっている。

### 目的

倉庫別の発注候補生成において、**仕入先を選択して発注データを生成できる**ようにする。これにより、締切時間の異なる仕入先に対して適切なタイミングで発注候補を生成できる。

## 現状の実装

### 発注候補生成の3つのエントリポイント

| # | エントリポイント | 場所 | contractorId | warehouseId | 用途 |
|---|-----------------|------|-------------|-------------|------|
| 1 | 倉庫別生成ボタン | `getGenerateByWarehouseAction()` | **null（全仕入先）** | ユーザー選択倉庫 | 日常運用 |
| 2 | 管理者メニュー > 全倉庫ウィザード | `getOrderGenerationWizardAction()` | null | null | 管理者の全体再生成 |
| 3 | 管理者メニュー > 仕入先別生成 | `getForceGenerateByContractorAction()` | ユーザー選択 | null | 仕入先指定の強制生成 |

**問題は#1**。日常運用で最も使用されるが、仕入先の絞り込みができない。

### スケジューラー（自動実行）

`AutoOrderScheduledCommand` は**仕入先単位で**5分ごとに実行:
- `wms_contractor_settings.auto_order_generation_time` で各仕入先の生成時間を管理
- `WmsAutoOrderExecutionLog` で1日1回の実行制御
- `ProcessOrderCandidateGenerationJob` に `contractorId` を渡して仕入先別に生成

→ スケジューラーは既に仕入先別に動作している。問題はUI（手動操作）側のみ。

### OrderCandidateCalculationService の仕入先フィルタリング

`calculate()` メソッドは既に `$contractorId` パラメータをサポート:

```php
public function calculate(
    ?int $contractorId = null,      // ← 既存パラメータ
    bool $transferOnly = false,
    ?int $warehouseId = null,
    ?int $createdBy = null
): WmsAutoOrderJobControl
```

- `$contractorId` 指定時: `WmsContractorSetting::getContractorIdsWithChildren()` で親+子仕入先を展開
- EXTERNAL候補: `$this->targetContractorIds` でフィルタリング（L774-776）
- **INTERNAL候補: 仕入先指定があっても常に全INTERNAL仕入先を処理**（L553コメント）

### 排他制御の現状

| パターン | 条件 | チェック対象 |
|---------|------|------------|
| 仕入先指定あり | PENDING/APPROVED候補が該当仕入先に存在 | 該当仕入先の候補のみ |
| 仕入先指定なし | APPROVED候補が存在（倉庫フィルタあり） | 該当倉庫全体 |

### batch_code の仕組み

- フォーマット: `YmdHis` + 倉庫ID3桁（全体実行は`000`）
- 同日同倉庫のPENDINGジョブがあれば再利用する仕組みあり（手動追加時）
- `findPendingSettlement()` は**倉庫・仕入先を区別せず**最新のPENDINGジョブを返す

### PENDING候補の削除タイミング

倉庫別生成時（`getGenerateByWarehouseAction()`）:
- 生成前に該当倉庫のPENDING候補を**全仕入先分**削除（L132-140）
- APPROVED候補があればブロック（L111-129）

## 変更内容

### 概要

倉庫別の発注候補生成UIに**仕入先選択**機能を追加し、選択した仕入先のみの発注候補を生成できるようにする。

### 考慮すべき論点一覧

#### 1. UI: 仕入先の選択方法

**選択肢:**
- (A) 単一選択（Select） — 1つの仕入先のみ選択
- (B) 複数選択（MultiSelect / CheckboxList） — 複数仕入先を同時選択
- (C) 全選択 + 個別解除 — デフォルト全選択、不要な仕入先のみ外す

**推奨: (B) 複数選択 + 全選択オプション**

理由:
- 締切時間が同じ仕入先グループをまとめて選択したいケースが多い
- 全仕入先一括（従来動作）も「全選択」で対応可能
- 1つずつ実行する(A)は運用負荷が高い

**表示すべき情報:**
- 仕入先コード + 仕入先名
- `auto_order_generation_time`（締切時間）があれば表示
- INTERNAL仕入先かEXTERNAL仕入先かの区別
- 該当倉庫に関連する仕入先のみ表示（`item_contractors` に存在するもの）

#### 2. PENDING候補の削除範囲

**現状**: 倉庫のPENDING候補を全仕入先分削除してから再生成
**問題**: 仕入先Aだけ再生成したい時に、仕入先BのPENDING候補まで消える

**選択肢:**
- (A) 選択した仕入先のPENDING候補のみ削除
- (B) 全仕入先のPENDING候補を削除（従来動作）
- (C) ユーザーに選択させる（確認ダイアログ）

**推奨: (A) 選択した仕入先のPENDING候補のみ削除**

理由:
- 仕入先別に独立して生成・削除できなければ、仕入先別生成の意味がない
- 仕入先Aを10:00に生成 → 仕入先Bを15:00に生成、の運用が成立する

**実装箇所:**
- `ListWmsAutoOrderJobControls::getGenerateByWarehouseAction()` のPENDING削除クエリに `whereIn('contractor_id', $selectedContractorIds)` を追加
- `ProcessOrderCandidateGenerationJob` の `deletePending` フラグの扱い

#### 3. INTERNAL移動候補の扱い

**現状のコード（L553）:**
```php
// Note: 仕入先指定がある場合でも、INTERNAL発注先は常に全件処理する
// （外部発注先のスケジュール実行時にも移動候補を生成するため）
```

**問題**: 仕入先Aのみ指定して生成した場合でも、全INTERNALの移動候補が再生成される。仕入先BのINTERNAL移動候補まで影響を受ける可能性がある。

**選択肢:**
- (A) 選択した仕入先がINTERNALの場合のみ、そのINTERNAL仕入先の移動候補を生成
- (B) 選択した仕入先にINTERNALが含まれていなければ、移動候補は生成しない（`transferOnly=false` でもスキップ）
- (C) 現状維持（常に全INTERNAL生成） — ただし削除範囲も全INTERNALになる

**推奨: (A) 選択した仕入先に含まれるINTERNALのみ生成**

理由:
- 仕入先別に独立した生成を保証するため
- ただし、EXTERNAL計算で移動候補の影響（`incomingFromTransfer`, `outgoingToTransfer`）を考慮している箇所に影響あり → 計算ロジックの注意が必要

**影響:**
- `createInternalTransferCandidatesBulk()` のINTERNAL仕入先リストにフィルタを追加
- `loadTransferCandidatesToMemory()` は同バッチの移動候補を読むので、部分生成でも正しく動く
- **ただし**: 仕入先AのINTERNAL移動候補が未生成の状態で仕入先BのEXTERNAL候補を計算すると、移動出庫分が反映されない → 在庫計算が不正確になるリスク

#### 4. EXTERNAL候補の在庫計算における移動候補の影響

**現状の計算式（L797）:**
```php
$calculatedStock = $effectiveStock + $incomingStock + $incomingFromTransfer - $outgoingToTransfer;
```

**問題**: 仕入先Aだけ生成した場合、仕入先BのINTERNAL移動候補がまだ存在しない（または古い）可能性がある。

**選択肢:**
- (A) 同バッチの移動候補のみ参照（現状動作）— 部分生成では不完全
- (B) PENDINGステータスの全移動候補を参照（バッチ横断）
- (C) 移動候補の影響を無視して在庫のみで計算

**推奨: (B) PENDINGステータスの全移動候補を参照**

理由:
- 仕入先Aの移動候補が先に生成されている場合、仕入先Bの計算でその影響を反映すべき
- `loadTransferCandidatesToMemory()` を同バッチ限定からPENDING全件に拡張

**実装変更:**
- `loadTransferCandidatesToMemory($batchCode)` → `loadTransferCandidatesToMemory()` （バッチ指定を外し、PENDINGステータスで全件ロード）
- **ただし**: 先に生成された仕入先のPENDING移動候補が削除されていない前提

#### 5. 排他制御の見直し

**現状:**
- 仕入先指定なし: APPROVED候補が該当倉庫に存在すればブロック
- 仕入先指定あり: 該当仕入先のPENDING/APPROVED候補が存在すればブロック

**仕入先別生成の場合:**
- 仕入先AがAPPROVEDでも、仕入先BはPENDING → 仕入先BだけPENDING削除して再生成したいケース

**選択肢:**
- (A) 選択した仕入先のPENDING/APPROVED状態のみチェック（他の仕入先は無視）
- (B) 倉庫全体でAPPROVED候補があればブロック（現状に近い）
- (C) APPROVEDチェックは仕入先単位、PENDINGは自動削除

**推奨: (A) 選択した仕入先のみチェック**

理由:
- 仕入先Aが確定待ちでも仕入先Bの生成に影響しないべき
- ただし、APPROVED仕入先は削除せずブロックする（誤操作防止）

#### 6. batch_code と WmsAutoOrderJobControl の管理

**現状:**
- `batch_code` は `YmdHis + warehouseId(3桁)` 
- 1回の生成で1つの `WmsAutoOrderJobControl` レコード
- `findPendingSettlement()` は倉庫・仕入先を区別しない

**仕入先別生成の問題:**
- 仕入先Aと仕入先Bで別の `batch_code` になる
- 確定処理で「同じバッチの候補をまとめて確定」する仕組みに影響
- `findPendingSettlement()` が1件しか返さないため、複数バッチの管理が困難

**選択肢:**
- (A) 仕入先が異なっても同日同倉庫なら同じ `batch_code` を再利用
- (B) 仕入先別に異なる `batch_code` を生成（現状動作の延長）
- (C) `batch_code` はそのまま、`WmsAutoOrderJobControl` に `contractor_ids` カラムを追加して追跡

**推奨: (A) 同日同倉庫で同じ batch_code を再利用**

理由:
- 確定処理は `batch_code` 単位で動くため、仕入先ごとにバッチが分かれると確定が複雑化する
- 「10:00に仕入先A分を生成 → 15:00に仕入先B分を追加生成」= 同バッチに追加
- `WmsAutoOrderJobControl` のPENDINGジョブが同日同倉庫に1件だけなら、そのbatch_codeを再利用

**実装:**
- `getGenerateByWarehouseAction()` で既存のPENDINGジョブを探し、あれば `batch_code` を再利用
- `OrderCandidateCalculationService::calculate()` に `$batchCode` パラメータを追加（外部から指定可能に）
- `ProcessOrderCandidateGenerationJob` にも `$batchCode` パラメータを追加
- **注意**: 既存候補を削除する際は、選択仕入先の候補のみ削除（他の仕入先の候補は保持）

#### 7. WmsAutoOrderJobControl の target_scope 活用

**現状**: `target_scope` カラムはJSON型で存在するが、ほぼ未使用。

**提案**: 仕入先別生成時に `target_scope` にメタデータを保存:
```json
{
  "contractor_ids": [1, 2, 3],
  "warehouse_id": 5,
  "source": "warehouse_contractor_specific"
}
```

これにより、ジョブ履歴画面でどの仕入先を対象に生成したかが確認できる。

#### 8. result_data への仕入先別集計の追加

**現状の result_data:**
```json
{
  "batchCode": "20260405101530005",
  "calculated": 150,
  "orderCandidates": 120,
  "transferCandidates": 30,
  "byWarehouse": [...]
}
```

**提案**: 仕入先別の集計を追加:
```json
{
  "byContractor": [
    {"contractor_name": "仕入先A", "order_count": 80, "transfer_count": 20},
    {"contractor_name": "仕入先B", "order_count": 40, "transfer_count": 10}
  ]
}
```

#### 9. UI: 倉庫に関連する仕入先のみ表示

**必要なクエリ:**
```sql
SELECT DISTINCT ic.contractor_id, c.code, c.name, cs.auto_order_generation_time, cs.transmission_type
FROM item_contractors ic
JOIN contractors c ON ic.contractor_id = c.id
LEFT JOIN wms_contractor_settings cs ON c.id = cs.contractor_id
WHERE ic.warehouse_id = :warehouseId
  AND ic.is_auto_order = true
  AND c.is_auto_change_order = true
ORDER BY c.code
```

- 倉庫に`item_contractors`が存在する仕入先のみ
- `is_auto_order = true` のもののみ
- INTERNAL/EXTERNALの区別を表示

#### 10. 子仕入先（transmission_contractor_id）の考慮

**現状**: 親仕入先を選択すると `getContractorIdsWithChildren()` で子仕入先も展開される。

**UI上の考慮:**
- 子仕入先は親にぶら下がっている → 親を選択すれば子も含まれる
- 子仕入先を個別に選択可能にするか？
- `transmission_contractor_id IS NOT NULL` の仕入先はUIに表示しない（親の選択で包含）か？

**推奨**: 親仕入先のみ表示（`transmission_contractor_id IS NULL`）。選択時に子仕入先も自動包含。

#### 11. 確定処理（ProcessOrderConfirmationJob）への影響

**現状**: APPROVED候補を `batch_code` でグループ化して確定処理。

**仕入先別生成の影響:**
- 同バッチ内に仕入先A（10:00生成・承認済み）と仕入先B（15:00生成・未承認）が混在
- 確定処理は仕入先を区別せず、APPROVEDのものをすべて処理 → **問題なし**
- ただし、仕入先Aだけ先に確定したい場合は、現在の確定フローでは対応不可

**将来の検討**: 仕入先別の確定処理（今回のスコープ外）

#### 12. 自動確定レベル（WmsWarehouseAutoOrderSetting）との整合

**現状**: `ProcessOrderCandidateGenerationJob` の `applyConfirmationLevels()` で倉庫ごとの自動確定レベルを適用。

**仕入先別生成の影響:**
- 自動確定レベルは倉庫単位（仕入先単位ではない）
- 仕入先Aの候補を生成 → 自動承認/確定が適用される
- **問題なし**: 確定レベルは生成された候補に対して一律適用

#### 13. 全倉庫ウィザード（管理者メニュー）への影響

**現状**: `getOrderGenerationWizardAction()` は全倉庫・全仕入先を一括生成。
**影響**: 今回のスコープ外。管理者メニューは引き続き全仕入先一括。

#### 14. `AutoOrderScheduledCommand` との競合

**現状**: スケジューラーは仕入先別に `auto_order_generation_time` で自動実行。

**競合シナリオ:**
- 10:00 にスケジューラーが仕入先Aを自動生成
- 10:05 にユーザーが同じ倉庫・同じ仕入先Aを手動生成しようとする
- → 排他制御で PENDING 候補が存在するためブロック（or 削除して再生成）

**推奨**: 手動生成時にPENDING候補を削除して再生成する現行動作を維持。

## 変更対象ファイル

### 既存変更

| ファイル | 変更内容 |
|---------|---------|
| `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` | `getGenerateByWarehouseAction()` に仕入先選択UI追加 |
| `app/Services/AutoOrder/OrderCandidateCalculationService.php` | `calculate()` に `$batchCode` パラメータ追加、INTERNAL仕入先フィルタ、移動候補ロード拡張 |
| `app/Jobs/ProcessOrderCandidateGenerationJob.php` | `$contractorIds` (複数) + `$batchCode` パラメータ追加 |
| `app/Models/WmsAutoOrderJobControl.php` | `startJob()` に `target_scope` 保存、`findPendingSettlementForWarehouse()` 追加 |

### 参照のみ

| ファイル | 参照理由 |
|---------|---------|
| `app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php` | スケジューラーの動作確認 |
| `app/Models/WmsContractorSetting.php` | 親子仕入先の展開ロジック |
| `app/Jobs/ProcessOrderConfirmationJob.php` | 確定処理への影響確認 |
| `app/Jobs/ProcessAutoSendJob.php` | 自動送信への影響確認 |

## 確認事項

1. **仕入先の選択方式**: 複数選択 (MultiSelect) でよいか？全選択デフォルトにするか、空デフォルトにするか？
全選択デフォルト．　複数選択可能．　選択なし不可（全てか複数か）．　カスタムVIEWをつくりUIを工夫すること。（仕入れ先が多い場合やりづらさがあるため．　仕入れ先名やコードで絞ってチェックできるように。絞りをかえてもチェックは維持できるように）
2. **INTERNAL移動候補の扱い**: 選択仕入先がEXTERNALのみの場合、移動候補は生成しなくてよいか？
生成しなくても良い
3. **batch_code の再利用**: 同日同倉庫で同じ batch_code にまとめてよいか？（確定処理が一括で行われる）
よい。ただし、確定処理前の場合。ない場合は新規作成。
4. **子仕入先の表示**: 親仕入先のみ表示で子は自動包含、でよいか？
良い。
5. **確定処理の仕入先別化**: 今回のスコープに含めるか？（含めない場合、確定は引き続きバッチ全体）
全体でよい。仕入先別発注データ生成　　→確定 -> その後全ての仕入れ先で生成 (一度作成したので発注データはないはず.もう一度生成されても問題なし) -> 確定の運用になると思う。
6. **自動実行（スケジューラー）との統合**: 手動生成時にスケジューラーの実行ログを参照するか？
自動実行は根本的に修正が必要。そもそもスケジューラーも手動生成と同じ仕組みにする必要がある。今回のスクープからは除外

## 制約

- FK禁止: 全リレーションはアプリケーションレベル管理
- migrate:fresh/refresh/reset/db:wipe 禁止: 共有DB
- 計算ロジック（不足数計算・単位切り上げ）は変更しない
- 既存のスケジューラー動作（`AutoOrderScheduledCommand`）を壊さない
- 既存の管理者メニュー（全倉庫ウィザード・仕入先別強制生成）は変更しない
