# Phase 4: 候補確認・修正UI

## 目的
発注候補・移動候補の確認画面と手動修正機能を実装する。

---

## 実装タスク

### 1. Filament管理画面

#### 1.1 移動候補一覧（WmsStockTransferCandidateResource）

**一覧テーブル:**
- バッチコード
- Satellite倉庫名
- Hub倉庫名
- 商品コード・商品名
- 理論必要数
- 移動数量（編集可能）
- 数量単位
- 入荷予定日
- ステータス
- ロット状態（バッジ表示）
  - RAW: グレー
  - ADJUSTED: 黄色
  - BLOCKED: 赤
  - NEED_APPROVAL: オレンジ
- 手動修正フラグ

**フィルター:**
- バッチコード
- 倉庫（Satellite/Hub）
- ステータス
- ロット状態
- 手動修正あり/なし

**一括操作:**
- 選択した候補を承認（APPROVED）
- 選択した候補を除外（EXCLUDED）

**インライン編集:**
- 移動数量の変更
- 除外理由の入力

#### 1.2 発注候補一覧（WmsOrderCandidateResource）

**一覧テーブル:**
- バッチコード
- 倉庫名
- 発注先名
- 商品コード・商品名
- 自倉庫不足数
- Satellite需要数
- 理論合計
- 発注数量（編集可能）
- 数量単位
- 入荷予定日
- ステータス
- ロット状態
- 送信状態

**フィルター:**
- バッチコード
- 倉庫
- 発注先
- ステータス
- ロット状態
- 送信状態

**一括操作:**
- 選択した候補を承認
- 選択した候補を除外
- 発注実行（Phase 5）

---

### 2. 詳細表示・編集画面

#### 2.1 候補詳細モーダル

**表示項目:**
- 基本情報（倉庫、商品、発注先）
- 計算入力値
  - 有効在庫
  - 入荷予定数
  - 安全在庫設定
  - リードタイム
- 計算結果
  - 理論必要数
  - ロット適用後数量
- ロットルール情報
  - 適用されたルール
  - 例外ルール（あれば）
  - 変更理由
- 入荷予定日
  - 元の日付
  - シフト後の日付
  - シフト理由（休日名）

**編集項目:**
- 発注/移動数量
- ステータス
- 除外理由

---

### 3. ダッシュボード表示

#### 3.1 発注候補サマリーウィジェット

**表示内容:**
- 今日のバッチ状態
- 移動候補件数（ステータス別）
- 発注候補件数（ステータス別）
- ロット警告件数
- 実行予定時刻までの残り時間

#### 3.2 ロット警告一覧ウィジェット

**表示内容:**
- BLOCKED/NEED_APPROVAL状態の候補
- クイックアクション（承認/除外）

---

### 4. バリデーション

#### 4.1 手動修正時のバリデーション
```php
class CandidateModificationService
{
    /**
     * 手動修正時のバリデーション
     */
    public function validateModification(
        WmsOrderCandidate|WmsStockTransferCandidate $candidate,
        int $newQuantity
    ): ValidationResult
    {
        $warnings = [];
        $errors = [];

        // ロットルール違反チェック
        $rule = $this->orderRuleService->getApplicableRule(
            $candidate->warehouse_id,
            $candidate->contractor_id,
            $candidate->item_id
        );

        // 最小数量チェック
        if ($newQuantity < $rule->minCaseQty) {
            $warnings[] = "最小発注数({$rule->minCaseQty})を下回っています";
        }

        // 倍数チェック
        if ($newQuantity % $rule->caseMultipleQty !== 0) {
            $warnings[] = "発注倍数({$rule->caseMultipleQty})に合っていません";
        }

        return new ValidationResult($errors, $warnings);
    }
}
```

---

### 5. 変更履歴

#### 5.1 変更履歴テーブル
```sql
CREATE TABLE wms_order_candidate_histories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    candidate_type ENUM('TRANSFER', 'ORDER') NOT NULL,
    candidate_id BIGINT UNSIGNED NOT NULL,

    field_name VARCHAR(50) NOT NULL,
    old_value VARCHAR(255) NULL,
    new_value VARCHAR(255) NULL,

    changed_by BIGINT UNSIGNED NOT NULL,
    changed_at DATETIME NOT NULL,

    INDEX idx_candidate (candidate_type, candidate_id)
) COMMENT '候補変更履歴';
```

---

### 6. 通知機能

#### 6.1 Slack/メール通知
- 計算完了時
- ロット警告発生時
- 実行時刻アラート（11:30など）

```php
class OrderCandidateNotificationService
{
    public function notifyCalculationComplete(string $batchCode): void;
    public function notifyLotWarnings(Collection $blockedCandidates): void;
    public function notifyExecutionReminder(): void;
}
```

---

## UI設計メモ

### 色分け規則
- PENDING: デフォルト
- APPROVED: 緑
- EXCLUDED: グレー
- EXECUTED: 青

### ロット状態の表示
- RAW: 「未適用」グレー
- ADJUSTED: 「調整済」黄色（ツールチップで変更内容）
- BLOCKED: 「ブロック」赤（理由表示）
- NEED_APPROVAL: 「要承認」オレンジ

### クイックアクション
- 一覧から直接承認/除外できるボタン
- 確認ダイアログ付き

---

## テスト項目

1. [ ] 一覧表示とフィルタリング
2. [ ] インライン編集
3. [ ] 一括操作
4. [ ] バリデーション警告表示
5. [ ] 変更履歴記録
6. [ ] 通知送信

---

## 次のフェーズ
Phase 5（発注実行・送信）へ進む
