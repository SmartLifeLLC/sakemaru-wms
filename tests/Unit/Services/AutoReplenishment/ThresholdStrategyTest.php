<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\ThresholdStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThresholdStrategyTest extends TestCase
{
    #[DataProvider('boundaries')]
    public function test_quantity_boundaries(int $stock, int $incoming, int $claims, int $min, int $step, int $max, int $packing, string $phase, int $quantity): void
    {
        $result = (new ThresholdStrategy)->calculate([
            'reorder_point_pieces' => 100, 'min_order_pieces' => $min,
            'order_multiple_pieces' => $step, 'max_order_pieces' => $max,
        ], $stock, $incoming, $claims, $packing);
        self::assertSame($phase, $result['phase']);
        self::assertSame($quantity, $result['quantity']);
    }

    public static function boundaries(): array
    {
        return [
            'at point' => [100, 0, 0, 12, 6, 120, 1, 'NO_ORDER', 0],
            'minimum' => [95, 0, 0, 12, 6, 120, 1, 'READY', 12],
            'round after incoming' => [50, 24, 0, 12, 6, 120, 1, 'READY', 30],
            'healthy zero stock' => [0, 0, 0, 12, 6, 120, 1, 'READY', 102],
            'existing supply' => [0, 120, 0, 12, 6, 120, 1, 'NO_ORDER', 0],
            'other candidate' => [50, 0, 60, 12, 6, 120, 1, 'NO_ORDER', 0],
            'cap cannot clip' => [20, 0, 0, 12, 6, 60, 1, 'HOLD', 84],
            'lcm rounding' => [87, 0, 0, 1, 5, 50, 12, 'HOLD', 60],
            'negative stock' => [-1, 0, 0, 12, 6, 120, 1, 'READY', 102],
            'negative with insufficient incoming' => [-50, 100, 0, 12, 6, 120, 1, 'READY', 54],
            'negative with sufficient incoming' => [-50, 150, 0, 12, 6, 120, 1, 'NO_ORDER', 0],
            'negative with insufficient claims' => [-50, 0, 100, 12, 6, 120, 1, 'READY', 54],
            'negative supply is still invalid' => [-5, -1, 0, 12, 6, 120, 1, 'HOLD', 0],
            'out of range' => [PHP_INT_MIN, 0, 0, 12, 6, 120, 1, 'HOLD', 0],
            'unset upper bound' => [50, 0, 0, 1, 1, 0, 1, 'HOLD', 0],
            'unset unit' => [50, 0, 0, 1, 0, 120, 1, 'HOLD', 0],
            'min over max' => [50, 0, 0, 121, 1, 120, 1, 'HOLD', 0],
        ];
    }

    public function test_safe_quantities_obey_all_constraints_for_many_combinations(): void
    {
        foreach ([-100, -6, -1, 0, 1, 20, 50, 95, 99, 100] as $stock) {
            foreach ([1, 3, 6, 12, 24] as $step) {
                foreach ([1, 6, 12] as $pack) {
                    foreach ([1, 10, 12, 50] as $min) {
                        $result = (new ThresholdStrategy)->calculate(['reorder_point_pieces' => 100, 'min_order_pieces' => $min, 'order_multiple_pieces' => $step, 'max_order_pieces' => 120], $stock, 0, 0, $pack);
                        if ($result['phase'] === 'READY') {
                            self::assertGreaterThanOrEqual($min, $result['quantity']);
                            self::assertLessThanOrEqual(120, $result['quantity']);
                            self::assertSame(0, $result['quantity'] % $step);
                            self::assertSame(0, $result['quantity'] % $pack);
                            self::assertGreaterThanOrEqual(100, $stock + $result['quantity']);
                        }
                    }
                }
            }
        }
    }
}
