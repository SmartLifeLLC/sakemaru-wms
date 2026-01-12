# 自動発注機能 実装ガイド

## 概要

各倉庫の在庫状況、安全在庫設定、入荷リードタイムに基づき、最適な発注数を自動計算するシステム。
拠点倉庫（Hub）と非拠点倉庫（Satellite）の階層構造に対応する。

---

## システム構成図

```
┌─────────────────────────────────────────────────────────────────┐
│                        自動発注システム                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────┐     ┌──────────┐     ┌──────────┐               │
│  │ Satellite │────▶│   Hub    │────▶│  外部    │               │
│  │   倉庫   │ 移動 │   倉庫   │ 発注 │ 発注先  │               │
│  └──────────┘     └──────────┘     └──────────┘               │
│       │                │                │                      │
│       ▼                ▼                ▼                      │
│  wms_stock_      wms_order_       JX送信/CSV/FTP              │
│  transfer_       candidates                                    │
│  candidates                                                    │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## 日次処理フロー

| 時刻 | Phase | 処理内容 |
|------|-------|---------|
| 09:55 | Phase 0 | 在庫スナップショット生成 |
| 10:00 | Phase 1 | Satellite倉庫の移動候補計算 |
| 10:30 | Phase 2 | Hub倉庫の発注候補計算 |
| 10:30〜12:00 | Phase 3 | 担当者による確認・修正 |
| 12:00 | Phase 4 | 発注実行（JX送信/CSV生成/倉庫移動） |

---

## 発注計算ロジック

### 基本計算式
```
必要発注数 = (安全在庫 + LT中消費予測) - (有効在庫 + 入荷予定数)
```

### ロット適用
- ケース単位への切り上げ
- 最小発注数・倍数制約
- 混載ルール適用

---

## 実装フェーズ

### [Phase 0: 事前準備・マスタ設定](./phase-0-preparation.md)
- ジョブ管理テーブル作成
- 在庫スナップショットテーブル作成
- クライアント設定・倉庫設定
- Hub/Satellite倉庫の判定カラム追加

### [Phase 1: 休日管理機能](./phase-1-holiday-management.md)
- 休日ルール設定（定休日・祝日）
- 展開済みカレンダー生成
- 入荷予定日の休日シフトロジック

### [Phase 2: 発注先・ロットルール設定](./phase-2-order-rules.md)
- JX/FTP接続設定
- ロット・混載ルール設定
- ルール例外設定

### [Phase 3: 発注候補計算ロジック](./phase-3-calculation-logic.md)
- Satellite倉庫の移動候補計算
- Hub倉庫の発注候補計算
- 計算ログ記録

### [Phase 4: 候補確認・修正UI](./phase-4-review-ui.md)
- 移動候補一覧・編集
- 発注候補一覧・編集
- バリデーション・警告表示

### [Phase 5: 発注実行・送信](./phase-5-execution.md)
- stock_transfer_queue生成
- JX送信
- CSV生成・FTP送信

### [Phase 6: 運用・監視](./phase-6-monitoring.md)
- ダッシュボード
- アラート・通知
- レポート・監査ログ

---

## 新規作成テーブル一覧

| テーブル名 | 用途 | Phase |
|-----------|------|-------|
| `wms_auto_order_job_controls` | バッチ実行管理 | 0 |
| `wms_warehouse_item_total_stocks` | 在庫スナップショット | 0 |
| `wms_client_settings` | クライアント設定 | 0 |
| `wms_warehouse_holiday_settings` | 休日ルール設定 | 1 |
| `wms_warehouse_calendars` | 展開済みカレンダー | 1 |
| `wms_national_holidays` | 祝日マスタ | 1 |
| `wms_warehouse_contractor_settings` | 発注先接続設定 | 2 |
| `wms_order_jx_settings` | JX接続設定 | 2 |
| `wms_order_ftp_settings` | FTP接続設定 | 2 |
| `wms_warehouse_contractor_order_rules` | ロットルール | 2 |
| `wms_order_rule_exceptions` | ルール例外 | 2 |
| `wms_stock_transfer_candidates` | 移動候補 | 3 |
| `wms_order_candidates` | 発注候補 | 3 |
| `wms_order_calculation_logs` | 計算ログ | 3 |
| `wms_order_candidate_histories` | 変更履歴 | 4 |
| `wms_order_jx_documents` | JX送信ドキュメント | 5 |
| `wms_order_execution_logs` | 実行履歴 | 5 |
| `wms_auto_order_notification_settings` | 通知設定 | 6 |

---

## 既存テーブルへの変更

| テーブル名 | 変更内容 | Phase |
|-----------|---------|-------|
| `warehouses` | `warehouse_type`, `hub_warehouse_id`, `exclude_sunday_arrival` 追加 | 0 |
| `item_contractors` | `safety_stock`, `max_stock`, `is_auto_order`, `lead_time_days`, `is_holiday_delivery_available` 追加（要確認） | 0 |

---

## Artisanコマンド一覧

```bash
# Phase 0
php artisan wms:snapshot-stocks              # 在庫スナップショット生成

# Phase 1
php artisan wms:generate-calendars           # カレンダー生成
php artisan wms:import-holidays {year}       # 祝日インポート

# Phase 3
php artisan wms:calculate-satellite-orders   # Satellite計算
php artisan wms:calculate-hub-orders         # Hub計算
php artisan wms:auto-order-calculate         # 統合計算

# Phase 5
php artisan wms:execute-orders               # 発注実行
php artisan wms:transmit-jx-orders           # JX送信
php artisan wms:export-order-csv             # CSV出力

# Phase 6
php artisan wms:health-check-auto-order      # ヘルスチェック
php artisan wms:generate-auto-order-report   # 日次レポート
```

---

## 実装前の確認事項

実装を開始する前に、[00-issues-and-clarifications.md](./00-issues-and-clarifications.md)を確認してください。

### 最優先確認項目
1. `item_contractors`テーブルの現在の構造
2. `warehouses`テーブルへのカラム追加可否（基幹システムとの整合性）
3. Hub/Satellite倉庫の判定方法

---

## 推奨実装順序

```
Phase 0 → Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phase 6
   ↓         ↓         ↓         ↓         ↓         ↓         ↓
 基盤     休日管理   ルール    計算     UI      実行     監視
```

各Phaseは前のPhaseに依存するため、順番に実装することを推奨します。

---

## 関連ドキュメント

- [仕様書](../2025-12-13-wms-auto-ordering-1.md)
- [矛盾点・確認事項](./00-issues-and-clarifications.md)
