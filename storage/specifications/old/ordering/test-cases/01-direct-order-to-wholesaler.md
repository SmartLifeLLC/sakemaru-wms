# テスト計画: 問屋への直接発注（拠点移動なし）

**作成日:** 2025年12月16日
**対象:** 外部発注（EXTERNAL）のみのシンプルケース
**前提:** Multi-Echelon構造を使用するが、倉庫間移動は発生しない

---

## 1. テスト概要

### 1.1 テスト範囲
- 単一倉庫 → 単一問屋（contractor）への発注
- `supply_type = EXTERNAL` のみ
- `hierarchy_level = 0`（最下流 = 最上流、単一階層）

### 1.2 テスト対象外（次フェーズ）
- 倉庫間移動（INTERNAL）
- 多段階階層（hierarchy_level > 0）
- Lotルール適用（ケース単位切り上げ、最低発注数）
- JX/FTP実送信

---

## 2. 前提条件・事前準備

### 2.1 マイグレーション実行
```bash
php artisan migrate
```

以下のテーブルが作成されていること：
- `wms_auto_order_job_controls`
- `wms_auto_order_settings`
- `wms_warehouse_auto_order_settings`
- `wms_item_supply_settings`
- `wms_order_candidates`
- `wms_stock_transfer_candidates`
- `wms_order_calculation_logs`
- `wms_national_holidays`
- `wms_warehouse_calendars`
- `wms_warehouse_holiday_settings`
- `wms_warehouse_contractor_order_rules`

### 2.2 テストデータ準備

#### A. 倉庫（既存マスタ使用）
| warehouse_id | name | 説明 |
|:---:|:---|:---|
| 1 | テスト倉庫A | 発注元倉庫 |

#### B. 商品（既存マスタ使用）
| item_id | item_name | case_quantity | 説明 |
|:---:|:---|:---:|:---|
| 100 | テスト商品A | 12 | 1ケース=12個 |
| 101 | テスト商品B | 24 | 1ケース=24個 |
| 102 | テスト商品C | 6 | 1ケース=6個 |

#### C. 発注先（contractors / item_contractors）
| contractor_id | contractor_name | 説明 |
|:---:|:---|:---|
| 10 | テスト問屋A | 商品A,Bの仕入先 |
| 11 | テスト問屋B | 商品Cの仕入先 |

#### D. 供給設定（wms_item_supply_settings）
```sql
INSERT INTO wms_item_supply_settings
  (warehouse_id, item_id, supply_type, item_contractor_id, lead_time_days, safety_stock_qty, daily_consumption_qty, hierarchy_level, is_enabled)
VALUES
  -- 商品A: LT=2日, 安全在庫=100, 日販=50
  (1, 100, 'EXTERNAL', <item_contractor_id_for_100>, 2, 100, 50, 0, 1),
  -- 商品B: LT=3日, 安全在庫=200, 日販=30
  (1, 101, 'EXTERNAL', <item_contractor_id_for_101>, 3, 200, 30, 0, 1),
  -- 商品C: LT=1日, 安全在庫=50, 日販=20
  (1, 102, 'EXTERNAL', <item_contractor_id_for_102>, 1, 50, 20, 0, 1);
```

---

## 3. テストケース

### TC-001: 在庫充足時（発注不要）

**目的:** 在庫が十分にある場合、発注候補が生成されないことを確認

**前提条件:**
| 項目 | 値 |
|:---|:---|
| 商品 | テスト商品A (item_id=100) |
| 有効在庫 | 300個 |
| 入荷予定 | 0個 |
| 安全在庫 | 100個 |
| LT中消費 | 100個（50×2日） |

**計算式:**
```
必要数 = (安全在庫 + LT消費) - (有効在庫 + 入荷予定)
      = (100 + 100) - (300 + 0)
      = 200 - 300
      = -100 (不足なし)
```

**期待結果:**
- `wms_order_candidates` にレコードが作成されない
- `wms_order_calculation_logs` に計算ログが記録される（不足なしとして）

