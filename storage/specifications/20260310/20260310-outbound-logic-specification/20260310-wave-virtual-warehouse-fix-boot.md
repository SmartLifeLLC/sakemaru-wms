# Work Plan: wave-virtual-warehouse-fix

- **ID**: wave-virtual-warehouse-fix
- **作成日**: 2026-03-10
- **最終更新**: 2026-03-10
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/202503100000/20260310-outbound-logic-specification/`

## 概要

出荷波動生成（手動・自動）で仮想倉庫の earnings/stock_transfers が対象外になるバグを修正。`WarehouseResolver::resolveAllWarehouseIds()` を追加し、全クエリの `where` を `whereIn` に変更する。倉庫セレクトは実倉庫のみに制限。

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: WarehouseResolver メソッド追加 | 完了 | 2026-03-10 | resolveAllWarehouseIds() 追加 |
| P1: ListWaves.php 修正 | 完了 | 2026-03-10 | 7箇所修正（6クエリ+1セレクト） |
| P2: GenerateWavesCommand.php 修正 | 完了 | 2026-03-10 | 1箇所修正 |
| P3: 検証・Pint | 完了 | 2026-03-10 | Pint PASS、テスト151パス（既存10失敗は無関係） |

---

## Phase完了記録

### P0: WarehouseResolver メソッド追加
- 完了日: 2026-03-10
- 実績:
  - `resolveAllWarehouseIds(int $warehouseId): array` を追加
  - 実倉庫自身 + stock_warehouse_id が一致する仮想倉庫の全IDを返す

### P1: ListWaves.php 修正
- 完了日: 2026-03-10
- 実績:
  - `use App\Services\WarehouseResolver;` 追加
  - 倉庫セレクトを `where('is_virtual', false)` で実倉庫のみに制限
  - 配送コース選択肢 earnings: `whereIn('earnings.warehouse_id', $warehouseIds)`
  - 配送コース選択肢 stock_transfers: `whereIn('st.from_warehouse_id', $warehouseIds)`
  - プレビュー earnings: `whereIn('earnings.warehouse_id', $warehouseIds)`
  - プレビュー stock_transfers: `whereIn('st.from_warehouse_id', $warehouseIds)`
  - generateManualWave() earnings: `whereIn('warehouse_id', $warehouseIds)`
  - getEligibleStockTransfersQuery(): `whereIn('st.from_warehouse_id', $warehouseIds)`

### P2: GenerateWavesCommand.php 修正
- 完了日: 2026-03-10
- 実績:
  - getEligibleStockTransfersQuery(): `whereIn('st.from_warehouse_id', $warehouseIds)`
  - WarehouseResolver は既に import 済みだったため use 文追加不要

### P3: 検証・Pint
- 完了日: 2026-03-10
- 実績:
  - Pint: 3ファイル全てPASS
  - テスト: 151パス、10失敗（既存、今回の変更とは無関係）
