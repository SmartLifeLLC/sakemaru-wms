# 発注計算の分離戦略 — 安全在庫ベース自動発注 + 実績ベース手動発注

- **作成日**: 2026-04-21
- **ステータス**: 確認済み
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-wms

## 背景・目的

現在の発注候補生成（`OrderCandidateCalculationService::calculate()`）では、**安全在庫ベースの発注計算** と **過去3日間の出荷実績ベースの発注計算** が1つのフローに混在している。

具体的には `safety_stock = 0` の商品に対して `last_3d_qty`（過去3日間の出荷実績）をしきい値代わりに使用して発注候補を生成している。

### 目指す運用フロー

| 時刻 | 操作 | 対象 | 処理 |
|------|------|------|------|
| **9:30** | 手動実行（将来的に自動化予定） | `is_auto_order = true` の商品 | 安全在庫ベースで発注候補生成 → そのまま仕入先へ送信 |
| **11:00** | 担当者が手動でボタンクリック | 過去3日間の出荷実績があるが安全在庫が未設定の商品 | 実績ベースで発注候補生成 → 担当者が確認後に発注実行 |

### 変更の3本柱

1. **発注OFFボタン**: `admin/wms-stock-transfer-candidates` に「発注OFF」ボタンを追加し、`item_contractors.is_auto_order` を `false` にする。該当商品のPENDING候補も自動削除する
2. **計算分離**: 現在の計算ロジックから実績ベース計算を分離し、安全在庫ベースのみで自動発注を完結させる
3. **実績ベース発注機能**: 過去3日間の出荷実績に基づく発注候補生成を独立した機能として新設（`is_auto_order` フラグに関係なく、実績があれば対象）

---

## 現状の実装

### 発注候補生成フロー

```
発注・移動候補生成モーダル（ListWmsAutoOrderJobControls.php:182-298）
  ↓ Queue Dispatch
ProcessOrderCandidateGenerationJob
  ↓
OrderCandidateCalculationService::calculate()
  ├─ INTERNAL移動候補生成（lines 585-827）
  │   ├─ safety_stock > 0 → 安全在庫ベース
  │   └─ safety_stock = 0 & last_3d_qty > 0 → 実績ベース ← これを分離
  └─ EXTERNAL発注候補生成（lines 833-1100）
      ├─ safety_stock > 0 → 安全在庫ベース
      └─ safety_stock = 0 & last_3d_qty > 0 → 実績ベース ← これを分離
```

### 現在の除外条件（OrderCandidateCalculationService:610-620）

```php
->where('item_contractors.is_auto_order', true)
->where('items.end_of_sale_type', 'NORMAL')
->where('items.is_ended', false)
->where('contractors.is_auto_change_order', true)
// + 販売開始日・終了日チェック
```

### 過去3日間実績の使用箇所

- **データソース**: `stats_item_warehouse_sales_summaries.last_3d_qty`
- **INTERNAL計算** (lines 649-668): `safety_stock = 0` の場合、`last_3d_qty` をしきい値として使用
- **EXTERNAL計算** (lines 875-894): 同上

### `is_auto_order` フラグ

- **テーブル**: `item_contractors`
- **モデル**: `App\Models\Sakemaru\ItemContractor`
- **UI**: `admin/item-contractors/{id}/edit` の在庫設定セクションにToggleで存在
- **計算で使用**: `OrderCandidateCalculationService` のクエリ条件 `where('item_contractors.is_auto_order', true)`

---

## 変更内容

### 概要

1. 移動候補一覧に「発注OFF」レコードアクションを追加
2. `OrderCandidateCalculationService` から実績ベース計算（`safety_stock=0 & last_3d_qty`）を除外
3. 実績ベース発注候補生成の新規サービス・UIを追加

---

### 詳細設計

#### 1. 発注OFFボタン（移動候補一覧）

**対象ページ**: `admin/wms-stock-transfer-candidates`

**テーブルファイル**: `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php`

**追加するレコードアクション**:

