<?php

namespace App\Services\AutoReplenishment;

/** Pure calculation shared by the item editor and the controlled batch import. */
final class SettingsRecommendationCalculator
{
    public function calculate(array $history, array $options = []): array
    {
        $basis = ['method' => 'linked-receipts-arrival-batch-v3', 'period' => $history['period'],
            'scope' => $history['scope'], 'unit_mode' => $options['unit_mode'] ?? 'AUTO',
            'warnings' => $history['warnings'] ?? [], 'calculated_at' => now()->toIso8601String()];
        $pool = $history['receipts'];
        $own = array_values(array_filter($pool, fn ($r) => $r['warehouse_id'] === $history['scope']['warehouse_id']));
        $basis += ['own_receipt_count' => count($own), 'shared_receipt_count' => count($pool)];
        if (! $pool) {
            return $this->unavailable($basis, '発注候補と紐付く出荷・入荷実績がありません。数量条件を手入力してください。');
        }
        if (count(array_unique(array_column($own, 'date'))) < 5) {
            return $this->unavailable($basis, 'この倉庫の発注連携実績がある日が5日未満です。他倉庫の数量を自動採用せず、数量条件を手入力してください。');
        }
        $packing = (int) $history['scope']['packing_unit'];
        $mode = $basis['unit_mode'];
        if (! in_array($mode, ['AUTO', 'PIECE', 'CASE'], true)) {
            return $this->unavailable($basis, '発注単位の扱いを選び直してください。');
        }
        $unit = $mode === 'PIECE' ? 1 : array_reduce($pool, fn ($u, $r) => $this->gcd($u, $r['pieces']), 0);
        if ($mode === 'CASE') {
            $unit = $this->lcm(max(1, (int) $history['scope']['capacity_case']), max(1, $unit));
        }
        $unit = $this->lcm(max(1, $unit), $packing);
        $eligible = array_values(array_filter($own, fn ($r) => $r['pieces'] % $unit === 0));
        if (count(array_unique(array_column($eligible, 'date'))) < 5) {
            return $this->unavailable($basis, '指定した発注単位に合うこの倉庫の実績が5日分未満です。単位の扱いを確認するか、数量条件を手入力してください。');
        }
        // Nearest-rank P50 is an observed shipment, including when the sample count is even.
        $min = $this->percentile(array_column($eligible, 'pieces'), .5);
        $leads = array_values(array_filter(array_column($own, 'lead_days'), fn ($v) => $v !== null && $v >= 0 && $v <= 14));
        $observedLead = count($leads) >= 5 ? $this->percentile($leads, .95) : null;
        $lead = max(1, $history['nominal_lead_days'], $observedLead ?? 1);
        if ($lead > 14) {
            return $this->unavailable($basis, '入荷までの日数が14日を超えています。配送設定を確認してください。');
        }
        $protection = $lead + 1;
        $end = \Carbon\CarbonImmutable::parse($history['period']['end']);
        $recentStart = $end->subDays(27)->toDateString();
        $daily = array_filter($history['daily'], fn ($date) => $date >= $recentStart, ARRAY_FILTER_USE_KEY);
        $values = array_values(array_filter($daily, fn ($v) => $v !== null));
        if (count($values) < 14 || count(array_filter($values, fn ($v) => $v > 0)) < 3) {
            $basis['warnings'][] = '直近28日の観測日が14日未満、または出荷のある日が3日未満のため、対象期間全体の観測値で算定しました。欠損日は0で補完していません。';
            $daily = $history['daily'];
            $values = array_values(array_filter($daily, fn ($v) => $v !== null));
        }
        $windows = [];
        $days = array_values($daily);
        for ($i = 0; $i <= count($days) - $protection; $i++) {
            $window = array_slice($days, $i, $protection);
            if (! in_array(null, $window, true)) {
                $windows[] = array_sum($window);
            }
        }
        $basis += ['demand_start' => array_key_first($daily), 'observed_days' => count($values), 'positive_demand_days' => count(array_filter($values, fn ($v) => $v > 0)),
            'unknown_days' => count($daily) - count($values), 'window_count' => count($windows)];
        if (count($values) < 14 || count($windows) < 3 || count(array_filter($values, fn ($v) => $v > 0)) < 3) {
            return $this->unavailable($basis, '需要の観測日数または連続実績が不足しています。欠損日を0として算定せず、数量条件は手入力してください。');
        }
        $mean = array_sum($values) / count($values);
        $p95 = $this->percentile($windows, .95);
        $point = $this->roundUp((int) ceil(max($p95, $mean * $protection)), $unit);
        $receiptP95 = $this->percentile(array_column($eligible, 'pieces'), .95);
        // Separate the lead-time stock threshold from the size of one replenishment batch.
        // A fixed item maximum also prevents GCD-derived intermediate pallet quantities.
        $cap = $min;
        $dailyP95 = $this->percentile($values, .95);
        $buffer = (int) ceil(max($mean, $dailyP95));
        $target = $this->roundUp($min + $buffer, $unit);
        if (max($point, $target, $cap, $min, $unit) > 2147483647) {
            return $this->unavailable($basis, '算定数量が保存できる上限を超えています。管理者に確認してください。');
        }
        $basis += ['nominal_lead_days' => $history['nominal_lead_days'], 'observed_lead_p95' => $observedLead,
            'protection_days' => $protection, 'demand_start' => array_key_first($daily), 'observed_days' => count($values),
            'unknown_days' => count($daily) - count($values), 'mean_daily' => round($mean, 4),
            'window_count' => count($windows), 'demand_p95' => $p95, 'receipt_p95' => $receiptP95,
            'batch_quantity_pieces' => $min, 'batch_percentile' => .5,
            'daily_demand_p95' => $dailyP95, 'arrival_buffer_pieces' => $buffer,
            'batch_rule' => 'observed-nearest-rank-p50-fixed-batch',
            'receipt_basis' => 'この倉庫の発注連携実績',
            'samples' => array_slice($eligible, 0, 3)];
        $basis['steps'] = [
            '発注連携実績：この倉庫 '.count($own).'回 / 全倉庫 '.count($pool).'回',
            "過去の1回代表量：この倉庫の実績P50（実績にある数量から選ぶ50%点）＝ {$min}バラ",
            "最小発注数・最大発注数：代表量に合わせて各{$min}バラ / 発注単位：{$unit}バラ（出力単位 {$packing} を含む）",
            "需要保護期間：入荷まで {$lead}日 ＋ 判断間隔1日 ＝ {$protection}日",
            "発注点：大きい方（連続{$protection}日需要P95 {$p95}、日平均 ".round($mean, 2)." × {$protection}）を{$unit}単位に切上げ ＝ {$point}",
            "補充目標：1回量 {$min} ＋ 出荷が多い日の1日分 {$buffer} を単位に合わせる ＝ {$target}バラ",
            '入荷までの出荷見込を差し引き、1回分を足しても補充目標を超えない場合に発注します。',
        ];
        $values = ['reorder_point_pieces' => $point, 'target_stock_pieces' => $target, 'min_order_pieces' => $min,
            'order_multiple_pieces' => $unit, 'max_order_pieces' => $cap,
            'strategy_key' => 'arrival_batch_v1'];
        $basis['suggested_values'] = $values;

        return ['available' => true, 'values' => $values, 'basis' => $basis];
    }

    private function unavailable(array $basis, string $reason): array
    {
        return ['available' => false, 'values' => [], 'basis' => $basis + ['reason' => $reason]];
    }

    private function percentile(array $values, float $percentile): int
    {
        sort($values, SORT_NUMERIC);

        return (int) $values[max(0, (int) ceil(count($values) * $percentile) - 1)];
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }

    private function lcm(int $a, int $b): int
    {
        throw_unless($a > 0 && $b > 0 && intdiv($a, max(1, $this->gcd($a, $b))) <= intdiv(PHP_INT_MAX, $b), \RuntimeException::class, '単位換算の上限を超えています。');

        return intdiv($a, $this->gcd($a, $b)) * $b;
    }

    private function roundUp(int $value, int $unit): int
    {
        return max(1, intdiv($value, $unit) + ($value % $unit > 0 ? 1 : 0)) * $unit;
    }
}
