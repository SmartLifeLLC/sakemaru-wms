<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;

/** One batch fits the target after consuming on-hand stock until the existing arrival date. */
final class ArrivalBatchStrategy
{
    public function calculate(array $policy, array $snapshot): array
    {
        $r = (int) ($policy['reorder_point_pieces'] ?? 0);
        $min = (int) ($policy['min_order_pieces'] ?? 0);
        $max = (int) ($policy['max_order_pieces'] ?? 0);
        $unit = (int) ($policy['order_multiple_pieces'] ?? 0);
        $packing = (int) ($snapshot['packing_unit'] ?? 0);
        $target = (int) ($policy['target_stock_pieces'] ?? $r);
        $stock = $snapshot['stock'] ?? -1;
        $incoming = $snapshot['incoming'] ?? -1;
        $claims = $snapshot['claims'] ?? -1;
        if (min($r, $min, $max, $unit, $packing, $target) < 1 || $min > $max
            || min($stock, $incoming, $claims) < 0) {
            return $this->result('HOLD', '在庫・数量条件を確認してください。');
        }
        $mean = $policy['calculation_basis']['mean_daily'] ?? null;
        if (! is_numeric($mean) || ! is_finite((float) $mean) || $mean <= 0 || $mean > 2147483647) {
            return $this->result('HOLD', '出荷見込が未設定です。「おすすめ設定」で再計算してください。');
        }
        try {
            $business = CarbonImmutable::createFromFormat('!Y-m-d', $snapshot['business_date'] ?? '');
            $arrival = CarbonImmutable::createFromFormat('!Y-m-d', $snapshot['arrival_date'] ?? '');
            $days = (int) $business->diffInDays($arrival, false);
            if ($days < 0 || $days > 14 || $business->format('Y-m-d') !== $snapshot['business_date'] || $arrival->format('Y-m-d') !== $snapshot['arrival_date']) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            return $this->result('HOLD', '入荷予定日を確認できません。配送設定を確認してください。');
        }
        $a = $unit;
        $b = $packing;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        if (intdiv($unit, $a) > intdiv(PHP_INT_MAX, $packing)) {
            return $this->result('HOLD', '発注単位が大きすぎます。数量条件を確認してください。');
        }
        $multiple = intdiv($unit, $a) * $packing;
        $batch = (intdiv($min, $multiple) + ($min % $multiple > 0 ? 1 : 0)) * $multiple;
        if ($batch > $max || $batch > $target) {
            return $this->result('HOLD', '1回の発注量が最大発注数または補充目標を超えています。数量条件を確認してください。');
        }
        // Do not spend future receipts/claims before they arrive. Keep all of them in the position.
        $outgoing = (int) floor((float) $mean * $days);
        $remaining = max(0, $stock - $outgoing);
        $position = $remaining + $incoming + $claims;
        $after = $position + $batch;
        $formula = [
            '入荷までの出荷見込：1日平均 '.round((float) $mean, 2)." × {$days}日 ＝ {$outgoing}バラ",
            "補充後見込：在庫残 {$remaining} ＋ 入荷予定 {$incoming} ＋ 発注候補 {$claims} ＋ 今回 {$batch} ＝ {$after}バラ",
        ];
        if ($stock >= $r) {
            $formula[] = "現在庫 {$stock} ≧ 発注点 {$r} のため発注不要";

            return $this->result('NO_ORDER', '現在庫が発注点以上です。', $formula);
        }
        if ($after > $target) {
            $formula[] = "補充後見込 {$after} ＞ 補充目標 {$target} のため今回は見送り";

            return $this->result('NO_ORDER', '補充目標を超えるため、今回は発注しません。', $formula);
        }
        $formula[] = "補充後見込 {$after} ≦ 補充目標 {$target} → {$batch}バラ発注";

        return $this->result('READY', '補充目標内の1回分を発注します。', $formula, $batch);
    }

    private function result(string $phase, string $reason, array $formula = [], int $quantity = 0): array
    {
        return compact('phase', 'reason', 'quantity', 'formula');
    }
}
