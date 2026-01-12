# 自動発注システム 実装状況および仕様比較レポート

**報告日時:** 2025年12月13日
**参照仕様書:** `storage/specifications/auto-ordering/prompts/` 配下の各フェーズ定義書

本レポートでは、提示された仕様書（Prompts）と現在の実装コードを比較し、進捗状況および仕様との差異、今後の修正方針をまとめます。

## 1. 全体進捗概況

| フェーズ | 仕様書定義 | 実装ステータス | 評価 |
| :--- | :--- | :--- | :--- |
| **Phase 0** | 基盤・マスタ整備 | ✅ 概ね完了 | DB定義、モデル作成は仕様通り完了。 |
| **Phase 1** | 休日管理 | ✅ 実装済み | カレンダー定義、休日判定ロジック実装済み。 |
| **Phase 2** | 発注ルール設定 | ⚠️ 部分的実装 | DB構造は完了。設定UIやバリデーションは未確認。 |
| **Phase 3** | 計算ロジック | ⚠️ ロジック不足 | 全体フローはOKだが、**Lot適用ロジック**が未実装 (TODO状態)。 |
| **Phase 4** | 確認UI | ⬜ 未確認 | 今回のコード確認範囲には含まれておらず。 |
| **Phase 5** | 発注実行 | ⚠️ 片側のみ | Hub側(JX)はモック実装済み。Satellite側(移動)の実装が見当たらない。 |
| **Phase 6** | 監視・通知 | ⬜ 未実装 | Dashboard, NotificationService等は未実装。 |

---

## 2. 仕様との差異・未実装箇所と修正方向性

### 2.1 Lotルールの適用ロジック (Phase 3)

*   **仕様:** `phase-3-calculation-logic.md` および基本設計書において、計算された必要数を「ケース単位への切り上げ」「最低発注数の適用」「混載ルールの適用」によって補正することが必須とされています。
*   **現状:** `HubCalculationService.php` 内で計算された理論値 (`suggested_quantity`) がそのまま発注数 (`order_quantity`) に代入されており、`// TODO: ロットルール適用` とコメントアウトされています。
*   **修正方向性:**
    *   **優先度: 高**
    *   `HubCalculationService` に `applyLotRules(WmsOrderCandidate $candidate)` のようなメソッドを追加。
    *   `WmsWarehouseContractorOrderRule` を参照し、`order_quantity` を補正するロジックを実装してください。

### 2.2 Satellite（倉庫間移動）の実行処理 (Phase 5)

*   **仕様:** `phase-5-execution.md` では、`OrderExecutionService` が定義され、その中で Satellite倉庫分の候補データを `stock_transfer_queue` テーブル（既存システム連携用）に変換・保存する処理 (`createStockTransferQueue`) が定義されています。
*   **現状:** JX送信用の `OrderTransmissionService` は存在しますが、倉庫間移動データの作成を行うサービスクラスまたはメソッドが今回の確認範囲に見当たりません。
*   **修正方向性:**
    *   **優先度: 中**
    *   `App\Services\AutoOrder\OrderExecutionService` クラスを作成（または既存サービスに追記）し、承認済みの `WmsStockTransferCandidate` を `stock_transfer_queue` にインサートする処理を実装してください。

### 2.3 通知・監視機能 (Phase 6)

*   **仕様:** `phase-6-monitoring.md` にて、計算完了時やLot警告時の `NotificationService` や、ダッシュボードウィジェットの実装が定義されています。
*   **現状:** 関連ファイルが存在しません。
*   **修正方向性:**
    *   **優先度: 低（機能要件による）**
    *   まずは計算と発注実行（Phase 3 & 5）を完成させることを優先し、その後に通知機能を実装することを推奨します。

---

## 3. 結論

**データ構造（DB/Model）は仕様書を忠実に再現しており、非常に高品質です。**
現在は「枠組み」が完成し、「中身のロジック（特にLot計算と移動データ生成）」を埋め込む段階にあります。

**直近のToDo:**
1.  `HubCalculationService` のTODO（Lot計算）にロジックを実装する。
2.  Satellite側の移動指示データ生成ロジック (`stock_transfer_queue` への登録) を実装する。

以上