**確認SQL:**
```sql
-- 発注候補が存在しないこと
SELECT COUNT(*) FROM wms_order_candidates
WHERE warehouse_id = 1 AND item_id = 100 AND batch_code = '<batch_code>';
-- 結果: 0

-- 計算ログが存在すること
SELECT * FROM wms_order_calculation_logs
WHERE warehouse_id = 1 AND item_id = 100 AND batch_code = '<batch_code>';
```

---

### TC-002: 在庫不足時（発注必要）

**目的:** 在庫不足時に正しい数量で発注候補が生成されることを確認

**前提条件:**
| 項目 | 値 |
|:---|:---|
| 商品 | テスト商品A (item_id=100) |
| 有効在庫 | 50個 |
| 入荷予定 | 0個 |
| 安全在庫 | 100個 |
| LT中消費 | 100個（50×2日） |

**計算式:**
```
必要数 = (安全在庫 + LT消費) - (有効在庫 + 入荷予定)
      = (100 + 100) - (50 + 0)
      = 200 - 50
      = 150 (不足あり)
```

**期待結果:**
- `wms_order_candidates` にレコードが作成される
  - `suggested_quantity = 150`
  - `order_quantity = 150`（Lotルール未適用のため同値）
  - `status = PENDING`
  - `lot_status = RAW`

**確認SQL:**
```sql
SELECT * FROM wms_order_candidates
WHERE warehouse_id = 1 AND item_id = 100 AND batch_code = '<batch_code>';
```

---

### TC-003: 入荷予定ありの場合

**目的:** 入荷予定を考慮した発注数量計算を確認

**前提条件:**
| 項目 | 値 |
|:---|:---|
| 商品 | テスト商品A (item_id=100) |
| 有効在庫 | 50個 |
| 入荷予定 | 80個 |
| 安全在庫 | 100個 |
| LT中消費 | 100個（50×2日） |

**計算式:**
```
必要数 = (安全在庫 + LT消費) - (有効在庫 + 入荷予定)
      = (100 + 100) - (50 + 80)
      = 200 - 130
      = 70 (不足あり)
```

**期待結果:**
- `wms_order_candidates.suggested_quantity = 70`

---

### TC-004: 複数商品の同時計算

**目的:** 同一倉庫で複数商品の発注候補が正しく生成されることを確認

**前提条件:**
| 商品 | 有効在庫 | 入荷予定 | 安全在庫 | LT日数 | 日販 | 期待発注数 |
|:---|:---:|:---:|:---:|:---:|:---:|:---:|
| 商品A | 50 | 0 | 100 | 2 | 50 | 150 |
| 商品B | 100 | 50 | 200 | 3 | 30 | 140 |
| 商品C | 100 | 0 | 50 | 1 | 20 | 0（不足なし） |

**商品B計算:**
```
必要数 = (200 + 90) - (100 + 50) = 290 - 150 = 140
```

**商品C計算:**
```
必要数 = (50 + 20) - (100 + 0) = 70 - 100 = -30（不足なし）
```

**期待結果:**
- 商品A, Bの発注候補が生成される（2件）
- 商品Cは発注候補なし

---

### TC-005: 入荷予定日の計算（休日なし）

**目的:** リードタイム加算による入荷予定日計算を確認

**前提条件:**
- 計算基準日: 2025-12-16（月曜日）
- LT日数: 2日
- 休日設定: なし

**期待結果:**
- `expected_arrival_date = 2025-12-18`（木曜日）
- `original_arrival_date = 2025-12-18`

---

### TC-006: 入荷予定日の計算（休日あり）

**目的:** 休日を考慮した入荷予定日計算を確認

**前提条件:**
- 計算基準日: 2025-12-20（金曜日）
- LT日数: 2日
- 休日設定: 2025-12-22（日曜日）は休日

**計算:**
```
基準日 + LT = 2025-12-22（日曜日）→ 休日
→ 翌営業日 = 2025-12-23（月曜日）
```

**期待結果:**
- `expected_arrival_date = 2025-12-23`
- `original_arrival_date = 2025-12-22`
- 計算ログに `shifted_days = 1` が記録される

