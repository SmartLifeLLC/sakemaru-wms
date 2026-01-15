# 発注システム仕様書

自動発注システム（Auto Ordering System）の統合仕様書

**最終更新**: 2026-01-15

---

## 1. 概要

各倉庫の在庫状況、安全在庫設定、入荷リードタイムに基づき、最適な発注数を自動計算するシステム。
Multi-Echelon（多段階供給）構造に対応し、INTERNAL（倉庫間移動）とEXTERNAL（外部発注）を統合管理。

---

## 2. システムアーキテクチャ

### 2.1 供給ネットワーク構造

```
仕入先A        仕入先B
   │              │
   ▼              ▼
┌────────┐    ┌────────┐
│中央倉庫 │     │ 地域   │     Level 2 (EXTERNAL発注)
│  (本社) │    │センター │
└───┬────┘    └───┬────┘
    │ INTERNAL    │
  ┌─┴─┐         ┌─┴─┐
  ▼   ▼         ▼   ▼
┌───┐┌───┐   ┌───┐┌───┐
│東京││大阪│  │福岡││札幌│   Level 1 (INTERNAL移動)
│DC ││DC │   │DC ││DC │
└───┘└───┘   └───┘└───┘
```

### 2.2 供給タイプ

| タイプ | 説明 | 出力先 |
|--------|------|--------|
| INTERNAL | 倉庫間移動 | `wms_stock_transfer_candidates` |
| EXTERNAL | 外部発注 | `wms_order_candidates` |

### 2.3 仮想倉庫の在庫管理

倉庫には実倉庫（`is_virtual=false`）と仮想倉庫（`is_virtual=true`）が存在する。
仮想倉庫は `stock_warehouse_id` で親となる実倉庫を参照する。

#### 基本方針

1. **仮想倉庫も在庫を持てる**
   - 仮想倉庫でも `real_stocks` に在庫レコードを持つことができる
   - 在庫の引当・出荷は仮想倉庫単位で行われる

2. **発注は実倉庫に集約**
   - 発注計算時、仮想倉庫の発注需要は親の実倉庫に集約される
   - 発注先への発注は実倉庫単位で行われる

3. **入荷時の在庫振り分け**
   - 物理的な入荷は実倉庫で行われる
   - 発注内容に基づき、入荷在庫を仮想倉庫に振り分ける
   - 発注確定後、入荷予定は仮想倉庫単位で作成される

4. **HUB倉庫からの横持ち**
   - HUB倉庫への移動依頼分は、入荷処理は拠点倉庫側で行う
   - 移動候補の入荷予定は依頼元の仮想倉庫で作成

#### 処理フロー

```
【発注計算時】
仮想倉庫A (需要50) ─┐
仮想倉庫B (需要30) ─┼→ 実倉庫X (集約需要80) → 発注候補80個
仮想倉庫C (需要0)  ─┘

【発注確定時】
発注80個 (実倉庫X)
    │
    ├→ 入荷予定: 仮想倉庫A 50個
    ├→ 入荷予定: 仮想倉庫B 30個
    └→ (仮想倉庫Cは需要0のため対象外)

【入荷時】
実倉庫Xに80個入荷（物理）
    │
    ├→ 仮想倉庫A在庫 +50
    └→ 仮想倉庫B在庫 +30
```

---

## 3. 日次処理フロー

| 時刻 | Phase | 処理 | コマンド |
|------|-------|------|---------|
| 05:00 | 0 | 在庫スナップショット生成 | `wms:snapshot-stocks` |
| 06:00 | 1-2 | 発注候補計算（INTERNAL→EXTERNAL） | `wms:auto-order-calculate` |
| 06:00〜12:00 | 3 | 担当者確認・修正 | Filament UI |
| 12:00 | 4 | 発注送信/移動指示 | `wms:transmit-orders` |

---

## 4. 計算ロジック

### 4.1 基本計算式

```
必要数 = (安全在庫 + LT中消費量) - (有効在庫 + 入荷予定数)
```

### 4.2 INTERNAL移動候補（Satellite→Hub）

```php
$shortageQty = $safetyStock - ($effectiveStock + $incomingStock);
$orderQty = ceil($shortageQty / $purchaseUnit) * $purchaseUnit;
```

### 4.3 EXTERNAL発注候補（移動候補を考慮）

```php
$calculatedStock = $effectiveStock + $incomingStock
                 + $incomingFromTransfer - $outgoingToTransfer;
$shortageQty = $safetyStock - $calculatedStock;
$orderQty = ceil($shortageQty / $purchaseUnit) * $purchaseUnit;
```

---

## 5. データベース設計

### 5.1 主要テーブル

| テーブル | 説明 |
|---------|------|
| `wms_order_candidates` | 発注候補（EXTERNAL） |
| `wms_stock_transfer_candidates` | 移動候補（INTERNAL） |
| `wms_order_calculation_logs` | 計算ログ |
| `wms_item_stock_snapshots` | 在庫スナップショット |
| `wms_auto_order_job_controls` | ジョブ管理 |

### 5.2 設定テーブル

| テーブル | 説明 |
|---------|------|
| `wms_contractor_settings` | 発注先設定（送信方法、供給倉庫等） |
| `wms_warehouse_auto_order_settings` | 倉庫別自動発注設定 |
| `wms_warehouse_contractor_order_rules` | ロットルール |
| `wms_order_jx_settings` | JX-FINET接続設定 |
| `wms_order_ftp_settings` | FTP接続設定 |
| `item_contractors` | 商品×倉庫×発注先の設定（safety_stock等） |

