# 倉庫間移動 (stock_transfers) ピッキング統合仕様書

倉庫間移動伝票（stock_transfers）をピッキング対象として波動生成・ピッキング処理に組み込む仕様書

**作成日**: 2026-01-16

---

## 1. 概要

### 1.1 背景

現在のWMSは出荷伝票（earnings）のみをピッキング対象としている。
倉庫間移動（stock_transfers）も同じピッキング作業が必要であるため、
波動生成時に stock_transfers を含め、ピッキングリストに出力できるようにする。

### 1.2 対象範囲

| 対象 | 説明 |
|------|------|
| stock_transfers | 倉庫間移動伝票（基幹システムで生成） |
| NOT 対象 | 仮想倉庫間移動（物理的ピッキング不要） |

### 1.3 除外条件

**仮想倉庫間移動はピッキング対象外**:
- `from_warehouse.is_virtual = true` かつ `to_warehouse.is_virtual = true`
- または `from_warehouse.stock_warehouse_id = to_warehouse.stock_warehouse_id`
- これらは帳簿上の移動であり、物理的なピッキング作業は不要

---

## 2. テーブル設計

### 2.1 stock_transfers テーブル（既存・基幹システム）

```sql
create table stock_transfers
(
    id                 bigint unsigned auto_increment
        primary key,
    trade_id           bigint unsigned                                                                                       not null,
    client_id          bigint unsigned                                                                                       not null,
    from_warehouse_id  bigint unsigned                                                                                       not null,
    created_at         timestamp                                                                                             null,
    updated_at         timestamp                                                                                             null,
    to_warehouse_id    bigint unsigned                                                                                       not null comment '移動先倉庫id',
    delivery_course_id bigint unsigned                                                                                       null comment '配送コースID',
    picking_status     enum ('BEFORE', 'BEFORE_PICKING', 'PICKING', 'SHORTAGE', 'COMPLETED', 'SHIPPED') default 'BEFORE'     not null comment '
    BEFORE,          -- 波動前／引当前
    BEFORE_PICKING,  -- ピッキング前（引当済）
    PICKING,         -- ピッキング中
    SHORTAGE,        -- 欠品あり（再配分または欠品確定待ち）
    COMPLETED,       -- ピッキング完了（出荷確定前）
    SHIPPED          -- 出荷確定（納品伝票出力済）',
    is_delivered       tinyint(1) as ((`picking_status` = _utf8mb4'SHIPPED')) stored comment '納品済みかどうか（picking_status=SHIPPEDでtrue）',
    delivered_date     date                                                                             default '2023-01-01' not null comment '納品日',
    is_confirmed       tinyint(1)                                                                       default 0            not null,
    is_active          tinyint(1)                                                                       default 1            not null comment '有効かどうか'
)
    collate = utf8mb4_unicode_ci;
```

### 2.2 picking_status 値

| 値 | 説明 |
|----|------|
| BEFORE | 未処理（波動生成前） |
| BEFORE_PICKING | 波動生成済み、ピッキング待ち |
| PICKING | ピッキング中 |
| COMPLETED | ピッキング完了 |

### 2.3 wms_picking_item_results 拡張

| カラム | 型                    | 説明 |
|--------|----------------------|------|
| source_type | ENUM                 | 'EARNING' or 'STOCK_TRANSFER' |　index必要
| stock_transfer_id | BIGINT UNSIGNED NULL | stock_transfers.id（source_type='STOCK_TRANSFER'時） |

**既存カラムとの整合性**:
- `earning_id` → source_type='EARNING' 時に使用
- `stock_transfer_id` → source_type='STOCK_TRANSFER' 時に使用

---

## 3. 波動生成フロー

### 3.1 現在のフロー（earnings のみ）

```
wms:generate-waves
    │
    ├─ 1. WaveSettingから対象の倉庫×配送コースを取得
    │
    ├─ 2. 対象earningsを検索
    │     └─ picking_status = 'BEFORE'
    │        AND warehouse_id = X
    │        AND delivery_course_id = Y
    │
    ├─ 3. Wave作成
    │
    ├─ 4. 在庫引当（StockAllocationService）
    │
    └─ 5. PickingTask / PickingItemResult作成
```

### 3.2 変更後のフロー（earnings + stock_transfers）

