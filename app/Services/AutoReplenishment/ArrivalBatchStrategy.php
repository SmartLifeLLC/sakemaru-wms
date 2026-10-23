<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;

/** Existing arrival forecast shared by fixed batches and opt-in target replenishment. */
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
        $stock = $snapshot['stock'] ?? null;
        $incoming = $snapshot['incoming'] ?? null;
        $claims = $snapshot['claims'] ?? null;
        if (min($r, $min, $max, $unit, $packing, $target) < 1 || $min > $max
            || ! is_int($stock) || ! is_int($incoming) || ! is_int($claims)
            || $stock < -2147483648 || $stock > 2147483647 || min($incoming, $claims) < 0 || max($incoming, $claims) > 2147483647) {
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
        if (($snapshot['shipping_unit']['mode'] ?? '') === 'SHIPMENT') {
            try {
                $candidates = app(ShippingQuantitySet::class)->normalize($snapshot['shipping_unit']['quantities'] ?? []);
            } catch (\Throwable) {
                return $this->result('HOLD', '出荷数量候補を読み込めません。商品設定を確認してください。');
            }
            $eligible = array_values(array_filter($candidates, fn ($q) => $q >= $min && $q <= min($max, $target) && $q % $multiple === 0));
            if (! $eligible || ($policy['strategy_key'] ?? '') !== 'arrival_adaptive_v1' || ($policy['channel'] ?? '') !== 'LOGISTICS') {
                return $this->result('HOLD', '数量条件に合う物流の出荷数量候補がありません。おすすめ設定を確認してください。');
            }
            $batch = $eligible[0];
            $snapshot['eligible_shipping_quantities'] = $eligible;
        }
        if ($batch > $max || $batch > $target) {
            return $this->result('HOLD', '最小発注数を発注単位に合わせると、最大発注数または補充目標を超えます。数量条件を確認してください。');
        }
        // Do not spend future receipts/claims before they arrive. Keep all of them in the position.
        $outgoing = (int) floor((float) $mean * $days);
        // Retain actual negative stock. Forecast demand must not manufacture additional debt.
        $remaining = $stock < 0 ? $stock : max(0, $stock - $outgoing);
        $position = $remaining + $incoming + $claims;
        if (($policy['strategy_key'] ?? '') === 'arrival_adaptive_v1') {
            return $this->adaptive($policy, $snapshot, $remaining, $batch, $multiple, $days, $outgoing);
        }
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

    private function adaptive(array $policy, array $snapshot, int $remaining, int $minimum, int $unit, int $days, int $outgoing): array
    {
        $point = (int) $policy['reorder_point_pieces'];
        $target = (int) ($policy['target_stock_pieces'] ?? $point);
        $maximum = (int) $policy['max_order_pieces'];
        $incoming = $snapshot['incoming'];
        $claims = $snapshot['claims'];
        $position = $remaining + $incoming + $claims;
        $formula = [
            '入荷までの出荷見込：1日平均 '.round((float) $policy['calculation_basis']['mean_daily'], 2)." × {$days}日 ＝ {$outgoing}バラ",
            "補充前見込：在庫残 {$remaining} ＋ 入荷予定 {$incoming} ＋ 発注候補 {$claims} ＝ {$position}バラ",
        ];
        if (isset($policy['calculation_basis']['safety_stock_days'])) {
            $formula[] = 'おすすめ算定時の安全在庫 '.$policy['calculation_basis']['safety_stock_days'].'日分（'.($policy['calculation_basis']['safety_stock_pieces'] ?? 0).'バラ）。現在の補充目標 '.$target.'バラ';
        }
        $stock = $snapshot['stock'];
        if ($stock >= $point) {
            $formula[] = "現在庫 {$stock} ≧ 発注点 {$point} のため発注不要";

            return $this->result('NO_ORDER', '現在庫が発注点以上です。', $formula);
        }
        $shortage = $target - $position;
        $formula[] = "不足数：補充目標 {$target} − 補充前見込 {$position} ＝ {$shortage}バラ";
        if ($shortage <= 0) {
            return $this->result('NO_ORDER', '入荷予定・発注候補を含めて補充目標に達しています。', $formula);
        }
        $normal = $policy['calculation_basis']['batch_quantity_pieces'] ?? null;
        if (is_int($normal) && $normal > 0) {
            $formula[] = "通常の1回量の目安は {$normal}バラです。";
        }
        $limit = min($shortage, $maximum);
        // Round down the bounded shortage: neither the holding target nor a physical unit
        // may be exceeded, including when the item maximum is not an exact multiple.
        $registered = $snapshot['eligible_shipping_quantities'] ?? null;
        $quantity = $registered === null ? intdiv($limit, $unit) * $unit
            : app(ShippingQuantitySet::class)->floor($registered, $limit, $minimum, $unit);
        $formula[] = "数量上限：不足数 {$shortage} と最大発注数 {$maximum} の小さい方 ＝ {$limit}バラ";
        $formula[] = $registered === null ? "単位調整：{$limit} を発注単位 {$unit} に合わせて切下げ ＝ {$quantity}バラ"
            : '出荷数量候補：'.implode('・', $registered)."バラから、上限 {$limit} 以下の最大候補 {$quantity}バラを選択";
        if ($quantity < $minimum) {
            $formula[] = "発注可能数量 {$quantity} ＜ 単位調整後の最小発注数 {$minimum} のため今回は見送り";

            return $this->result('NO_ORDER', '補充目標内で最小発注数・発注単位を満たせないため、今回は発注しません。', $formula);
        }
        // The historical usual batch controls timing, independently of the physical minimum.
        // Respect hand-edited target/max as well: an old larger usual batch must not block forever.
        $normal = is_int($normal) && $normal > 0 ? $normal : $minimum;
        $usual = max($minimum, min(
            (int) (ceil($normal / $unit) * $unit),
            intdiv($maximum, $unit) * $unit,
            intdiv($target, $unit) * $unit,
        ));
        if ($registered !== null) {
            $usualCandidates = array_values(array_filter($registered, fn ($q) => $q >= $normal));
            $usual = $usualCandidates[0] ?? max($registered);
        }
        if (isset($policy['calculation_basis']['safety_stock_days'])) {
            $boundary = (new SafetyStockSettings)->normalOrderBoundary($point, $target, $usual, $outgoing, $incoming, $claims);
            $formula[] = "今回の入荷日・既発注を含む通常量 {$usual}バラの発注目安：現在庫 {$boundary}バラ以下（欠品見込時は小口で判定）";
        }
        if ($quantity < $usual) {
            try {
                $business = CarbonImmutable::createFromFormat('!Y-m-d', $snapshot['business_date']);
                $next = CarbonImmutable::createFromFormat('!Y-m-d', $snapshot['next_arrival_date'] ?? '');
                $nextDays = (int) $business->diffInDays($next, false);
                if ($next->format('Y-m-d') !== ($snapshot['next_arrival_date'] ?? '') || $nextDays < $days || $nextDays > 21) {
                    throw new \RuntimeException;
                }
            } catch (\Throwable) {
                return $this->result('HOLD', '次回の入荷予定日を確認できません。倉庫の実行曜日・配送設定を確認してください。', $formula);
            }
            // Undated/unconfirmed supply must not be assumed available before the next arrival.
            // All such supply remains counted above, so this exception never increases the stock cap.
            $untilNext = (int) ceil((float) $policy['calculation_basis']['mean_daily'] * $nextDays);
            $risk = $stock - $untilNext <= 0 && $nextDays > $days;
            $formula[] = "通常量の判定：今回 {$quantity} ＜ まとめ発注の目安 {$usual}バラ";
            $formula[] = "次回入荷 {$next->format('Y/m/d')} までの出荷見込 {$untilNext}バラ（現在庫 {$stock}バラ・未入荷分の到着は未保証）";
            if (! $risk) {
                return $this->result('NO_ORDER', '通常量までまとめて発注するため、今回は見送ります。', $formula);
            }
            $formula[] = '次回入荷まで現在庫だけでは欠品のおそれがあるため、最小数・上限内の小口発注を行います。';
        }
        $after = $position + $quantity;
        $formula[] = "補充後見込：{$position} ＋ 今回 {$quantity} ＝ {$after}バラ（補充目標 {$target} 以下）";
        if ($after < $target) {
            $formula[] = '今回分は上限・発注単位を優先します。残りの不足 '.($target - $after).'バラは、既発注を含めて次回再判定します。';
        }

        return $this->result('READY', '補充目標までの不足を、最大発注数内で補います。', $formula, $quantity);
    }

    private function result(string $phase, string $reason, array $formula = [], int $quantity = 0): array
    {
        return compact('phase', 'reason', 'quantity', 'formula');
    }
}
