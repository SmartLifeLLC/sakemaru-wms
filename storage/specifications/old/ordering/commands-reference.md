# 自動発注システム コマンドリファレンス (Multi-Echelon対応版)

## 概要

多段階供給ネットワーク（Multi-Echelon）に対応した自動発注システムのコマンド一覧です。

---

## アーキテクチャ概要

```
┌─────────────────────────────────────────────────────────────────┐
│                 多段階供給ネットワーク (Multi-Echelon)            │
└─────────────────────────────────────────────────────────────────┘

  仕入先A        仕入先B
     │              │
     ▼              ▼
┌────────┐    ┌────────┐
│中央倉庫│    │ 地域   │     Level 2 (最上流・外部発注)
│  (本社)│    │センター│     supply_type = EXTERNAL
└───┬────┘    └───┬────┘
    │             │
  ┌─┴─┐         ┌─┴─┐
  ▼   ▼         ▼   ▼
┌───┐┌───┐   ┌───┐┌───┐
│東京││大阪│   │福岡││札幌│   Level 1 (中流・内部移動)
│DC ││DC │   │DC ││DC │   supply_type = INTERNAL
└─┬─┘└─┬─┘   └─┬─┘└─┬─┘
  │    │       │    │
┌─┴┐ ┌─┴┐   ┌─┴┐ ┌─┴┐
▼  ▼ ▼  ▼   ▼  ▼ ▼  ▼
店舗  店舗   店舗  店舗      Level 0 (最下流・内部移動)
                            supply_type = INTERNAL
```

---

## コマンド一覧

### 1. 在庫スナップショット生成

```bash
php artisan wms:snapshot-stocks
```

**説明:** 現在の在庫データから自動発注計算用のスナップショットを生成します。

**出力テーブル:** `wms_warehouse_item_total_stocks`

---

### 2. 自動発注計算（Multi-Echelon）

```bash
php artisan wms:auto-order-calculate [--skip-snapshot]
```

**説明:** 多段階供給ネットワーク全体の発注計算を実行します。

**オプション:**
| オプション | 説明 | デフォルト |
|-----------|------|----------|
| `--skip-snapshot` | スナップショット生成をスキップ | false |

**計算フロー:**
1. Level 0（最下流）から計算開始
2. 内部移動需要を上位階層に伝播
3. Level N（最上流）まで順次計算
4. 外部発注候補・移動候補を生成

**計算式:**
```
必要数 = (安全在庫 + LT中消費量 + 下位からの移動需要)
       - (有効在庫 + 入荷予定数)
```

**出力テーブル:**
- `wms_stock_transfer_candidates` (内部移動候補)
- `wms_order_candidates` (外部発注候補)

---

### 3. カレンダー生成

```bash
php artisan wms:generate-calendars [--months=3] [--warehouse=]
```

**説明:** 倉庫別の営業日カレンダーを生成します。

**出力テーブル:** `wms_warehouse_calendars`

---

### 4. 祝日インポート

```bash
php artisan wms:import-holidays [--year=] [--force]
```

**説明:** 日本の祝日データを外部APIからインポートします。

**出力テーブル:** `wms_national_holidays`

---

### 5. 発注送信

```bash
php artisan wms:transmit-orders [--batch-code=] [--dry-run]
```

**説明:** 承認済みの発注候補をJX-FINETまたはFTP経由で送信します。

**出力テーブル:**
- `wms_order_jx_documents`
- `wms_order_transmission_logs`

---

## 設定テーブル

### wms_item_supply_settings（新規・重要）

商品×倉庫ごとの供給ルートを定義します。

```sql
wms_item_supply_settings
├── warehouse_id        # 発注元倉庫
├── item_id             # 対象商品
├── supply_type         # INTERNAL(内部移動) / EXTERNAL(外部発注)
├── source_warehouse_id # 供給元倉庫ID (INTERNAL時)
├── contractor_id       # 発注先業者ID (EXTERNAL時)
├── lead_time_days      # 調達リードタイム
├── safety_stock_qty    # 安全在庫
├── daily_consumption_qty # 1日消費予測
├── hierarchy_level     # 供給階層レベル (0=最下流)
└── is_enabled          # 有効フラグ
```