```
wms:generate-waves
    │
    ├─ 1. WaveSettingから対象の倉庫×配送コースを取得
    │
    ├─ 2. 対象伝票を検索
    │     │
    │     ├─ [A] earnings（既存）
    │     │     └─ picking_status = 'BEFORE'
    │     │        AND warehouse_id = X
    │     │        AND delivery_course_id = Y
    │     │
    │     └─ [B] stock_transfers（追加）
    │           └─ picking_status = 'BEFORE' # 移動生成時に自動的にSHIPPEDになる。
    │              AND from_warehouse_id = X
    │              AND delivery_course_id = Y
    │
    ├─ 3. Wave作成（earningsまたはstock_transfersがあれば）
    │
    ├─ 4. 在庫引当（StockAllocationService）
    │     └─ source_type = 'EARNING' or 'STOCK_TRANSFER'
    │
    └─ 5. PickingTask / PickingItemResult作成
          └─ source_type, stock_transfer_id を設定
```

### 3.3 仮想倉庫判定ロジック

```php
/**
 * stock_transfersがピッキング対象かどうかを判定
 *　# 移動生成時に自動的にpicking_status == SHIPPEDになる。
 */

```

---

## 4. ピッキングAPI対応

### 4.1 影響するAPIエンドポイント

| エンドポイント | 影響 |
|---------------|------|
| GET /api/picking/tasks | source_type追加、stock_transfer情報を含める |
| GET /api/picking/tasks/{id} | 同上 |
| POST /api/picking/tasks/{id}/start | 変更なし |
| POST /api/picking/tasks/{itemResultId}/update | 変更なし |
| POST /api/picking/tasks/{id}/complete | stock_transfers.picking_status更新追加 |

### 4.2 APIレスポンス変更

**GET /api/picking/tasks レスポンス例**:
```json
{
  "is_success": true,
  "result": {
    "data": [{
      "course": { "code": "910072", "name": "東京配送" },
      "picking_area": { "code": "A", "name": "エリアA" },
      "wave": { "wms_picking_task_id": 1, "wms_wave_id": 5 },
      "picking_list": [
        {
          "wms_picking_item_result_id": 1,
          "source_type": "EARNING",
          "earning_id": 12345,
          "stock_transfer_id": null,
          "slip_number": 12345,
          "item_id": 111110,
          "item_name": "白鶴　本醸造720ml",
          "planned_qty_type": "CASE",
          "planned_qty": 2,
          "picked_qty": 0,
          "status": "PENDING"
        },
        {
          "wms_picking_item_result_id": 2,
          "source_type": "STOCK_TRANSFER",
          "earning_id": null,
          "stock_transfer_id": 789,
          "slip_number": 789,
          "item_id": 111110,
          "item_name": "白鶴　本醸造720ml",
          "destination_warehouse": "大阪DC",
          "planned_qty_type": "CASE",
          "planned_qty": 5,
          "picked_qty": 0,
          "status": "PENDING"
        }
      ]
    }]
  }
}
```

### 4.3 タスク完了時の処理

```php
// POST /api/picking/tasks/{id}/complete

// 既存: earnings.picking_status更新
DB::connection('sakemaru')
    ->table('earnings')
    ->whereIn('id', $earningIds)
    ->update(['picking_status' => 'COMPLETED']);

// 追加: stock_transfers.picking_status更新
DB::connection('sakemaru')
    ->table('stock_transfers')
    ->whereIn('id', $stockTransferIds)
    ->update(['picking_status' => 'COMPLETED']);
```

---

## 5. 帳票出力

### 5.1 納品先倉庫別商品リスト（新規）

**配送員が使用する帳票** - ピッキング完了後、配送員が納品先倉庫へ商品を持っていく際に使用。

**横持ち出荷リストとの違い**:
| 種別 | 商品の流れ | 配送員の動き |
|------|-----------|-------------|
| 横持ち出荷 | 他倉庫 → 当倉庫 | 他倉庫へ行き、商品を**受け取る** |
| 倉庫間移動 | 当倉庫 → 他倉庫 | 商品を持って他倉庫へ**届ける** |

**帳票レイアウト**:
```
┌─────────────────────────────────────────────────────┐
│ 倉庫間移動 納品リスト                               │
├─────────────────────────────────────────────────────┤
│ 出荷元: 本社倉庫         出荷日: 2026-01-16         │
│ 納品先: 大阪DC           配送コース: 大阪便         │
├─────────────────────────────────────────────────────┤
│ No. │ 商品名          │ JAN        │ 数量 │ 備考   │
├─────────────────────────────────────────────────────┤
│ 1   │ 白鶴 本醸造720ml│ 4901681... │ 5 CS │        │
│ 2   │ 獺祭 純米大吟醸 │ 4905034... │ 3 CS │        │
├─────────────────────────────────────────────────────┤
│                        合計: 8 CS                   │
└─────────────────────────────────────────────────────┘
```