### 5.3 候補ステータス（CandidateStatus）

| 値 | 説明 |
|----|------|
| PENDING | 未確認（計算直後） |
| APPROVED | 承認済み |
| EXCLUDED | 除外 |
| EXECUTED | 実行済み |

### 5.4 Lotステータス（LotStatus）

| 値 | 説明 |
|----|------|
| RAW | 未適用 |
| ADJUSTED | 調整済み |
| BLOCKED | ロット未達でブロック |
| NEED_APPROVAL | 承認必要 |

---

## 6. サービスクラス

```
app/Services/AutoOrder/
├── OrderCandidateCalculationService.php  # 発注候補計算
├── StockSnapshotService.php              # 在庫スナップショット
├── OrderExecutionService.php             # 発注確定→入庫予定作成
├── OrderTransmissionService.php          # 発注送信（JX/FTP）
├── CalendarGenerationService.php         # 営業日カレンダー生成
├── ContractorLeadTimeService.php         # リードタイム計算
├── TransferCandidateApprovalService.php  # 移動候補承認
└── IncomingConfirmationService.php       # 入庫確定
```

---

## 7. Artisanコマンド

```bash
# 在庫スナップショット
php artisan wms:snapshot-stocks

# 発注候補計算
php artisan wms:auto-order-calculate [--skip-snapshot]

# カレンダー生成
php artisan wms:generate-calendars [--months=3] [--warehouse=]

# 祝日インポート
php artisan wms:import-holidays [--year=] [--force]

# 発注送信
php artisan wms:transmit-orders [--batch-code=] [--dry-run]
```

---

## 8. Filament UI

### 8.1 発注候補一覧
- **リソース**: `WmsOrderCandidates/`
- **機能**: 一覧表示、数量編集、承認、除外、手動追加

### 8.2 移動候補一覧
- **リソース**: `WmsStockTransferCandidates/`
- **機能**: 一覧表示、数量編集、承認、除外、手動追加

### 8.3 ジョブ管理
- **リソース**: `WmsAutoOrderJobControls/`
- **機能**: 実行履歴、進捗確認

---

## 9. 発注先設定（WmsContractorSetting）

### 9.1 送信タイプ（TransmissionType）

| 値 | 説明 |
|----|------|
| INTERNAL | 倉庫間移動（supply_warehouse_id必須） |
| JX_FINET | JX-FINETで送信 |
| FTP | FTPで送信 |
| MANUAL_CSV | 手動CSV出力 |

### 9.2 送信設定

- `transmission_time`: 送信時刻
- `is_transmission_*`: 曜日別送信フラグ
- `is_auto_transmission`: 自動送信フラグ

---

## 10. 実装状況

| 機能 | 状況 | 備考 |
|------|------|------|
| 在庫スナップショット | ✅ 完了 | 入荷予定数反映済み |
| 発注候補計算 | ✅ 完了 | INTERNAL/EXTERNAL対応 |
| 最小仕入単位切上げ | ✅ 完了 | `roundUpToUnit()` |
| カレンダー生成 | ✅ 完了 | 祝日・定休日対応 |
| 発注候補UI | ✅ 完了 | 一覧・編集・手動追加 |
| 移動候補UI | ✅ 完了 | 一覧・編集・手動追加 |
| 発注確定→入庫予定 | ✅ 完了 | OrderExecutionService |
| JX-FINET送信 | ⚠️ 部分 | モック状態 |
| Lotルール適用 | ⬜ 未実装 | ケース単位、混載等 |
| 監視・通知 | ⬜ 未実装 | ダッシュボード等 |

---

## 11. 月別発注点管理

季節変動に対応するため、発注点（安全在庫）を月別に管理する機能。

### 11.1 テーブル

| テーブル | 説明 |
|---------|------|
| `wms_monthly_safety_stocks` | 月別発注点設定 |
| `item_contractors.use_safety_stock_auto_update` | 自動更新フラグ（trueのみ同期対象） |

### 11.2 処理フロー

```
分析システム → CSVエクスポート → WMSにインポート → wms_monthly_safety_stocks
                                                              ↓
                                               日次バッチ(04:30)
                                                              ↓
                                               item_contractors.safety_stock更新
                                                              ↓
                                               発注計算(06:00)で使用
```

### 11.3 関連コマンド

```bash
# 月別発注点を item_contractors に同期
php artisan wms:sync-monthly-safety-stocks

# 特定月を指定して同期
php artisan wms:sync-monthly-safety-stocks --month=1

# ドライラン（更新なし）
php artisan wms:sync-monthly-safety-stocks --dry-run
```

### 11.4 CSVフォーマット

```csv
item_code,warehouse_code,contractor_code,month,safety_stock
10001,WH001,CNT001,1,100
10001,WH001,CNT001,2,150
```

詳細は `csv-export-guide.md` を参照。

---

## 12. 今後の課題

1. **Lotルール適用ロジック**
   - ケース単位切上げ
   - 最低発注数・倍数制約
   - 混載ルール

2. **JX-FINET本番接続**
   - 接続テスト
   - 本番認証情報設定

3. **監視・通知機能**
   - 計算完了通知
   - Lot警告通知
   - ダッシュボードウィジェット

---

## 13. 旧仕様書

詳細な設計資料は `old/ordering/` に移動:
- `2025-12-13-wms-auto-ordering-1.md` - 初期仕様書
- `prompts/` - 実装フェーズ別仕様
- `commands-reference.md` - コマンド詳細
