# 仕様と実装の乖離レポート (Multi-Echelon Auto Order)

**作成日:** 2025年12月14日
**最終更新:** 2025年12月16日
**対象:** 多段階供給ネットワーク対応 (Multi-Echelon Supply Network)

本ドキュメントでは、最新の仕様書 (`2025-12-14-multi-echelon-changes.md`) と現在のコードベースにおける実装の差異について報告します。

---

## 1. 全体進捗概況

| フェーズ | 内容 | ステータス | 備考 |
| :--- | :--- | :---: | :--- |
| **Phase 0** | 基盤・マスタ整備 | ✅ 完了 | DB定義、モデル作成済み |
| **Phase 1** | 休日管理 | ✅ 完了 | カレンダー生成ロジック実装済み |
| **Phase 2** | 発注ルール設定 | ⚠️ 部分完了 | DBとモデルは完了、UIは未確認 |
| **Phase 3** | 計算ロジック | ⚠️ 部分完了 | Multi-Echelon計算OK、**Lotルール適用が未実装** |
| **Phase 4** | 確認UI | ⬜ 未着手 | Filament Resources未作成 |
| **Phase 5** | 発注実行 | ⚠️ 部分完了 | 外部発注(JX/FTP)はモック実装済、**移動候補→stock_transfer_queue変換が未実装** |
| **Phase 6** | 監視・通知 | ⬜ 未着手 | Dashboard, NotificationService未実装 |

---

## 2. ✅ 解決済みの課題

### 2.1 `wms_item_supply_settings` テーブル

| 項目 | 仕様 | 実装 | ステータス |
| :--- | :--- | :--- | :---: |
| 外部発注設定カラム | `item_contractor_id` | `item_contractor_id` | ✅ 一致 |
| リレーション | `itemContractor()` | `itemContractor()` | ✅ 一致 |

**確認済みファイル:**
- `database/migrations/2025_12_14_143048_create_wms_item_supply_settings_table.php` (line 27)
- `app/Models/WmsItemSupplySetting.php` (lines 36, 66-69)
- `app/Services/AutoOrder/MultiEchelonCalculationService.php` (lines 102, 191, 214)

---

## 3. ⚠️ 未実装・要対応の課題

### 3.1 Lotルール適用ロジック【優先度: 高】

**仕様:**
- 計算された必要数を「ケース単位への切り上げ」「最低発注数の適用」「混載ルールの適用」によって補正する

**現状:**
- `MultiEchelonCalculationService` で計算された `suggested_quantity` がそのまま `order_quantity` に代入されている
- `WmsWarehouseContractorOrderRule` モデルは存在するが、適用ロジック（サービス/メソッド）が未実装

**必要な実装:**
```php
// 例: LotRuleApplicator サービスの作成
class LotRuleApplicator
{
    public function apply(WmsOrderCandidate $candidate): void
    {
        $rule = WmsWarehouseContractorOrderRule::where('warehouse_id', $candidate->warehouse_id)
            ->where('contractor_id', $candidate->contractor_id)
            ->first();

        if (!$rule) return;

        // ケース単位への切り上げ
        if (!$rule->allows_piece) {
            $candidate->order_quantity = $this->roundUpToCase($candidate);
        }

        // 最低発注数チェック
        // 混載ルール適用
        // ...
    }
}
```

**参照ファイル:**
- `app/Models/WmsWarehouseContractorOrderRule.php`
- `app/Services/AutoOrder/MultiEchelonCalculationService.php`

---

### 3.2 移動候補の実行処理（stock_transfer_queue生成）【優先度: 中】

**仕様:**
- 承認済みの `wms_stock_transfer_candidates` を `stock_transfer_queue` テーブルに変換・保存する
- `stock_transfer_queue.request_id` には `order-{wms_stock_transfer_candidates.id}` を登録

**現状:**
- `OrderTransmissionService` は外部発注（JX/FTP）のみ対応
- 内部移動（倉庫間移動）用の `OrderExecutionService` または同等の処理が未実装
- 既存の `StockTransferQueueService` はShortage用で、AutoOrder用ではない

