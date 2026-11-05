<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use Tests\TestCase;

class OrderUnitRecommendationTest extends TestCase
{
    private function history(bool $piece, array $receipts = []): array
    {
        return ['scope' => ['warehouse_id' => 1, 'channel' => 'LOGISTICS', 'capacity_case' => 24,
            'packing_unit' => $piece ? 1 : 24, 'order_unit' => ['quantity_type' => $piece ? 'PIECE' : 'CASE',
                'reason' => $piece ? 'バラ発注が可能です。' : 'バラ発注は不可です。']],
            'period' => ['start' => '2026-10-01', 'end' => '2026-10-14'],
            'daily' => array_fill_keys(array_map(fn ($n) => sprintf('2026-10-%02d', $n), range(1, 14)), 5),
            'nominal_lead_days' => 1, 'receipts' => $receipts];
    }

    public function test_case_only_master_overrides_piece_preference_even_without_receipt_history(): void
    {
        $r = app(SettingsRecommendationCalculator::class)->calculate($this->history(false), ['unit_mode' => 'PIECE']);
        self::assertTrue($r['available']);
        self::assertSame('CASE', $r['basis']['unit_mode']);
        self::assertSame(24, $r['values']['order_multiple_pieces']);
        self::assertSame(0, $r['values']['max_order_pieces'] % 24);
    }

    public function test_piece_master_overrides_case_preference_and_full_case_receipts_do_not_prohibit_loose_orders(): void
    {
        $receipts = array_map(fn ($n) => ['warehouse_id' => 1, 'pieces' => $n % 2 ? 48 : 72,
            'date' => sprintf('2026-10-%02d', $n), 'lead_days' => 1], range(1, 6));
        $r = app(SettingsRecommendationCalculator::class)->calculate($this->history(true, $receipts), ['unit_mode' => 'CASE']);
        self::assertTrue($r['available']);
        self::assertSame('PIECE', $r['basis']['unit_mode']);
        self::assertSame(1, $r['values']['order_multiple_pieces']);
    }

    public function test_registered_shipping_quantities_exclude_non_case_candidates_when_piece_is_forbidden(): void
    {
        $r = app(SettingsRecommendationCalculator::class)->calculate($this->history(false),
            ['shipping_quantities' => [12, 24, 48], 'unit_mode' => 'AUTO']);
        self::assertTrue($r['available']);
        self::assertSame(24, $r['values']['min_order_pieces']);
        self::assertSame(24, $r['basis']['batch_quantity_pieces']);
        self::assertSame(24, $r['values']['order_multiple_pieces']);
    }
}
