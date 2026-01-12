# WMS 仕様書

Smart WMS (Warehouse Management System) の仕様書ディレクトリ

**最終更新**: 2026-01-12

## ディレクトリ構成

```
storage/specifications/
├── README.md               # このファイル
├── ordering/README.md      # 発注システム仕様（自動発注、Multi-Echelon）
├── incoming/README.md      # 入荷システム仕様（入庫予定、仕入れ連携）
├── outbound/README.md      # 出荷システム仕様（ウェーブ、ピッキング、欠品）
├── api/README.md           # API仕様（Androidハンディ連携）
├── filament4spec.md        # Filament 4 フレームワーク仕様
└── old/                    # アーカイブ（旧仕様書）
    ├── ordering/           # 発注関連の詳細仕様
    ├── incoming/           # 入荷関連の詳細仕様
    ├── outbound/           # 出荷関連の詳細仕様
    └── api/                # API関連の詳細仕様
```

## 各仕様書の概要

### ordering/ - 発注システム

自動発注システムの仕様。Multi-Echelon（多段階供給）構造に対応。

- **INTERNAL**: 倉庫間移動（`wms_stock_transfer_candidates`）
- **EXTERNAL**: 外部発注（`wms_order_candidates`）
- **計算式**: `必要数 = (安全在庫 + LT中消費量) - (有効在庫 + 入荷予定数)`

### incoming/ - 入荷システム

入荷予定管理と仕入れデータ連携の仕様。

- **入庫予定**: `wms_order_incoming_schedules`
- **仕入れ連携**: `purchase_create_queue` → sakemaru-ai-core
- **ステータス**: PENDING → PARTIAL → CONFIRMED

### outbound/ - 出荷システム

ウェーブ生成からピッキング、出荷確定までの仕様。

- **在庫引当**: FEFO → FIFO 優先順位
- **ピッキング**: A*アルゴリズムによるルート最適化
- **欠品処理**: 代理出荷（ProxyShipmentService）

### api/ - API仕様

Androidハンディターミナル向けAPI仕様。

- **認証**: Bearer Token (JWT)
- **ピッキング**: タスク取得、数量登録、完了
- **マスタ**: 倉庫、ピッキングエリア

## 参照順序（推奨）

| 目的 | 参照先 |
|------|--------|
| 全体像把握 | このREADME |
| 発注機能開発 | `ordering/README.md` |
| 入荷機能開発 | `incoming/README.md` |
| 出荷機能開発 | `outbound/README.md` |
| API開発 | `api/README.md` |
| Filament実装 | `filament4spec.md` |
| 詳細設計確認 | `old/` 配下の旧仕様 |

## 更新履歴

| 日付 | 内容 |
|------|------|
| 2026-01-12 | 仕様書を統合・整理（各カテゴリREADME化、旧仕様をoldに移行） |
| 2026-01-10 | 入庫予定管理機能追加 |
| 2025-12-27 | 発注先設定変更 |
| 2025-12-13 | 自動発注システム初版 |