```php
Action::make('toggleAutoOrder')
    ->label('発注OFF')
    ->icon('heroicon-o-no-symbol')
    ->color('danger')
    ->requiresConfirmation()
    ->modalHeading('自動発注対象から除外')
    ->modalDescription(fn ($record) => 
        "[{$record->item_code}] {$record->item_name}\nこの商品を自動発注対象から除外しますか？\n（item_contractors の自動発注フラグをOFFにします）")
    ->action(function ($record) {
        // 1. item_contractors.is_auto_order = false に更新
        ItemContractor::where('item_id', $record->item_id)
            ->where('contractor_id', $record->contractor_id)
            ->where('warehouse_id', $record->warehouse_id)
            ->update(['is_auto_order' => false]);
        
        // 2. 同一 item_id + contractor_id + warehouse_id の PENDING 候補を削除
        WmsStockTransferCandidate::where('item_id', $record->item_id)
            ->where('contractor_id', $record->contractor_id)
            ->where('satellite_warehouse_id', $record->satellite_warehouse_id)
            ->where('status', 'PENDING')
            ->delete();
        
        // 3. 対応する EXTERNAL 発注候補の PENDING も削除
        WmsOrderCandidate::where('item_id', $record->item_id)
            ->where('contractor_id', $record->contractor_id)
            ->where('warehouse_id', $record->warehouse_id)
            ->where('status', 'PENDING')
            ->delete();
        
        Notification::make()->title('自動発注対象から除外しました')->success()->send();
    })
    ->visible(fn ($record) => $record->status === 'PENDING')
```

---

#### 2. 既存計算ロジックの変更（安全在庫ベースのみに限定）

**対象ファイル**: `app/Services/AutoOrder/OrderCandidateCalculationService.php`

**変更箇所 — INTERNAL計算 (lines 649-668)**:

変更前:
```php
// safety_stock = 0 の場合、3日実績をしきい値として使用
if ($safetyStock === 0 && isset($this->salesSummaries3d[$warehouseId][$itemId])) {
    $threeDaySales = $this->salesSummaries3d[$warehouseId][$itemId];
    // ... 実績ベース計算
}
```

変更後:
```php
// safety_stock = 0 の場合はスキップ（実績ベース計算は別機能で実施）
if ($safetyStock === 0) {
    continue; // or skip this item
}
```

**変更箇所 — EXTERNAL計算 (lines 875-894)**: 同様の変更

**影響**: `safety_stock > 0` かつ `is_auto_order = true` の商品のみが計算対象になる

---

#### 3. 実績ベース発注候補生成（新規機能）

**新規サービス**: `app/Services/AutoOrder/SalesBasedOrderCandidateService.php`

**機能概要**:
- 過去3日間の出荷実績（`stats_item_warehouse_sales_summaries.last_3d_qty`）に基づいて発注候補を生成
- `is_auto_order = false` かつ `last_3d_qty > 0` の商品が対象（`safety_stock` の値は問わない）
- `is_auto_order = true` + `safety_stock = 0` のケースはどちらの計算にも含まれない（設定不備として許容）

**計算ロジック**:
```php
// 対象: is_auto_order = false で過去3日に出荷実績がある商品
// 不足分 = last_3d_qty - (effective_stock + incoming_qty)
// 不足分 > 0 の場合のみ候補を生成
```

**UIの追加箇所**: `admin/wms-auto-order-job-controls`

**モーダルの追加**:
- 既存の「発注・移動候補生成」モーダルとは別に「実績ベース発注候補生成」ボタンを追加
- ツールバーアクションとして配置
- 倉庫選択 + 発注先選択（既存モーダルと同じUI構成）

**Jobの追加**: `ProcessSalesBasedOrderCandidateJob`
- `SalesBasedOrderCandidateService::calculate()` を呼び出す
- 生成した候補は `wms_order_candidates` / `wms_stock_transfer_candidates` テーブルに格納（既存と同じテーブル）
- `batch_code` は安全在庫ベースと**同一バッチ**を使用する。安全在庫ベースの生成後に実績ベースの生成を実行した場合、同じ `batch_code` に候補が追加される。これにより同一仕入先への発注データを一括で送信できる

**WmsAutoOrderJobControl の process_name 追加**:
- 新規 enum 値: `SALES_BASED_CALC`（既存: `ORDER_CALC`）
- ジョブ履歴の識別には `process_name` を使用するが、生成される候補の `batch_code` は既存のPENDING settlement と同じものを再利用する