---

### TC-007: 発注先（contractor）の紐付け

**目的:** item_contractor経由で正しい発注先が設定されることを確認

**前提条件:**
- item_contractor_id = 5（商品A × 問屋A）

**期待結果:**
- `wms_order_candidates.contractor_id = <問屋AのID>`

---

### TC-008: バッチコードの一意性

**目的:** 同一バッチ内で複数回計算しても重複しないことを確認

**手順:**
1. 計算実行（バッチ1）
2. 同じ条件で再度計算実行（バッチ2）

**期待結果:**
- 異なるbatch_codeが発行される
- 各バッチに対応する候補が別々に存在

---

### TC-009: ジョブ制御（重複実行防止）

**目的:** 計算中に再実行しようとした場合のエラー処理を確認

**手順:**
1. 計算ジョブ開始（長時間化のためsleepを入れる）
2. 同時に別の計算ジョブを開始しようとする

**期待結果:**
- 2番目のジョブは `RuntimeException: Calculation job is already running` で失敗

---

### TC-010: 在庫0の場合

**目的:** 在庫が完全にゼロの場合の計算を確認

**前提条件:**
| 項目 | 値 |
|:---|:---|
| 有効在庫 | 0個 |
| 入荷予定 | 0個 |
| 安全在庫 | 100個 |
| LT中消費 | 100個 |

**計算:**
```
必要数 = (100 + 100) - (0 + 0) = 200
```

**期待結果:**
- `suggested_quantity = 200`

---

## 4. テスト実行手順

### 4.1 ユニットテスト
```bash
# テストファイル作成後
php artisan test --filter=MultiEchelonCalculationServiceTest
```

### 4.2 手動テスト（Tinker）
```bash
php artisan tinker
```

```php
use App\Services\AutoOrder\MultiEchelonCalculationService;

$service = new MultiEchelonCalculationService();
$job = $service->calculateAll();

// 結果確認
dd([
    'batch_code' => $job->batch_code,
    'status' => $job->status,
    'processed' => $job->processed_count,
]);
```

### 4.3 コマンドラインテスト（将来）
```bash
php artisan wms:auto-order:calculate
```

---

## 5. 確認ポイントチェックリスト

| # | 確認項目 | 確認方法 | 結果 |
|:---:|:---|:---|:---:|
| 1 | マイグレーション完了 | `php artisan migrate:status` | ⬜ |
| 2 | テストデータ投入 | SQLで確認 | ⬜ |
| 3 | TC-001: 在庫充足時 | 発注候補0件 | ⬜ |
| 4 | TC-002: 在庫不足時 | 発注候補生成 | ⬜ |
| 5 | TC-003: 入荷予定考慮 | 正しい数量 | ⬜ |
| 6 | TC-004: 複数商品 | 各商品計算OK | ⬜ |
| 7 | TC-005: 入荷日計算 | 日付正確 | ⬜ |
| 8 | TC-006: 休日考慮 | 日付シフト | ⬜ |
| 9 | TC-007: 発注先紐付け | contractor_id正確 | ⬜ |
| 10 | TC-008: バッチコード | 一意性確認 | ⬜ |
| 11 | TC-009: 重複実行防止 | エラー発生 | ⬜ |
| 12 | TC-010: 在庫0 | 正しく計算 | ⬜ |

---

## 6. 既知の制限事項

1. **Lotルール未適用:** 現時点では `suggested_quantity` がそのまま `order_quantity` になる
2. **UI未実装:** Filamentでの確認UIは別途作成予定
3. **実送信未実装:** JX/FTP送信はモック状態

---

## 7. 次フェーズへの引き継ぎ

本テスト完了後、以下のテストケースを追加予定：

1. `02-warehouse-transfer.md` - 倉庫間移動テスト
2. `03-lot-rule-application.md` - Lotルール適用テスト
3. `04-multi-level-hierarchy.md` - 多段階階層テスト

---

## 変更履歴

| 日付 | 内容 |
|:---|:---|
| 2025-12-16 | 初版作成 |