### 設定例

| warehouse_id | item_id | supply_type | source_warehouse_id | contractor_id | hierarchy_level |
|-------------|---------|-------------|---------------------|---------------|-----------------|
| 店舗A (10)  | 商品X   | INTERNAL    | 東京DC (5)          | NULL          | 0               |
| 店舗B (11)  | 商品X   | INTERNAL    | 東京DC (5)          | NULL          | 0               |
| 東京DC (5)  | 商品X   | INTERNAL    | 中央倉庫 (1)        | NULL          | 1               |
| 中央倉庫 (1)| 商品X   | EXTERNAL    | NULL                | 仕入先A (100) | 2               |

---

## 計算フロー詳細

```
┌─────────────────────────────────────────────────────────────────┐
│                      計算実行フロー                              │
└─────────────────────────────────────────────────────────────────┘

[Phase 0] 在庫スナップショット生成
    │
    ▼
[Phase 1] 多段階計算ループ
    │
    ├── Level 0 計算
    │   ├── 店舗Aの商品X: 不足10個 → 移動候補作成
    │   │   └── 東京DCへの需要として登録 (+10)
    │   └── 店舗Bの商品X: 不足5個 → 移動候補作成
    │       └── 東京DCへの需要として登録 (+5)
    │
    ├── Level 1 計算
    │   └── 東京DCの商品X:
    │       自己不足3 + 下位需要15 = 18個不足
    │       → 移動候補作成
    │       └── 中央倉庫への需要として登録 (+18)
    │
    └── Level 2 計算
        └── 中央倉庫の商品X:
            自己不足5 + 下位需要18 = 23個不足
            → 発注候補作成 (仕入先Aへ)
    │
    ▼
[確認] 管理画面で候補を確認・承認
    │
    ▼
[送信] wms:transmit-orders で送信
```

---

## 廃止されたコマンド

以下のコマンドはMulti-Echelon対応により廃止されました：

- ~~`wms:calculate-satellite-orders`~~ → `wms:auto-order-calculate` に統合
- ~~`wms:calculate-hub-orders`~~ → `wms:auto-order-calculate` に統合

---

## スケジューラ設定

```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

| 時刻 | コマンド | 説明 |
|------|---------|------|
| 毎日 5:00 | `wms:snapshot-stocks` | 在庫スナップショット生成 |
| 毎日 6:00 | `wms:auto-order-calculate --skip-snapshot` | 多段階発注計算 |
| 毎月1日 4:00 | `wms:generate-calendars --months=3` | カレンダー生成 |
| 毎年1月1日 3:00 | `wms:import-holidays --year=翌年` | 祝日インポート |

---

## トラブルシューティング

### 循環参照エラー

**エラー:** `Circular reference detected: warehouse_5_item_10`

**原因:** 供給ルートに循環が発生している
```
倉庫A → 倉庫B → 倉庫C → 倉庫A (NG)
```

**対処:** `wms_item_supply_settings` の設定を見直し、循環を解消

### 階層レベルの不整合

**対処:** 階層レベルを再計算
```php
WmsItemSupplySetting::recalculateHierarchyLevels();
```

---

## 関連テーブル一覧

### 設定テーブル
- `wms_item_supply_settings` - 商品別供給設定 (**新規・重要**)
- `wms_warehouse_auto_order_settings` - 倉庫別設定
- `wms_warehouse_holiday_settings` - 休日設定
- `wms_warehouse_calendars` - 営業日カレンダー
- `wms_national_holidays` - 祝日マスタ

### 計算結果テーブル
- `wms_warehouse_item_total_stocks` - 在庫スナップショット
- `wms_stock_transfer_candidates` - 移動候補（内部移動）
- `wms_order_candidates` - 発注候補（外部発注）
- `wms_order_calculation_logs` - 計算ログ

### 送信関連テーブル
- `wms_order_jx_documents` - JX発注ドキュメント
- `wms_order_transmission_logs` - 送信ログ

### ジョブ管理テーブル
- `wms_auto_order_job_controls` - ジョブ実行履歴
