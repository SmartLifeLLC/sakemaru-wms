<?php

namespace App\Services\AutoReplenishment;

/** Preview, preparation and locked confirmation share the exact same strategy dispatch. */
final class StrategyCalculator
{
    public function calculate(array $policy, array $snapshot): array
    {
        $class = config('replenishment.strategies.'.($policy['strategy_key'] ?? ''));
        throw_unless(is_string($class) && class_exists($class), \RuntimeException::class, '計算ロジックが未設定です。管理者に商品設定を確認してください。');
        $strategy = app($class);

        throw_if(($snapshot['shipping_unit']['mode'] ?? '') === 'SHIPMENT' && ! $strategy instanceof ArrivalBatchStrategy,
            \RuntimeException::class, '出荷数量候補は入荷時の補充目標を確認する計算方式で利用してください。');

        return $strategy instanceof ArrivalBatchStrategy
            ? $strategy->calculate($policy, $snapshot)
            : $strategy->calculate($policy, $snapshot['stock'], $snapshot['incoming'], $snapshot['claims'], $snapshot['packing_unit']);
    }
}