**batch_code 共有の運用フロー**:
```
9:30  安全在庫ベース生成 → batch_code=20260421093000002 で候補作成
        ↓ （PENDING settlement として残る）
11:00 実績ベース生成 → 同じ batch_code=20260421093000002 に候補を追加
        ↓
担当者が確認 → 同一バッチの全候補を一括確定 → 同じ仕入先への発注データをまとめて送信
```

---

### 影響範囲

| 機能 | 影響 |
|------|------|
| `admin/wms-stock-transfer-candidates` | レコードアクション追加（発注OFF + PENDING候補自動削除） |
| `admin/wms-auto-order-job-controls` | 新規ツールバーアクション追加（実績ベース生成ボタン） |
| `admin/item-contractors/{id}/edit` | 変更なし（既存の `is_auto_order` Toggle をそのまま使用） |
| `admin/wms-order-candidates` | 実績ベース候補も表示される（batch_code/process_nameで識別） |
| `OrderCandidateCalculationService` | 実績ベース計算ロジックの削除 |
| `ProcessOrderCandidateGenerationJob` | 変更なし（サービス側の変更で対応） |
| 9:30発注（現在は手動、将来自動化予定） | 安全在庫ベースのみで動作するようになる |

---

## 制約

- **FK禁止**: `item_contractors` は基幹システム共有テーブル。WMS側からは `update` のみ
- **migrate:fresh/refresh 禁止**: 本番データ保護
- **楽観ロック不要**: `is_auto_order` フラグ更新は単純な boolean 切り替え
- **既存候補への影響**: 発注OFFにした際、PENDING候補は自動削除する。APPROVED候補はそのまま残す（承認済みのため手動対応）

---

## 対象ファイル

### 新規作成

| ファイル | 説明 |
|----------|------|
| `app/Services/AutoOrder/SalesBasedOrderCandidateService.php` | 実績ベース発注候補生成サービス |
| `app/Jobs/ProcessSalesBasedOrderCandidateJob.php` | 実績ベース候補生成ジョブ |

### 既存変更

| ファイル | 変更内容 |
|----------|----------|
| `app/Services/AutoOrder/OrderCandidateCalculationService.php` | `safety_stock=0` の実績ベース計算を削除 |
| `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php` | 「発注OFF」レコードアクション追加 |
| `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` | 「実績ベース発注候補生成」ツールバーアクション追加 |
| `app/Models/WmsAutoOrderJobControl.php` | `process_name` enum に `SALES_BASED_CALC` 追加（必要に応じて） |

### 参照のみ

| ファイル | 参照理由 |
|----------|----------|
| `app/Models/Sakemaru/ItemContractor.php` | `is_auto_order` フラグの確認 |
| `app/Models/StatsItemWarehouseSalesSummary.php` | `last_3d_qty` フィールドの確認 |
| `app/Filament/Resources/ItemContractors/Schemas/ItemContractorForm.php` | 既存Toggle UIの確認 |
| `app/Jobs/ProcessOrderCandidateGenerationJob.php` | 新規ジョブの参考 |

---

## 決定事項（確認済み）

| # | 項目 | 決定 |
|---|------|------|
| 1 | 実績ベース計算の対象範囲 | `is_auto_order = false` かつ `last_3d_qty > 0` の商品が対象。`safety_stock` は考慮しない。`is_auto_order = true` + `safety_stock = 0` は設定不備として許容 |
| 2 | 9:30自動発注の仕組み | **現時点では不要**。現在のモーダル手動実行のまま。将来 `is_auto_order = true` の商品を自動化する際に別途設計 |
| 3 | batch_code 管理 | **同一バッチ**。安全在庫ベース生成後に実績ベース生成を実行した場合、同じ `batch_code` に候補を追加する。同一仕入先への発注データをまとめて送信するため |
| 4 | 発注OFF時のPENDING候補 | **自動削除する**。発注OFFにした際、同一商品のPENDING候補（移動候補・発注候補）を自動削除 |
| 5 | バルク発注OFF | **不要**。個別レコードアクションのみ |
