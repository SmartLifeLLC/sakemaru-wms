<?php

namespace App\Services\AutoReplenishment;

/** Customer strategies cannot bypass the shared physical quantity limits. */
final class ProposalSafety
{
    public function validate(array $policy, array $proposal, int $packingUnit, array $shippingUnit = []): void
    {
        throw_unless(in_array($proposal['phase'] ?? '', ['READY', 'NO_ORDER', 'HOLD'], true)
            && is_string($proposal['reason'] ?? null) && mb_strlen($proposal['reason']) <= 500
            && is_int($proposal['quantity'] ?? null) && $proposal['quantity'] >= 0
            && (($proposal['phase'] ?? '') !== 'NO_ORDER' || $proposal['quantity'] === 0),
            \RuntimeException::class, '計算ロジックの判定結果が不正です。');
        if (($proposal['phase'] ?? '') !== 'READY') {
            return;
        }
        $quantity = $proposal['quantity'] ?? null;
        if (($shippingUnit['mode'] ?? '') === 'SHIPMENT') {
            throw_unless(in_array($quantity, app(ShippingQuantitySet::class)->normalize($shippingUnit['quantities'] ?? []), true),
                \RuntimeException::class, '提案数量が登録済みの出荷数量候補にありません。');
        }
        $min = (int) ($policy['min_order_pieces'] ?? 0);
        $max = (int) ($policy['max_order_pieces'] ?? 0);
        $multiple = (int) ($policy['order_multiple_pieces'] ?? 0);
        throw_unless(is_int($quantity) && $quantity > 0 && $min > 0 && $max >= $min && $multiple > 0 && $packingUnit > 0
            && $quantity >= $min && $quantity <= $max && $quantity % $multiple === 0 && $quantity % $packingUnit === 0,
            \RuntimeException::class, '計算ロジックの提案数量が共通の最小数・単位・上限を満たしていません。');
    }
}
