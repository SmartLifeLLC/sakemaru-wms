# 自動発注システム仕様変更: 多段階供給ネットワーク対応 (Multi-Echelon Supply Network)

**作成日:** 2025年12月14日
**対象:** 自動発注システム (Auto Ordering System)

本ドキュメントでは、従来の「Hub / Satellite」という固定的な2階層構造を廃止し、より柔軟な「多段階供給ネットワーク」に対応するための仕様変更について詳述します。

---

## 1. 背景と変更理由 (Background & Motivation)

### 1.1 現行仕様の課題
従前の仕様では、倉庫を「Hub（拠点）」または「Satellite（非拠点）」のいずれかに分類し、SatelliteはHubに、Hubは外部サプライヤーに発注するという固定ルールでした。しかし、現実のサプライチェーンには以下の課題があります。

1.  **多段階階層の非対応:**
    *   地域デポ → 地域センター → 中央センター → メーカー のような3段階以上の構成に対応できない。
2.  **商品別ルートの非対応:**
    *   「商品Aは倉庫Xから、商品Bは倉庫Yから供給を受ける」といった柔軟なルート設定ができない。
3.  **役割の固定化による矛盾:**
    *   ある倉庫が「ある商品にとっては供給元（Hub）」であり、「別の商品にとっては受給側（Satellite）」である場合、現行の倉庫単位のマスタ設定 (`warehouse_type`) では矛盾が生じる。

### 1.2 解決策
倉庫自体に「役割（Hub/Satellite）」を持たせるのをやめ、**「商品ごとの供給ルート（Supply Route）」** を定義する方式へ変更します。これにより、任意の深さの階層構造と、商品ごとの複雑な物流ルートを表現可能にします。

---

## 2. データベース構造の変更 (Schema Changes)

### 2.1 廃止・変更項目

*   **廃止:** `wms_warehouse_auto_order_settings` テーブルの以下のカラムを廃止（または非推奨化）します。
    *   `warehouse_type` (Enum: HUB, SATELLITE)
    *   `hub_warehouse_id` (固定の親倉庫ID)

### 2.2 新規追加・変更項目

#### A. 供給ルート設定 (`wms_item_supply_settings`)

各倉庫・商品ごとに「どこから調達するか」を定義します。

```sql
CREATE TABLE wms_item_supply_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- "誰の" 在庫設定か
    warehouse_id BIGINT UNSIGNED NOT NULL COMMENT '発注元倉庫',
    item_id BIGINT UNSIGNED NOT NULL COMMENT '対象商品',

    -- 供給元タイプ (内部移動 or 外部発注)
    supply_type ENUM('INTERNAL', 'EXTERNAL') NOT NULL DEFAULT 'EXTERNAL',

    -- 内部移動 (INTERNAL) の場合の供給元
    source_warehouse_id BIGINT UNSIGNED NULL COMMENT '供給元倉庫ID (INTERNAL時必須)',

    -- 外部発注 (EXTERNAL) の場合の設定
    -- contractor_id ではなく、既存の item_contractors テーブルへの参照を持つ
    item_contractor_id BIGINT UNSIGNED NULL COMMENT '仕入れ契約ID (EXTERNAL時必須)',

    -- このルート固有のパラメータ
    lead_time_days INT NOT NULL DEFAULT 1 COMMENT '調達リードタイム',
    safety_stock_qty INT NOT NULL DEFAULT 0 COMMENT '安全在庫数',

    -- 計算順序制御用
    hierarchy_level INT NOT NULL DEFAULT 0 COMMENT '供給階層レベル (0=最下流)',

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE KEY uk_wh_item (warehouse_id, item_id),
    INDEX idx_calc_order (hierarchy_level)
);
```

---

## 3. 発注計算ロジックの変更 (Calculation Logic Changes)

従来の「Phase 1 (Satellite計算)」「Phase 2 (Hub計算)」という時刻ベースの分離を廃止し、**「階層レベルに基づく順次計算フロー」** に刷新します。

### 3.1 新しい計算概念: "Hierarchy Level"

全倉庫・全商品を、供給の依存関係に基づいてレベル分けします。

*   **Level 0 (最下流):** 他の倉庫へ供給を行わない倉庫（店舗、末端デポなど）。
*   **Level 1 (中流):** Level 0 の倉庫へ供給を行うが、自身も上位倉庫から供給を受ける。
*   **Level 2 (上流):** Level 1 へ供給を行う。
*   ...
*   **Level N (最上流):** 外部サプライヤー（メーカー）から直接調達する倉庫。

※ このレベルは、マスタ設定保存時に自動計算するか、運用で設定します。

### 3.2 計算実行フロー

バッチ処理は以下のループで実行されます。

#### Step 1: 初期化 (Initialization)
全倉庫の在庫スナップショットを作成します。

#### Step 2: 階層別計算ループ (Level-based Calculation)
Level 0（最下流）から順に、Level N（最上流）まで計算を実行します。

**ループ処理 (for level = 0 to MAX_LEVEL):**

1.  **不足数計算:**
    *   そのレベルに属する `wms_item_supply_settings` を抽出。
    *   `必要数 = (安全在庫 + LT消費予測 + **下位からの移動要求数**) - (有効在庫 + 入荷予定)`
    *   計算結果が正（不足あり）の場合、次へ。

2.  **補充アクション作成:**
    *   `supply_type = EXTERNAL` の場合:
        *   **`wms_order_candidates` (外部発注候補)** を作成。
        *   これでこのチェーンは解決。
    *   `supply_type = INTERNAL` の場合:
        *   **`wms_stock_transfer_candidates` (移動候補)** を作成。
        *   **重要:** 作成された移動候補は、即座に供給元倉庫（上位レベル）の **「被・移動要求数 (Internal Demand)」** として登録される。

3.  **状態の伝播:**
    *   現在のレベルで発生した「内部移動需要」は、次のレベル（供給元倉庫）の計算時に「需要」として加算されるため、自然と上流へ連鎖していく。

### 3.3 ロジック比較

| 項目 | 旧仕様 (Fixed Hub-Satellite) | **新仕様 (Multi-Echelon)** |
| :--- | :--- | :--- |
| **構造** | 2階層固定 | **N階層 (無制限)** |
| **ルート定義** | 倉庫単位 (`warehouse_type`) | **商品×倉庫単位 (`wms_item_supply_settings`)** |
| **バッチ分割** | Hub/Satelliteで時刻を分ける | **1つのバッチ内でレベル順に連続実行** |
| **外部発注** | Hub倉庫のみ可能 | **どの倉庫でも可能 (設定次第)** |
| **移動需要** | Phase 1の結果をPhase 2で集計 | **下位レベルの結果を上位レベルが継承** |

---

## 4. 実装上の注意点

1.  **循環参照の防止 (Cycle Detection):**
    *   A倉庫→B倉庫→A倉庫 という設定が行われないよう、マスタ登録時にチェックが必要です。
    *   階層レベル (`hierarchy_level`) を導入することで、計算順序を容易に制御できます。

2.  **パフォーマンス:**
    *   商品・倉庫が増えると計算量が増大します。
    *   「全件ループ」ではなく、「Level 0 の計算」→「移動が発生したルートの親倉庫IDを特定」→「その親倉庫だけを次の計算対象にする」といった最適化が有効です。

3.  **移行措置:**
    *   既存の `Hub/Satellite` 設定データがある場合、それを読み込んで新しい `wms_item_supply_settings` に変換するマイグレーションスクリプトが必要です。
