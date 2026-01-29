# 移動・発注統合確定フロー仕様書

## 1. 概要

### 1.1 背景と課題

現在のシステムでは、移動候補と発注候補が別々に確定処理される。

**現状の問題:**
- 移動候補と発注候補の確定タイミングがずれる可能性
- 移動数量の変更が発注数量に反映されない
- 移動承認前に発注確定ができてしまう（整合性の問題）

### 1.2 解決方針

移動候補と発注候補を統合的に管理し、同一タイミングで確定処理を行う。

---

## 2. フロー変更

### 2.1 新しいワークフロー

```
┌─────────────────────────────────────────────────────────────────┐
│ Step 1: 計算実行                                                │
│   → 移動候補 (INTERNAL) + 発注候補 (EXTERNAL) 同時生成          │
├─────────────────────────────────────────────────────────────────┤
│ Step 2: 移動候補承認 (/admin/wms-stock-transfer-candidates)     │
│   PENDING → APPROVED / EXCLUDED                                 │
│   ※ 移動数量の変更時は関連発注候補を自動再計算                   │
├─────────────────────────────────────────────────────────────────┤
│ Step 3: 発注候補承認 (/admin/wms-order-candidates)              │
│   ※ 移動候補に PENDING が残っている場合は承認不可（アラート）    │
│   PENDING → APPROVED / EXCLUDED                                 │
├─────────────────────────────────────────────────────────────────┤
│ Step 4: 一括確定 (/admin/wms-order-confirmation-waiting)        │
│   ※ 移動・発注に PENDING が残っている場合は確定不可             │
│   移動: APPROVED → EXECUTED (stock_transfer_queue作成)          │
│   発注: APPROVED → CONFIRMED → EXECUTED                         │
└─────────────────────────────────────────────────────────────────┘
```

---

## 3. UI/メニュー変更

### 3.1 メニュー名変更

| 現在 | 変更後 |
|-----|--------|
| 発注確定待ち | 移動・発注確定待ち |

### 3.2 ボタンラベル変更

| 現在 | 変更後 | 場所 |
|-----|--------|------|
| 発注確定 | 移動・発注確定 | /admin/wms-order-confirmation-waiting |
| 承認済み移動伝票生成 | **削除** | /admin/wms-stock-transfer-candidates |

---

## 4. 制約とバリデーション

### 4.1 発注承認時の制約

**条件:** 移動候補に PENDING ステータスが残っている場合

**動作:**
- 発注候補の承認ボタンをクリック時にアラート表示
- メッセージ: 「移動候補に未承認のデータがあります。先に移動候補を承認してください。」
- 承認処理は実行されない

**UI:**
```
┌─────────────────────────────────────────────────────┐
│ ⚠️ 発注承認不可                                     │
│                                                     │
│ 移動候補に未承認のデータが X 件 あります。          │
│ 先に移動候補の承認を完了してください。              │
│                                                     │
│ [移動候補一覧へ]              [閉じる]              │
└─────────────────────────────────────────────────────┘
```

### 4.2 移動・発注確定時の制約

**条件:** 移動候補または発注候補に PENDING ステータスが残っている場合

**動作:**
- 「移動・発注確定」ボタンクリック時にモーダルでエラー表示
- 確定処理は実行されない

**UI:**
```
┌─────────────────────────────────────────────────────┐
│ ❌ 確定できません                                   │
│                                                     │
│ 以下の未処理データがあります:                       │
│                                                     │
│ ■ 移動候補                                          │
│   ・未承認: X 件                                    │
│                                                     │
│ ■ 発注候補                                          │
│   ・未承認: X 件                                    │
│                                                     │
│ 全ての候補を承認または除外してください。            │
│                                                     │
│ [移動候補一覧へ] [発注候補一覧へ] [閉じる]          │
└─────────────────────────────────────────────────────┘
```

---

## 5. 移動候補テーブル拡張

### 5.1 追加カラム

`wms_stock_transfer_candidates` テーブルに以下のカラムを追加:

| カラム名 | 型 | 説明 | 編集可否 |
|---------|-----|------|---------|
| `current_effective_stock` | INT | 有効在庫 | 不可（参照のみ） |
| `incoming_quantity` | INT | 入庫予定数 | 可能 |
| `calculated_available` | INT | 計算後在庫 | 不可（自動計算） |
| `shortage_qty` | INT | 不足数 | 不可（自動計算） |
| `purchase_unit` | INT | 最小仕入単位 | 不可（参照のみ） |