**必要情報**:
| 項目 | 説明 |
|------|------|
| 出荷元倉庫 | from_warehouse（ピッキング実施倉庫） |
| 納品先倉庫 | to_warehouse（配送員が届ける先） |
| 配送コース | delivery_course |
| 商品情報 | item_id, item_name, JAN, volume |
| 数量 | quantity, quantity_type |
| 出荷日 | delivered_date |

**グルーピング**:
- 納品先倉庫×配送コースでグループ化
- 1枚のリストで1つの納品先を表示

### 5.2 ピッキングリスト

ピッキング作業用のリストは earnings と stock_transfers を統合:
- source_type で区別可能
- 移動先倉庫を表示（通常出荷は配送先住所）
- ピッカーは区別せず同じ作業を実施

---

## 6. 実装計画

### Phase 1: データベース変更

1. **マイグレーション作成**
   - wms_picking_item_results に source_type, stock_transfer_id 追加
   - stock_transfers に picking_status 確認（なければ追加）

```php
// migration
Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
    $table->string('source_type', 32)->default('EARNING')->after('earning_id');
    $table->unsignedBigInteger('stock_transfer_id')->nullable()->after('source_type');
    $table->index('source_type');
    $table->index('stock_transfer_id');
});
```

### Phase 2: 波動生成修正

1. **GenerateWavesCommand.php**
   - stock_transfers検索ロジック追加
   - 仮想倉庫除外フィルタ
   - PickingItemResult作成時にsource_type設定

### Phase 3: API対応

1. **PickingTaskController.php**
   - レスポンスにsource_type, stock_transfer_id追加
   - 移動先倉庫情報を含める

2. **タスク完了時の処理**
   - stock_transfers.picking_status更新追加

### Phase 4: 帳票対応

1. **倉庫間移動リスト帳票**
   - Filament PrintRequestAction追加
   - PDF生成テンプレート作成

---

## 7. 対象ファイル一覧

| Phase | ファイル | 操作 |
|-------|---------|------|
| 1 | `database/migrations/..._add_stock_transfer_support_to_picking.php` | 新規 |
| 2 | `app/Console/Commands/GenerateWavesCommand.php` | 編集 |
| 2 | `app/Services/StockAllocationService.php` | 編集（source_type対応） |
| 3 | `app/Http/Controllers/Api/PickingTaskController.php` | 編集 |
| 3 | `app/Models/WmsPickingItemResult.php` | 編集（リレーション追加） |
| 4 | `app/Filament/Resources/WmsPickingTasks/...` | 編集（帳票アクション追加） |

---

## 8. 検証方法

### 8.1 単体テスト

1. **仮想倉庫判定テスト**
   - 仮想→仮想: false
   - 実→実: true
   - 仮想→実: true
   - 実→仮想: true

2. **波動生成テスト**
   - stock_transfers がピッキングタスクに含まれること
   - 仮想倉庫移動が除外されること

### 8.2 結合テスト

1. **ピッキング完了テスト**
   - earnings と stock_transfers が混在するWaveを完了
   - 両方の picking_status が COMPLETED になること

### 8.3 運用テスト

1. **帳票出力テスト**
   - ピッキングリストに移動伝票が含まれること
   - 倉庫間移動リスト単独出力

---

## 9. 注意事項

### 9.1 波動生成タイミング

stock_transfers は stock_transfer_queue 処理後に生成されるため、
波動生成タイミングに注意が必要。

```
05:00 - 移動候補承認
↓
06:00 - stock_transfer_queue 処理（sakemaru-ai-core）
        → stock_transfers 生成
↓
07:00 - 波動生成（stock_transfers を含む）
```

### 9.2 配送コース設定

stock_transfers に delivery_course_id が設定されていない場合、
波動生成対象外となる。

→ TransferCandidateExecutionService で必ず delivery_course_id を設定すること。

### 9.3 欠品処理

stock_transfers のピッキングで欠品が発生した場合:
- 現時点では代理出荷対象外（別倉庫への移動を中断）
- 欠品レコード（wms_shortages）を作成し、手動対応

---

## 10. 関連ドキュメント

- `storage/specifications/outbound/README.md` - 出荷システム仕様書
- `storage/specifications/ordering/README.md` - 発注システム仕様書（移動候補生成）
- `app/Services/AutoOrder/TransferCandidateExecutionService.php` - 移動候補確定サービス
