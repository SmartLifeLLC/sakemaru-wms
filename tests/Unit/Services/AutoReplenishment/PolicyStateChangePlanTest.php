<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\PolicyStateChangePlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PolicyStateChangePlanTest extends TestCase
{
    #[DataProvider('states')]
    public function test_only_products_with_a_different_state_are_changed(array $states, bool $enabled, int $changes): void
    {
        $rows = [];
        foreach ($states as $index => $state) {
            $rows[] = ['id' => $index + 1, 'version' => 3, 'is_enabled' => $state, 'item_code' => 'item-'.$index, 'item_name' => '商品'.$index];
        }
        $versions = array_fill_keys(array_column($rows, 'id'), 3);
        $plan = (new PolicyStateChangePlan)->build($versions, $rows, $enabled);
        self::assertSame($changes, $plan['change_count']);
        self::assertSame(count($rows) - $changes, $plan['unchanged_count']);
        self::assertSame($enabled, $plan['enabled']);
        self::assertSame($versions, $plan['versions']);
        self::assertSame($states, array_column($plan['rows'], 'enabled'));
    }

    public static function states(): array
    {
        return [
            'OFF products to ON' => [[false, false, false], true, 3],
            'ON products to OFF' => [[true, true, true], false, 3],
            'already OFF' => [[false, false], false, 0],
            'already ON' => [[true, true], true, 0],
            'mixed to ON' => [[false, true, false], true, 2],
            'mixed to OFF' => [[false, true, false], false, 1],
        ];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_or_stale_selections_are_rejected(array $versions, array $rows): void
    {
        $this->expectException(\RuntimeException::class);
        (new PolicyStateChangePlan)->build($versions, $rows, true);
    }

    public static function invalidSelections(): array
    {
        return [
            'none' => [[], []],
            'too many' => [array_fill(1, 101, 3), []],
            'deleted or foreign product' => [[1 => 3, 2 => 3], [['id' => 1, 'version' => 3, 'is_enabled' => false]]],
            'changed version' => [[1 => 2], [['id' => 1, 'version' => 3, 'is_enabled' => false]]],
            'different product' => [[1 => 3], [['id' => 2, 'version' => 3, 'is_enabled' => false]]],
        ];
    }
}
