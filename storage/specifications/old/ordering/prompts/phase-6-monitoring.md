# Phase 6: 運用・監視

## 目的
自動発注システムの運用監視、エラー検知、レポート機能を実装する。

---

## 実装タスク

### 1. ダッシュボード

#### 1.1 発注状況ダッシュボード

**ウィジェット:**

1. **本日のバッチ状況**
   - 現在のフェーズ
   - 次のフェーズまでの残り時間
   - 各フェーズの実行状況（成功/失敗/未実行）

2. **候補サマリー**
   - 移動候補: 合計/承認済/除外/警告
   - 発注候補: 合計/承認済/除外/警告

3. **ロット警告**
   - BLOCKED件数
   - NEED_APPROVAL件数
   - クイックアクションリンク

4. **送信状況**
   - JX送信: 成功/失敗/待機
   - CSV生成: 完了/未完了
   - FTP送信: 成功/失敗

5. **過去7日間の実績**
   - 日別発注件数グラフ
   - 日別発注金額グラフ

---

### 2. アラート・通知

#### 2.1 通知設定テーブル
```sql
CREATE TABLE wms_auto_order_notification_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    event_type ENUM(
        'CALCULATION_COMPLETE',
        'LOT_WARNING',
        'EXECUTION_REMINDER',
        'EXECUTION_COMPLETE',
        'EXECUTION_FAILED',
        'TRANSMISSION_FAILED'
    ) NOT NULL,

    -- 通知先
    notification_channel ENUM('SLACK', 'EMAIL', 'BOTH') DEFAULT 'BOTH',
    slack_webhook_url VARCHAR(255) NULL,
    email_addresses JSON NULL COMMENT 'メールアドレス配列',

    -- 条件
    is_enabled TINYINT(1) DEFAULT 1,
    threshold_count INT NULL COMMENT '警告閾値（件数）',

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) COMMENT '自動発注通知設定';
```

#### 2.2 NotificationService
```php
namespace App\Services\AutoOrder;

class NotificationService
{
    public function notifyCalculationComplete(string $batchCode, CalculationSummary $summary): void
    {
        $message = <<<MSG
        📊 発注計算完了
        バッチ: {$batchCode}
        移動候補: {$summary->transferCount}件
        発注候補: {$summary->orderCount}件
        警告: {$summary->warningCount}件
        MSG;

        $this->send('CALCULATION_COMPLETE', $message);
    }

    public function notifyLotWarnings(Collection $warnings): void
    {
        if ($warnings->isEmpty()) return;

        $message = <<<MSG
        ⚠️ ロット警告
        BLOCKED: {$warnings->where('lot_status', 'BLOCKED')->count()}件
        要承認: {$warnings->where('lot_status', 'NEED_APPROVAL')->count()}件
        確認をお願いします。
        MSG;

        $this->send('LOT_WARNING', $message);
    }

    public function notifyExecutionReminder(): void
    {
        $pendingCount = WmsOrderCandidate::where('status', 'PENDING')
            ->whereDate('created_at', today())
            ->count();

        if ($pendingCount > 0) {
            $message = "⏰ 発注実行まであと30分です。未承認の候補が{$pendingCount}件あります。";
            $this->send('EXECUTION_REMINDER', $message);
        }
    }

    public function notifyExecutionFailed(string $batchCode, array $errors): void;
    public function notifyTransmissionFailed(WmsOrderJxDocument $document): void;
}
```

---

### 3. レポート機能

#### 3.1 日次レポート
```php
namespace App\Services\AutoOrder;

class DailyReportService
{
    public function generateDailyReport(Carbon $date): DailyReport
    {
        return new DailyReport(
            date: $date,
            calculationSummary: $this->getCalculationSummary($date),
            executionSummary: $this->getExecutionSummary($date),
            transmissionSummary: $this->getTransmissionSummary($date),
            lotAnalysis: $this->getLotAnalysis($date),
        );
    }
}
```

#### 3.2 レポートエクスポート
- PDF出力
- Excel出力

---

### 4. 監視・ヘルスチェック

#### 4.1 HealthCheckCommand
```bash
php artisan wms:health-check-auto-order
```

**チェック項目:**
- DBコネクション
- JX接続テスト
- FTP接続テスト
- カレンダーデータの有無
- 在庫スナップショットの鮮度

#### 4.2 監視メトリクス
```php
// カスタムメトリクス（Prometheus等との連携用）
class AutoOrderMetrics
{
    public function recordCalculationDuration(float $seconds): void;
    public function recordCandidateCount(string $type, int $count): void;
    public function recordExecutionResult(string $type, bool $success): void;
    public function recordTransmissionLatency(float $seconds): void;
}
```

---

### 5. エラー復旧機能

#### 5.1 手動再実行
- 失敗したバッチの再計算
- 失敗した送信の再送信
- 部分的な再実行

#### 5.2 ロールバック
- 誤って実行した候補の取り消し（可能な範囲で）
- stock_transfer_queueのキャンセル

---

### 6. 設定画面

#### 6.1 自動発注設定リソース

**設定項目:**
- 計算ロジックタイプ
- 各フェーズの実行時刻
- 自動実行ON/OFF
- 通知設定

---

### 7. ログ・監査

#### 7.1 操作ログ
- 手動修正履歴
- 承認/除外履歴
- 設定変更履歴

#### 7.2 実行ログ
- バッチ実行ログ
- API通信ログ
- エラーログ

---

## Artisanコマンド（追加）

```bash
# ヘルスチェック
php artisan wms:health-check-auto-order

# 日次レポート生成
php artisan wms:generate-auto-order-report {date?}

# 手動再実行
php artisan wms:retry-auto-order {batchCode} {--phase=}

# ログクリーンアップ（30日以上前）
php artisan wms:cleanup-auto-order-logs {--days=30}
```

---

## スケジューラ設定（追加）

```php
protected function schedule(Schedule $schedule): void
{
    // ... Phase 1-5のスケジュール ...

    // 実行リマインダー (11:30)
    $schedule->command('wms:notify-execution-reminder')
        ->dailyAt('11:30');

    // 日次レポート (18:00)
    $schedule->command('wms:generate-auto-order-report')
        ->dailyAt('18:00');

    // ヘルスチェック (毎時)
    $schedule->command('wms:health-check-auto-order')
        ->hourly();

    // ログクリーンアップ (毎日深夜)
    $schedule->command('wms:cleanup-auto-order-logs')
        ->dailyAt('02:00');
}
```

---

## テスト項目

1. [ ] ダッシュボードウィジェット表示
2. [ ] Slack通知送信
3. [ ] メール通知送信
4. [ ] 日次レポート生成
5. [ ] ヘルスチェック実行
6. [ ] 手動再実行
7. [ ] ログクリーンアップ

---

## 本番運用チェックリスト

- [ ] 全テーブルのマイグレーション完了
- [ ] 初期マスタデータ投入
- [ ] カレンダー生成（12ヶ月分）
- [ ] JX接続設定・テスト
- [ ] FTP接続設定・テスト
- [ ] 通知設定
- [ ] スケジューラ設定確認
- [ ] 監視アラート設定
- [ ] 運用マニュアル作成
- [ ] 担当者トレーニング
