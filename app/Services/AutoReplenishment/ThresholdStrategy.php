<?php

namespace App\Services\AutoReplenishment;

final class ThresholdStrategy
{
    public function calculate(array $policy, int $stock, int $incoming, int $claims, int $packingUnit, bool $capAtMaximum = false): array
    {
        $r = (int) ($policy['reorder_point_pieces'] ?? 0);
        $m = (int) ($policy['min_order_pieces'] ?? 0);
        $max = (int) ($policy['max_order_pieces'] ?? 0);
        $u = (int) ($policy['order_multiple_pieces'] ?? 0);
        $target = (int) ($policy['target_stock_pieces'] ?? $r);
        if (min($r, $m, $max, $u, $packingUnit) < 1 || $m > $max || $target < $r) {
            return ['phase' => 'HOLD', 'quantity' => 0, 'reason' => '数量設定・入数が未設定または不整合です。'];
        }
        if ($stock < -2147483648 || $stock > 2147483647 || $incoming < 0 || $claims < 0 || max($incoming, $claims) > 2147483647) {
            return ['phase' => 'HOLD', 'quantity' => 0, 'reason' => '在庫または供給数量に異常があります。'];
        }
        // Negative on-hand is real demand; an incoming quantity alone does not cancel that debt.
        if ($stock >= $r || $stock + $incoming + $claims >= $target) {
            return ['phase' => 'NO_ORDER', 'quantity' => 0, 'reason' => '現在庫・既発注で充足しています。'];
        }
        $a = $u;
        $b = $packingUnit;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        if (intdiv($u, $a) > intdiv(PHP_INT_MAX, $packingUnit)) {
            return ['phase' => 'HOLD', 'quantity' => 0, 'reason' => '単位換算の上限を超えています。'];
        }
        $unit = intdiv($u, $a) * $packingUnit;
        $need = max($m, $target - $stock - $incoming - $claims);
        $multiples = intdiv($need, $unit) + ($need % $unit > 0 ? 1 : 0);
        if ($multiples > intdiv(PHP_INT_MAX, $unit)) {
            return ['phase' => 'HOLD', 'quantity' => 0, 'reason' => '発注数量の上限を超えています。'];
        }
        $quantity = $multiples * $unit;
        if ($quantity > $max && $capAtMaximum) {
            $capped = intdiv($max, $unit) * $unit;
            if ($capped >= $m) {
                return ['phase' => 'READY', 'quantity' => $capped, 'required_quantity' => $quantity, 'capped' => true,
                    'reason' => "必要{$quantity}バラのうち、商品上限内の{$capped}バラを発注します。", 'unit' => $unit,
                    'shortage' => $target - $stock - $incoming - $claims];
            }
        }

        return ['phase' => $quantity > $max ? 'HOLD' : 'READY', 'quantity' => $quantity,
            'reason' => $quantity > $max ? "上限{$max}に対し{$quantity}バラ必要です。" : '安全条件を確認しました。',
            'unit' => $unit, 'shortage' => $target - $stock - $incoming - $claims];
    }
}