**必要な実装:**
```php
class TransferCandidateExecutionService
{
    public function executeApprovedTransfers(string $batchCode): void
    {
        $candidates = WmsStockTransferCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::APPROVED)
            ->get();

        foreach ($candidates as $candidate) {
            DB::table('stock_transfer_queue')->insert([
                'request_id' => "order-{$candidate->id}",
                'from_warehouse_id' => $candidate->hub_warehouse_id,
                'to_warehouse_id' => $candidate->satellite_warehouse_id,
                'item_id' => $candidate->item_id,
                'quantity' => $candidate->transfer_quantity,
                // ...
            ]);
        }
    }
}
```

---

### 3.3 確認UI（Phase 4）【優先度: 中】

**仕様:**
- 移動候補一覧・編集UI
- 発注候補一覧・編集UI
- バリデーション・警告表示

**現状:**
- Filament Resources 未作成
- `app/Filament/Resources/` 配下に AutoOrder 関連リソースなし

**必要な実装:**
- `WmsOrderCandidateResource` (発注候補管理)
- `WmsStockTransferCandidateResource` (移動候補管理)

---

### 3.4 監視・通知機能（Phase 6）【優先度: 低】

**仕様:**
- 計算完了時やLot警告時の通知
- ダッシュボードウィジェット
- 日次レポート生成

**現状:**
- 関連ファイル未作成

---

## 4. マイグレーション状況

全てのAutoOrder関連マイグレーションが **Pending** 状態です。

```
2025_12_13_164011_create_wms_auto_order_job_controls_table ......... Pending
2025_12_13_164100_create_wms_auto_order_settings_table ............. Pending
2025_12_13_164140_create_wms_warehouse_auto_order_settings_table ... Pending
2025_12_13_164602_create_wms_national_holidays_table ............... Pending
2025_12_13_164602_create_wms_warehouse_calendars_table ............. Pending
2025_12_13_164602_create_wms_warehouse_holiday_settings_table ...... Pending
2025_12_13_164857_create_wms_warehouse_contractor_order_rules_table  Pending
2025_12_13_165200_create_wms_stock_transfer_candidates_table ....... Pending
2025_12_13_165201_create_wms_order_candidates_table ................ Pending
2025_12_14_143048_create_wms_item_supply_settings_table ............ Pending
```

**注意:** `php artisan migrate` でマイグレーション実行が必要

---

## 5. 実装済みコンポーネント一覧

### サービス
| ファイル | 説明 | ステータス |
| :--- | :--- | :---: |
| `MultiEchelonCalculationService.php` | 多段階計算ロジック | ✅ 実装済み |
| `StockSnapshotService.php` | 在庫スナップショット生成 | ✅ 実装済み |
| `CalendarGenerationService.php` | カレンダー生成 | ✅ 実装済み |
| `OrderTransmissionService.php` | JX/FTP送信（モック） | ⚠️ モック |
| `LotRuleApplicator` (仮称) | Lotルール適用 | ⬜ 未実装 |
| `TransferExecutionService` (仮称) | 移動候補実行 | ⬜ 未実装 |

### モデル
| ファイル | ステータス |
| :--- | :---: |
| `WmsItemSupplySetting.php` | ✅ 実装済み |
| `WmsOrderCandidate.php` | ✅ 実装済み |
| `WmsStockTransferCandidate.php` | ✅ 実装済み |
| `WmsWarehouseContractorOrderRule.php` | ✅ 実装済み |
| `WmsOrderCalculationLog.php` | ✅ 実装済み |
| `WmsAutoOrderJobControl.php` | ✅ 実装済み |

---

## 6. 推奨アクションプラン

### 即時対応（優先度: 高）
1. `php artisan migrate` でマイグレーション実行
2. `LotRuleApplicator` サービス実装

### 短期対応（優先度: 中）
3. `TransferCandidateExecutionService` 実装
4. Filament Resources 作成（候補確認UI）

### 中期対応（優先度: 低）
5. JX/FTP実送信機能の実装（モック→本番）
6. 監視・通知機能の実装

---

## 変更履歴

| 日付 | 内容 |
| :--- | :--- |
| 2025-12-14 | 初版作成 |
| 2025-12-16 | `item_contractor_id` 問題の解決を確認。全体進捗・未実装項目を更新 |