### 5.2 計算式

```
calculated_available = current_effective_stock + incoming_quantity
shortage_qty = safety_stock - calculated_available
```

### 5.3 マイグレーション

```php
Schema::table('wms_stock_transfer_candidates', function (Blueprint $table) {
    $table->integer('current_effective_stock')->nullable()->after('transfer_quantity');
    $table->integer('incoming_quantity')->nullable()->after('current_effective_stock');
    $table->integer('calculated_available')->nullable()->after('incoming_quantity');
    $table->integer('shortage_qty')->nullable()->after('calculated_available');
    $table->integer('purchase_unit')->default(1)->after('shortage_qty');
});
```

### 5.4 一覧表示カラム（発注候補と統一）

| カラム | 移動候補 | 発注候補 | 備考 |
|-------|---------|---------|-----|
| 計算時刻 | ✅ | ✅ | |
| 依頼倉庫 | ✅ | - | 移動のみ |
| 移動元倉庫 | ✅ | - | 移動のみ |
| 倉庫 | - | ✅ | 発注のみ |
| 商品コード | ✅ | ✅ | |
| 商品名 | ✅ | ✅ | |
| 規格 | ✅ | ✅ | toggleable |
| 発注先 | ✅ | ✅ | toggleable |
| **現在庫** | ✅ 追加 | ✅ | |
| **入庫予定** | ✅ 追加（編集可） | ✅（編集可） | |
| **計算後在庫** | ✅ 追加 | ✅ | |
| 発注点 | ✅ | ✅ | |
| **不足分** | ✅ 追加 | ✅ | |
| 入数 | ✅ | ✅ | |
| 移動数/発注数 | ✅（編集可） | ✅（編集可） | |
| 移動出荷日/入荷予定 | ✅ | ✅ | |
| 状態 | ✅ | ✅ | |

---

## 6. 移動候補と発注候補の連動

### 6.1 紐付け方法

移動候補と発注候補は以下の条件で紐付ける（外部キーではなく動的に特定）:

```sql
-- 移動候補に対応する発注候補を特定
SELECT * FROM wms_order_candidates
WHERE batch_code = :transfer_batch_code
  AND warehouse_id = :hub_warehouse_id  -- 移動元倉庫 = 発注倉庫
  AND item_id = :item_id
  AND status IN ('PENDING', 'APPROVED')
```

### 6.2 移動数量変更時の発注再計算

移動候補の `transfer_quantity` が変更された場合:

```
┌─────────────────────────────────────────────────────────────────┐
│ 移動数量変更                                                    │
│   transfer_quantity: 10 → 15 (差分: +5)                         │
├─────────────────────────────────────────────────────────────────┤
│ 発注候補への影響                                                │
│   ・satellite_demand_qty: 移動合計から再計算                    │
│   ・order_quantity: 不足分を再計算して更新                      │
├─────────────────────────────────────────────────────────────────┤
│ 再計算式                                                        │
│   新satellite_demand = Σ(同一batch_code, hub_warehouse_id,      │
│                          item_id の移動数量)                    │
│   新order_quantity = self_shortage_qty + 新satellite_demand     │
│                      (purchase_unit で切り上げ)                 │
└─────────────────────────────────────────────────────────────────┘
```

### 6.3 発注候補の新規作成/削除ケース

#### ケース1: 移動追加時に発注候補がない場合

```
条件:
  - 移動候補を追加
  - 対応するHub倉庫の発注候補が存在しない

対応:
  1. Hub倉庫の在庫状況を確認
  2. 不足がある場合 → 発注候補を新規作成
  3. 不足がない場合 → 発注候補は作成しない（移動のみ）
```

#### ケース2: 移動削除/0に変更時に発注が不要になる場合

```
条件:
  - 移動数量を0に変更 or 移動候補を除外
  - 発注候補の satellite_demand_qty が 0 になる
  - self_shortage_qty も 0 の場合

対応:
  - 発注候補を自動的に EXCLUDED に変更
  - 理由: 「移動数量変更により発注不要」
```

### 6.4 再計算サービス

新規サービス `TransferOrderRecalculationService` を作成:

```php
class TransferOrderRecalculationService
{
    /**
     * 移動候補の数量変更時に関連発注候補を再計算
     */
    public function recalculateOrderForTransfer(
        WmsStockTransferCandidate $transfer,
        int $oldQuantity,
        int $newQuantity
    ): ?WmsOrderCandidate;

    /**
     * 移動候補追加時に発注候補の作成が必要か判定
     */
    public function checkAndCreateOrderCandidate(
        WmsStockTransferCandidate $transfer
    ): ?WmsOrderCandidate;

    /**
     * 移動候補削除時に発注候補の削除が必要か判定
     */
    public function checkAndRemoveOrderCandidate(
        WmsStockTransferCandidate $transfer
    ): bool;
}
```

---

## 7. 確定処理フロー

### 7.1 ProcessOrderConfirmationJob の拡張

```php
public function handle(): void
{
    // 1. 移動候補の確定処理
    $transferResult = $this->executeTransferCandidates();

    // 2. 発注候補の確定処理（既存）
    $orderResult = $this->executeOrderCandidates();

    // 3. 入庫予定作成（既存）
    $scheduleResult = $this->createIncomingSchedules();

    // 4. CSVファイル生成（既存）
    $csvResult = $this->generateCsvFiles();

    // 5. JXファイル生成（既存）
    $jxResult = $this->generateJxFiles();
}

private function executeTransferCandidates(): array
{
    // TransferCandidateExecutionService を使用
    // APPROVED → EXECUTED
    // stock_transfer_queue 作成
    // WmsOrderIncomingSchedule 作成（移動入荷）
}
```

### 7.2 進捗表示

```
移動・発注確定処理を実行中...

[===================>        ] 60%

■ 移動候補確定: 15/20 件
■ 発注候補確定: 完了
■ CSVファイル生成: 待機中
■ JXファイル生成: 待機中
```

---

## 8. 実装タスク

### Phase 1: データベース・モデル変更
- [ ] 移動候補テーブルにカラム追加（マイグレーション）
- [ ] WmsStockTransferCandidate モデル更新（fillable, casts）
- [ ] 計算サービスで新カラムに値を設定

### Phase 2: UI変更
- [ ] メニュー名変更（EMenu, EMenuCategory）
- [ ] 移動候補一覧に新カラム表示
- [ ] 移動候補詳細パネルに計算詳細追加
- [ ] 「承認済み移動伝票生成」ボタン削除

### Phase 3: バリデーション実装
- [ ] 発注承認時の移動PENDING チェック
- [ ] 確定時の PENDING チェック
- [ ] エラーモーダル実装

### Phase 4: 連動処理実装
- [ ] TransferOrderRecalculationService 作成
- [ ] 移動数量変更時の発注再計算
- [ ] 移動追加/削除時の発注候補管理

### Phase 5: 確定処理統合
- [ ] ProcessOrderConfirmationJob 拡張
- [ ] 進捗表示の拡張
- [ ] テスト実行

---

## 9. 影響範囲

### 9.1 変更ファイル

| ファイル | 変更内容 |
|---------|---------|
| `database/migrations/` | 新規マイグレーション |
| `app/Models/WmsStockTransferCandidate.php` | カラム追加 |
| `app/Enums/EMenu.php` | メニュー名変更 |
| `app/Enums/EMenuCategory.php` | カテゴリ名変更 |
| `app/Filament/Resources/WmsStockTransferCandidates/` | テーブル・アクション変更 |
| `app/Filament/Resources/WmsOrderCandidates/` | 承認時チェック追加 |
| `app/Filament/Resources/WmsOrderConfirmationWaiting/` | ボタン・チェック変更 |
| `app/Jobs/ProcessOrderConfirmationJob.php` | 移動確定処理追加 |
| `app/Services/AutoOrder/` | 新規サービス追加 |

### 9.2 既存機能への影響

- 移動候補の個別確定ができなくなる（発注と同時のみ）
- 発注承認のタイミングが移動承認完了後に制限される
- 確定処理の所要時間が増加する可能性

---

## 10. 備考

### 10.1 将来の拡張

- 移動候補と発注候補の外部キー連携（パフォーマンス向上）
- バッチ単位でのロック機能
- 承認ワークフローの導入（上長承認など）

### 10.2 ロールバック計画

緊急時は以下で旧フローに戻すことが可能:
1. 「承認済み移動伝票生成」ボタンを復活
2. 確定時の移動処理をスキップ
3. 承認時のチェックを無効化
