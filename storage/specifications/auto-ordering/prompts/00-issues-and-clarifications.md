# 仕様書の矛盾点・確認事項

## 1. ドキュメント構造の問題

### 1.1 Phase 4の重複
**問題:** Phase 4が2箇所に記載されている
- Phase 4: Execution & Transmission (Default 12:00)
- Phase 4: Data Transmission (12:00)

**推奨:** 1つに統合する

### 1.2 セクション番号の不整合
**問題:**
- 「## 2.2 休日管理」の後に「### 2.1. 休日ルール設定」がある（番号が逆転）
- 「## ロジック仕様」にセクション番号がない
- セクション番号の順序: 0 → 1 → 2 → 2.2 → ロジック仕様(番号なし) → 3 → 4 → 5

**推奨:** セクション番号を整理して再構成

---

## 2. テーブル設計の不完全さ

### 2.1 スキーマ定義が欠けているテーブル
- `wms_stock_transfer_candidates` - 完全なスキーマ定義なし
- `wms_client_settings` - 項目のみで詳細スキーマなし
- `wms_warehouse_contractor_settings` - 項目のみで詳細スキーマなし

**推奨:** 各Phaseの実装時にスキーマを確定

### 2.2 Hub/Satellite倉庫の判定
**問題:** 倉庫がHub（拠点倉庫）かSatellite（非拠点倉庫）かを判定するカラムが未定義

**推奨:** `warehouses`テーブルに以下を追加
```sql
warehouse_type ENUM('HUB', 'SATELLITE')
hub_warehouse_id BIGINT UNSIGNED NULL -- Satelliteの場合、所属するHubのID
```

---

## 3. 既存テーブルとの整合性

### 3.1 item_contractors テーブル
**問題:** 仕様書では「すでに存在」と記載されているが、以下のカラムの存在確認が必要
- `safety_stock`
- `max_stock`
- `is_auto_order`
- `lead_time_days`
- `is_holiday_delivery_available`

**確認方法:**
```sql
DESCRIBE item_contractors;
```

### 3.2 wms_warehouse_settings テーブル
**問題:** 仕様書の「4.2. Sunday/Holiday Arrival Logic」で `wms_warehouse_settings.exclude_sunday_arrival` が必要と記載されているが、このテーブル/カラムの定義がない

**推奨:** `warehouses`テーブルへのカラム追加、または専用設定テーブルを作成

### 3.3 stock_transfers vs wms_stock_transfers
**問題:**
- 仕様書では `stock_transfers` と記載
- 既存の `wms_stock_transfers` は倉庫内ロケーション移動用
- 仕様書の説明では `stock_transfer_queue` を使用すると記載

**整理:**
| 用途 | テーブル |
|------|---------|
| 倉庫内ロケーション移動 | wms_stock_transfers (既存) |
| 倉庫間移動（基幹連携） | stock_transfer_queue (既存) → stock_transfers (基幹システム) |
| 移動候補 | wms_stock_transfer_candidates (新規作成) |

---

## 4. ロジックの順序

### 4.1 Phase 3.5の位置
**問題:**
- Phase 3は「Review & Modification」
- Phase 3.5は「Lot / Mixed Load Application」
- しかしPhase 3では「候補テーブルには「Lot適用済」の推奨値が格納されている」と記載

**矛盾:** Lot適用がPhase 3より後に書かれているが、Phase 3の時点でLot適用済みである必要がある

**推奨される順序:**
1. Phase 1: Satellite計算 + Lot適用
2. Phase 2: Hub計算 + Lot適用
3. Phase 3: Review（Lot適用済みデータを確認・修正）
4. Phase 4: 実行・送信

---

## 5. 用語の曖昧さ

### 5.1 混載（Mixed Load）の定義
**問題:** `allows_mixed` が何を指すか不明確
- 同一発注先に複数商品を混載？
- 異なる発注先を1回の送信で混載？
- 異なる温度帯の商品を混載？

**推奨:** 仕様書に明確な定義を追加

### 5.2 消費予測（Consumption during LT）
**問題:** リードタイム中の消費予測の算出方法が未定義
- 過去の実績から算出？
- 固定値？
- 予測モデル使用？

**推奨:** 初期実装では「安全在庫」でカバーし、将来的に予測機能を追加

---

## 6. 実装前に解決が必要な項目

### 最優先（Phase 0開始前）
- [ ] `item_contractors`の現在の構造を確認
- [ ] `warehouses`テーブルへのカラム追加可否（基幹システムとの整合性）
- [ ] Hub/Satellite倉庫の判定方法の最終確認

### 高優先（Phase 1-2開始前）
- [ ] 混載ルールの定義確認
- [ ] 消費予測ロジックの決定

### 中優先（Phase 3開始前）
- [ ] Lot適用のタイミング確認
- [ ] ロット未達時のアクション詳細確認

---

## 7. 仕様書修正案

仕様書の構成を以下のように整理することを推奨:

```
1. System Overview
2. Business Flow & Timeline
   2.1 Phase 0: 在庫スナップショット
   2.2 Phase 1: Satellite計算
   2.3 Phase 2: Hub計算
   2.4 Phase 3: 確認・修正
   2.5 Phase 4: 実行・送信
3. Holiday Management
   3.1 休日ルール設定
   3.2 展開済みカレンダー
   3.3 計算時の利用ロジック
4. Database Schema Design
   4.1 マスタテーブル
   4.2 ルールテーブル
   4.3 トランザクションテーブル
5. Calculation Logic
   5.1 必要数計算式
   5.2 入荷予定日算出
   5.3 ロットルール適用
6. UI/UX Requirements
7. Transmission
   7.1 JX送信
   7.2 CSV出力
   7.3 FTP送信
```
