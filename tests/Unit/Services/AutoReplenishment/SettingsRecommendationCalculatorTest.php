<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SettingsRecommendationCalculatorTest extends TestCase
{
    private function history(): array
    {
        $daily = [];
        for ($d = CarbonImmutable::parse('2026-09-01'); $d->toDateString() <= '2026-10-17'; $d = $d->addDay()) {
            $daily[$d->toDateString()] = $d->month === 9 && $d->day < 20 ? 1000 : 300;
        }

        return ['scope' => ['warehouse_id' => 1, 'packing_unit' => 1, 'capacity_case' => 24],
            'period' => ['start' => '2026-09-01', 'end' => '2026-10-17'], 'daily' => $daily, 'nominal_lead_days' => 3,
            'receipts' => array_map(fn ($i) => ['warehouse_id' => 1, 'pieces' => $i === 0 ? 576 : 1728,
                'date' => CarbonImmutable::parse('2026-09-01')->addDays($i)->toDateString(), 'lead_days' => 1, 'source' => ['id' => $i]], range(0, 12))
                + [20 => ['warehouse_id' => 2, 'pieces' => 384, 'date' => '2026-09-01', 'lead_days' => 1, 'source' => ['id' => 20]]]];
    }

    public function test_recent_demand_and_actual_linked_lots_are_separate_inputs(): void
    {
        $r = app(SettingsRecommendationCalculator::class)->calculate($this->history());
        self::assertTrue($r['available']);
        self::assertSame('linked_receipts', $r['basis']['basis_type']);
        // Minimum, most frequent batch, and maximum are separate observed quantities.
        self::assertSame(192, $r['values']['order_multiple_pieces']);
        self::assertSame(576, $r['values']['min_order_pieces']);
        self::assertSame(1728, $r['basis']['batch_quantity_pieces']);
        self::assertSame(1200, $r['values']['reorder_point_pieces']);
        self::assertSame(1728, $r['values']['max_order_pieces']);
        self::assertSame(2028, $r['values']['target_stock_pieces']);
        self::assertSame('arrival_adaptive_v1', $r['values']['strategy_key']);
        self::assertSame(28, $r['basis']['observed_days']);
        self::assertSame(4, $r['basis']['protection_days']);
    }

    public function test_missing_days_are_never_filled_with_zero(): void
    {
        $h = $this->history();
        foreach ($h['daily'] as $day => $v) {
            $h['daily'][$day] = (int) substr($day, -2) % 2 ? null : $v;
        }
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertFalse($r['available']);
        self::assertStringContainsString('連続実績が不足', $r['basis']['reason']);
    }

    public function test_missing_receipts_uses_store_sales_and_explicit_review_days(): void
    {
        $h = $this->history();
        $h['receipts'] = [];
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame('sales', $r['basis']['basis_type']);
        self::assertSame(7, $r['basis']['review_days']);
        self::assertSame(1, $r['values']['order_multiple_pieces']);
        self::assertSame(2100, $r['values']['min_order_pieces']);
        self::assertSame(2100, $r['values']['max_order_pieces']);
        self::assertSame(2400, $r['values']['target_stock_pieces']);
        self::assertSame(1200, $r['values']['reorder_point_pieces']);
        self::assertSame(7.0, $r['basis']['batch_coverage_days']);
        self::assertSame([], $r['basis']['samples']);
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['review_days' => '3']);
        self::assertSame(900, $r['values']['min_order_pieces']);
        self::assertStringContainsString('3日分', implode('', $r['basis']['steps']));
    }

    public function test_sales_fallback_still_requires_observed_demand_and_reports_the_missing_input(): void
    {
        $h = $this->history();
        $h['receipts'] = [];
        $h['daily'] = ['2026-10-01' => 24, '2026-10-02' => null, '2026-10-03' => 0, '2026-10-04' => 12];
        foreach (['EOS', 'LOGISTICS'] as $channel) {
            $h['scope']['channel'] = $channel;
            $r = app(SettingsRecommendationCalculator::class)->calculate($h);
            self::assertFalse($r['available']);
            self::assertSame([], $r['values']);
            self::assertSame(2, $r['basis']['demand_positive_days']);
            self::assertSame(36, $r['basis']['demand_total_pieces']);
            self::assertSame('sales', $r['basis']['basis_type']);
            self::assertStringContainsString('需要の観測日数', $r['basis']['reason']);
        }
    }

    public function test_receipt_day_count_distinguishes_repeated_receipts_from_sufficient_history(): void
    {
        $h = $this->history();
        $h['receipts'] = array_fill(0, 6, $h['receipts'][0]);
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame(6, $r['basis']['own_receipt_count']);
        self::assertSame(1, $r['basis']['own_receipt_days']);
        self::assertSame('sales', $r['basis']['basis_type']);
        self::assertSame(2112, $r['values']['min_order_pieces']);
    }

    public function test_sales_unit_selection_preserves_piece_preference_and_required_output_pack(): void
    {
        $h = $this->history();
        $h['receipts'] = [];
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        self::assertSame(1, $r['values']['order_multiple_pieces']);
        self::assertSame(2100, $r['values']['min_order_pieces']);
        $h['scope']['packing_unit'] = 24;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        self::assertSame(24, $r['values']['order_multiple_pieces']);
        self::assertSame(2112, $r['values']['min_order_pieces']);
    }

    public function test_invalid_review_days_do_not_generate_recommendations(): void
    {
        $h = $this->history();
        $h['receipts'] = [];
        foreach ([0, -1, 29, '1.5', '', 'bad'] as $days) {
            $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['review_days' => $days]);
            self::assertFalse($r['available']);
            self::assertSame([], $r['values']);
            self::assertStringContainsString('1〜28日', $r['basis']['reason']);
        }
    }

    public function test_piece_preference_preserves_eos_output_pack_and_uses_typical_batch(): void
    {
        $h = $this->history();
        $h['scope']['packing_unit'] = 24;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        self::assertSame(24, $r['values']['order_multiple_pieces']);
        self::assertSame(576, $r['values']['min_order_pieces']);
        $h['scope']['packing_unit'] = 1;
        self::assertSame(1, app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE'])['values']['order_multiple_pieces']);
    }

    public function test_sparse_recent_sales_use_longer_observed_history_with_an_explicit_warning(): void
    {
        $h = $this->history();
        foreach ($h['daily'] as $day => $value) {
            $h['daily'][$day] = in_array($day, ['2026-09-03', '2026-09-10', '2026-09-17', '2026-10-03', '2026-10-10']) ? 24 : 0;
        }
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame('2026-09-01', $r['basis']['demand_start']);
        self::assertSame(5, $r['basis']['positive_demand_days']);
        self::assertStringContainsString('出荷のある日が3日未満', implode('', $r['basis']['warnings']));
        $h['daily'] = array_map(fn () => 0, $h['daily']);
        self::assertFalse(app(SettingsRecommendationCalculator::class)->calculate($h)['available']);
    }

    public function test_other_warehouse_is_not_used_to_invent_a_store_batch_and_long_lead_is_rejected(): void
    {
        $h = $this->history();
        $h['scope']['warehouse_id'] = 99;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame(0, $r['basis']['own_receipt_count']);
        self::assertSame('sales', $r['basis']['basis_type']);
        self::assertSame(2100, $r['values']['min_order_pieces']);
        $h['scope']['warehouse_id'] = 1;
        $h['nominal_lead_days'] = 15;
        self::assertFalse(app(SettingsRecommendationCalculator::class)->calculate($h)['available']);
    }

    public function test_tied_most_frequent_quantities_use_the_smaller_observed_batch(): void
    {
        $h = $this->history();
        $h['receipts'] = array_slice($h['receipts'], 0, 6);
        foreach ([576, 576, 768, 960, 1728, 1728] as $i => $qty) {
            $h['receipts'][$i]['pieces'] = $qty;
        }
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame(576, $r['values']['min_order_pieces']);
        self::assertSame(1728, $r['values']['max_order_pieces']);
        self::assertSame(576, $r['basis']['batch_quantity_pieces']);
        self::assertSame(2, $r['basis']['batch_frequency']);
        self::assertNull($r['basis']['batch_percentile']);
    }

    public function test_repeated_receipts_on_one_day_do_not_establish_a_typical_store_batch(): void
    {
        $h = $this->history();
        foreach ($h['receipts'] as &$receipt) {
            $receipt['date'] = '2026-10-01';
        }
        unset($receipt);
        self::assertSame('sales', app(SettingsRecommendationCalculator::class)->calculate($h)['basis']['basis_type']);
    }

    public function test_case_preference_uses_sales_when_compatible_own_history_is_insufficient(): void
    {
        $h = $this->history();
        $h['receipts'] = array_slice($h['receipts'], 0, 6);
        foreach ([1, 2, 3, 4, 5, 24] as $i => $qty) {
            $h['receipts'][$i]['pieces'] = $qty;
        }
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'CASE']);
        self::assertTrue($r['available']);
        self::assertSame('sales', $r['basis']['basis_type']);
        self::assertSame(24, $r['values']['order_multiple_pieces']);
        self::assertStringContainsString('単位に合う', $r['basis']['fallback_reason']);
    }

    public function test_sales_recommendation_runs_through_existing_strategy_and_incoming_prevents_second_batch(): void
    {
        foreach (['EOS', 'LOGISTICS'] as $channel) {
            $h = $this->history();
            $h['scope']['channel'] = $channel;
            $h['scope']['packing_unit'] = $channel === 'EOS' ? 24 : 1;
            $h['receipts'] = [];
            $r = app(SettingsRecommendationCalculator::class)->calculate($h);
            $p = $r['values'] + ['calculation_basis' => $r['basis']];
            $s = ['stock' => 0, 'incoming' => 0, 'claims' => 0, 'packing_unit' => $channel === 'EOS' ? 24 : 1,
                'business_date' => '2026-10-18', 'arrival_date' => '2026-10-19'];
            $calculator = app(\App\Services\AutoReplenishment\StrategyCalculator::class);
            $proposal = $calculator->calculate($p, $s);
            self::assertSame('READY', $proposal['phase']);
            self::assertSame($channel === 'EOS' ? 2112 : 2100, $proposal['quantity']);
            app(\App\Services\AutoReplenishment\ProposalSafety::class)->validate($p, $proposal, $s['packing_unit']);
            $s['incoming'] = $proposal['quantity'];
            self::assertSame('NO_ORDER', $calculator->calculate($p, $s)['phase']);
            $s['incoming'] = 0;
            $s['claims'] = $proposal['quantity'];
            self::assertSame('NO_ORDER', $calculator->calculate($p, $s)['phase']);
        }
    }

    public function test_legacy_fixed_capped_policy_retains_its_existing_behavior(): void
    {
        $p = app(SettingsRecommendationCalculator::class)->calculate($this->history())['values'];
        $p['min_order_pieces'] = 1728;
        $p['reorder_point_pieces'] = 4000;
        $p['target_stock_pieces'] = 4000;
        $strategy = app(\App\Services\AutoReplenishment\CappedThresholdStrategy::class);
        foreach ([3999, 3000, 0] as $stock) {
            $r = $strategy->calculate($p, $stock, 0, 0, 1);
            self::assertSame('READY', $r['phase']);
            self::assertSame(1728, $r['quantity']);
        }
        self::assertSame('NO_ORDER', $strategy->calculate($p, 3000, 1728, 0, 1)['phase']);
        self::assertSame('NO_ORDER', $strategy->calculate($p, 3000, 0, 1728, 1)['phase']);
        self::assertSame('READY', $strategy->calculate($p, -1, 0, 0, 1)['phase']);
    }

    public function test_one_large_historical_order_does_not_raise_the_typical_batch(): void
    {
        $h = $this->history();
        $h['receipts'][0]['pieces'] = 10000000;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertSame(1728, $r['basis']['batch_quantity_pieces']);
        self::assertSame(10000000, $r['values']['max_order_pieces']);
        self::assertSame(1, $r['basis']['maximum_frequency']);
        $proposal = app(\App\Services\AutoReplenishment\StrategyCalculator::class)->calculate(
            $r['values'] + ['calculation_basis' => $r['basis']], ['stock' => 0, 'incoming' => 0, 'claims' => 0,
                'packing_unit' => 1, 'business_date' => '2026-10-18', 'arrival_date' => '2026-10-19']);
        self::assertSame('READY', $proposal['phase']);
        self::assertSame(1984, $proposal['quantity']);
    }

    public function test_long_lead_does_not_inflate_the_arrival_holding_target(): void
    {
        $h = $this->history();
        $h['nominal_lead_days'] = 8;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertSame(2028, $r['values']['target_stock_pieces']);
        self::assertLessThan($r['values']['reorder_point_pieces'], $r['values']['target_stock_pieces']);
    }

    public function test_registered_pallet_is_retained_and_does_not_round_inventory_target_to_an_extra_pallet(): void
    {
        $h = $this->history();
        $h['scope']['channel'] = 'LOGISTICS';
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PALLET', 'pallet_pieces' => 1728]);
        self::assertTrue($r['available']);
        self::assertSame(1728, $r['values']['order_multiple_pieces']);
        self::assertSame(1728, $r['values']['min_order_pieces']);
        self::assertSame(1200, $r['values']['reorder_point_pieces']);
        self::assertSame(2028, $r['values']['target_stock_pieces']);
        $h['receipts'] = [];
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PALLET', 'pallet_pieces' => 1728]);
        self::assertSame('sales', $r['basis']['basis_type']);
        self::assertSame(3456, $r['values']['min_order_pieces']);
        self::assertSame(3756, $r['values']['target_stock_pieces']);
        self::assertFalse(app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PALLET'])['available']);
        $h['scope']['channel'] = 'EOS';
        self::assertFalse(app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PALLET', 'pallet_pieces' => 1728])['available']);
    }

    public function test_kirishima_normal_six_and_historical_maximum_twelve_are_independent(): void
    {
        $h = $this->history();
        $h['scope']['capacity_case'] = 6;
        $h['daily'] = array_map(fn () => 1, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 6);
        foreach ([6, 12, 6, 12, 6, 6] as $i => $pieces) {
            $h['receipts'][$i]['pieces'] = $pieces;
        }
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        self::assertSame(6, $r['basis']['batch_quantity_pieces']);
        self::assertSame(4, $r['basis']['batch_frequency']);
        self::assertSame(6, $r['values']['min_order_pieces']);
        self::assertSame(12, $r['values']['max_order_pieces']);
        self::assertSame([['pieces' => 6, 'count' => 4], ['pieces' => 12, 'count' => 2]], $r['basis']['batch_quantity_counts']);
    }

    public function test_minimum_is_not_the_most_frequent_quantity_and_pallet_history_filters_incompatible_lots(): void
    {
        $h = $this->history();
        $h['scope']['channel'] = 'LOGISTICS';
        $h['daily'] = array_map(fn () => 8, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 7);
        foreach ([3, 24, 24, 24, 48, 72, 96] as $i => $pieces) {
            $h['receipts'][$i]['pieces'] = $pieces;
        }
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PALLET', 'pallet_pieces' => 24]);
        self::assertSame('linked_receipts', $r['basis']['basis_type']);
        self::assertSame(24, $r['values']['min_order_pieces']);
        self::assertSame(24, $r['basis']['batch_quantity_pieces']);
        self::assertSame(96, $r['values']['max_order_pieces']);
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        self::assertSame(3, $r['values']['min_order_pieces']);
        self::assertSame(24, $r['basis']['batch_quantity_pieces']);
    }

    public function test_low_turnover_loose_receipt_keeps_piece_unit_and_unrounded_inventory_thresholds(): void
    {
        $h = $this->history();
        $h['scope']['capacity_case'] = 4;
        $h['receipts'] = [['warehouse_id' => 1, 'pieces' => 1, 'date' => '2026-10-01', 'lead_days' => 1, 'quantity_types' => ['PIECE']]];
        $h['daily'] = array_map(fn () => 0, $h['daily']);
        foreach (['2026-09-20', '2026-09-30', '2026-10-10'] as $date) {
            $h['daily'][$date] = 1;
        }
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame('sales', $r['basis']['basis_type']);
        self::assertSame(1, $r['values']['order_multiple_pieces']);
        self::assertSame(1, $r['values']['min_order_pieces']);
        self::assertSame(1, $r['values']['max_order_pieces']);
        self::assertSame(1, $r['values']['reorder_point_pieces']);
        self::assertSame(2, $r['values']['target_stock_pieces']);
        self::assertSame(['PIECE'], $r['basis']['own_quantity_types']);
        self::assertStringContainsString('暫定', $r['basis']['unit_basis']);
        $h['receipts'][0]['pieces'] = 4;
        // A PIECE-typed whole case does not prove a single-bottle supply unit.
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertSame(4, $r['values']['order_multiple_pieces']);
        self::assertSame(4, $r['values']['min_order_pieces']);
        self::assertSame(1, $r['values']['reorder_point_pieces']);
        self::assertSame(5, $r['values']['target_stock_pieces']);
    }

    public function test_sparse_case_only_history_uses_case_not_the_large_observed_gcd(): void
    {
        $h = $this->history();
        $h['receipts'] = [['warehouse_id' => 1, 'pieces' => 1728, 'date' => '2026-10-01', 'lead_days' => 1, 'quantity_types' => ['CASE']]];
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame('sales', $r['basis']['basis_type']);
        self::assertSame(24, $r['values']['order_multiple_pieces']);
        self::assertStringContainsString('パレットとは推定していません', $r['basis']['unit_basis']);
        self::assertSame(1200, $r['values']['reorder_point_pieces']);
        self::assertSame(2412, $r['values']['target_stock_pieces']);
    }

    public function test_no_own_history_uses_only_output_unit_and_explains_that_it_is_provisional(): void
    {
        $h = $this->history();
        $h['scope']['warehouse_id'] = 99;
        $h['scope']['packing_unit'] = 6;
        $h['scope']['capacity_case'] = 24;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame(6, $r['values']['order_multiple_pieces']);
        self::assertSame(2100, $r['values']['min_order_pieces']);
        self::assertSame([], $r['basis']['own_quantity_types']);
        self::assertStringContainsString('発注単位は実績で未確認', $r['basis']['unit_basis']);
    }

    public function test_weekly_schedule_covers_the_gap_and_does_not_increase_historical_maximum(): void
    {
        $h = $this->history();
        $h['daily'] = array_map(fn () => 2, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 6);
        foreach ([6, 6, 6, 6, 12, 24] as $i => $pieces) {
            $h['receipts'][$i]['pieces'] = $pieces;
        }
        $h['review_interval_days'] = 7;
        $h['review_weekdays'] = [1];
        $h['review_schedule_fingerprint'] = 'schedule-test';
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        self::assertTrue($r['available']);
        self::assertSame(10, $r['basis']['protection_days']);
        self::assertSame(20, $r['values']['reorder_point_pieces']);
        self::assertSame(20, $r['values']['target_stock_pieces']);
        self::assertSame(6, $r['basis']['batch_quantity_pieces']);
        self::assertSame(6, $r['values']['min_order_pieces']);
        self::assertSame(24, $r['values']['max_order_pieces']);
        self::assertSame(24, $r['basis']['weekly_order_capacity_pieces']);
        self::assertSame(14.0, $r['basis']['weekly_demand_pieces']);
        self::assertSame('schedule-test', $r['basis']['review_schedule_fingerprint']);
        self::assertSame([1], $r['basis']['review_weekdays']);
        $h['receipts'][5]['pieces'] = 12;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        self::assertFalse($r['available']);
        self::assertSame([], $r['values']);
        self::assertSame(12, $r['basis']['maximum_quantity_pieces']);
        self::assertStringContainsString('実行曜日を増やして再計算', $r['basis']['reason']);
        self::assertStringContainsString('数量条件を手入力で見直し', $r['basis']['reason']);
        $h['review_weekdays'] = [1, 4];
        $h['review_interval_days'] = 4;
        self::assertTrue(app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE'])['available']);
    }

    public function test_daily_schedule_with_sunday_clock_gap_protects_two_days_and_saves_metadata(): void
    {
        $h = $this->history();
        $h['review_interval_days'] = 2;
        $h['review_weekdays'] = range(0, 6);
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertSame(5, $r['basis']['protection_days']);
        self::assertSame(1500, $r['values']['reorder_point_pieces']);
        self::assertSame(600, $r['basis']['review_interval_demand_pieces']);
        self::assertSame(2028, $r['values']['target_stock_pieces']);
        self::assertSame('receipts-or-sales-adaptive-batch-v8', $r['basis']['method']);
        $h['review_interval_days'] = 0;
        self::assertFalse(app(SettingsRecommendationCalculator::class)->calculate($h)['available']);
    }

    public function test_infrequent_generation_target_keeps_room_for_minimum_case_before_running_out(): void
    {
        $h = $this->history();
        $h['scope']['capacity_case'] = 4;
        $h['scope']['packing_unit'] = 4;
        $h['daily'] = array_map(fn () => 1, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 6);
        foreach ($h['receipts'] as &$receipt) {
            $receipt['pieces'] = 4;
            $receipt['lead_days'] = 1;
        }
        unset($receipt);
        $h['nominal_lead_days'] = 1;
        $h['review_interval_days'] = 4;
        $h['review_weekdays'] = [1, 4];
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame(5, $r['values']['reorder_point_pieces']);
        self::assertSame(8, $r['values']['target_stock_pieces']);
        self::assertSame(4, $r['values']['min_order_pieces']);
        self::assertSame(4, $r['values']['max_order_pieces']);
        $p = $r['values'] + ['calculation_basis' => $r['basis']];
        $s = ['stock' => 3, 'incoming' => 0, 'claims' => 0, 'packing_unit' => 4,
            'business_date' => '2026-10-19', 'arrival_date' => '2026-10-20', 'next_arrival_date' => '2026-10-23'];
        $calculator = app(\App\Services\AutoReplenishment\StrategyCalculator::class);
        $proposal = $calculator->calculate($p, $s);
        self::assertSame('READY', $proposal['phase']);
        self::assertSame(4, $proposal['quantity']);
        app(\App\Services\AutoReplenishment\ProposalSafety::class)->validate($p, $proposal, 4);
        // The old five-piece target could not fit this case and withheld the needed order.
        $p['target_stock_pieces'] = 5;
        self::assertSame('NO_ORDER', $calculator->calculate($p, $s)['phase']);
    }

    public function test_daily_low_turnover_case_does_not_raise_trigger_or_target_to_extra_case(): void
    {
        $h = $this->history();
        $h['scope']['capacity_case'] = 4;
        $h['scope']['packing_unit'] = 4;
        $h['receipts'] = [['warehouse_id' => 1, 'pieces' => 4, 'date' => '2026-10-01', 'lead_days' => 1, 'quantity_types' => ['CASE']]];
        $h['daily'] = array_map(fn () => 0, $h['daily']);
        foreach (['2026-09-20', '2026-09-30', '2026-10-10'] as $date) {
            $h['daily'][$date] = 1;
        }
        $h['review_interval_days'] = 2;
        $h['review_weekdays'] = range(0, 6);
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame(1, $r['values']['reorder_point_pieces']);
        self::assertSame(5, $r['values']['target_stock_pieces']);
        self::assertSame(4, $r['values']['max_order_pieces']);
        $p = $r['values'] + ['calculation_basis' => $r['basis']];
        $s = ['stock' => 3, 'incoming' => 0, 'claims' => 0, 'packing_unit' => 4,
            'business_date' => '2026-10-19', 'arrival_date' => '2026-10-20', 'next_arrival_date' => '2026-10-21'];
        self::assertSame('NO_ORDER', app(\App\Services\AutoReplenishment\StrategyCalculator::class)->calculate($p, $s)['phase']);
    }

    public function test_delivery_gap_raises_only_target_and_preserves_quantity_conditions(): void
    {
        $h = $this->history();
        $h['daily'] = array_map(fn () => 2, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 6);
        foreach ($h['receipts'] as &$receipt) {
            $receipt['pieces'] = 6;
        }
        unset($receipt);
        $before = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        $h['maximum_arrival_gap_days'] = 3;
        $h['arrival_schedule'] = ['start' => '2026-10-18', 'end' => '2026-12-12', 'generation_count' => 56,
            'distinct_arrival_count' => 40, 'maximum_gap_days' => 3, 'schedule_fingerprint' => 'delivery-test',
            'opportunities' => [['generation_date' => '2026-10-18', 'arrival_date' => '2026-10-19']],
            'arrival_dates' => ['2026-10-19']];
        $after = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        self::assertTrue($after['available']);
        self::assertSame(8, $before['values']['target_stock_pieces']);
        self::assertSame(12, $after['values']['target_stock_pieces']);
        self::assertSame(8, $after['basis']['original_target_stock_pieces']);
        self::assertSame(6, $after['basis']['arrival_gap_demand_pieces']);
        self::assertSame(12, $after['basis']['arrival_gap_target_floor_pieces']);
        self::assertSame('2026-10-18', $after['basis']['arrival_schedule']['start']);
        self::assertSame(40, $after['basis']['arrival_schedule']['distinct_arrival_count']);
        self::assertArrayNotHasKey('opportunities', $after['basis']['arrival_schedule']);
        self::assertArrayNotHasKey('arrival_dates', $after['basis']['arrival_schedule']);
        foreach (['reorder_point_pieces', 'min_order_pieces', 'order_multiple_pieces', 'max_order_pieces'] as $field) {
            self::assertSame($before['values'][$field], $after['values'][$field]);
        }
        self::assertSame($before['basis']['batch_quantity_pieces'], $after['basis']['batch_quantity_pieces']);
        self::assertStringContainsString('入荷間隔 3日分', implode('', $after['basis']['steps']));
    }

    public function test_invalid_arrival_gap_never_silently_uses_generation_interval(): void
    {
        $h = $this->history();
        foreach ([null, 0, -1, 57, '1.5', '', 'unknown'] as $gap) {
            $h['maximum_arrival_gap_days'] = $gap;
            $result = app(SettingsRecommendationCalculator::class)->calculate($h);
            self::assertFalse($result['available']);
            self::assertSame([], $result['values']);
            self::assertStringContainsString('入荷日の間隔を確認できません', $result['basis']['reason']);
        }
    }

    public function test_arrival_gap_leaves_low_turnover_case_target_unrounded(): void
    {
        $h = $this->history();
        $h['scope']['capacity_case'] = 6;
        $h['receipts'] = [['warehouse_id' => 1, 'pieces' => 6, 'date' => '2026-10-01', 'lead_days' => 1]];
        $h['daily'] = array_map(fn () => 0, $h['daily']);
        foreach (['2026-09-20', '2026-09-30', '2026-10-10'] as $date) {
            $h['daily'][$date] = 1;
        }
        $h['maximum_arrival_gap_days'] = 3;
        $result = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($result['available']);
        self::assertSame(1, $result['values']['reorder_point_pieces']);
        self::assertSame(7, $result['values']['target_stock_pieces']);
        self::assertSame(6, $result['values']['order_multiple_pieces']);
        self::assertSame(7, $result['candidates']['history']['values']['target_stock_pieces']);
    }

    public function test_sparse_history_is_optional_and_preserves_sales_default_and_piece_preference(): void
    {
        $h = $this->history();
        $h['scope']['capacity_case'] = 6;
        $h['daily'] = array_map(fn () => 1, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 4);
        foreach ([6, 12, 6, 12] as $i => $quantity) {
            $h['receipts'][$i]['pieces'] = $quantity;
        }
        $h['maximum_arrival_gap_days'] = 3;
        $result = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE', 'candidate_key' => 'history']);
        self::assertTrue($result['available']);
        self::assertSame('sales', $result['basis']['candidate_key']);
        self::assertSame(7, $result['basis']['batch_quantity_pieces']);
        self::assertSame(7, $result['values']['min_order_pieces']);
        self::assertSame(7, $result['values']['max_order_pieces']);
        self::assertSame(12, $result['basis']['observed_maximum_quantity_pieces']);
        self::assertSame(2, $result['basis']['observed_maximum_frequency']);
        self::assertSame([['pieces' => 6, 'count' => 2], ['pieces' => 12, 'count' => 2]], $result['basis']['observed_quantity_counts']);
        self::assertSame($result['values'], $result['candidates']['sales']['values']);
        $reference = $result['candidates']['history'];
        self::assertTrue($reference['available']);
        self::assertSame('history', $reference['basis']['candidate_key']);
        self::assertTrue($reference['basis']['reference_only']);
        self::assertSame(6, $reference['basis']['batch_quantity_pieces']);
        self::assertSame(6, $reference['values']['min_order_pieces']);
        self::assertSame(12, $reference['values']['max_order_pieces']);
        self::assertSame(9, $reference['values']['target_stock_pieces']);
        self::assertSame(1, $reference['values']['order_multiple_pieces']);
        self::assertSame($result['values']['reorder_point_pieces'], $reference['values']['reorder_point_pieces']);
        foreach ($result['candidates'] as $candidate) {
            self::assertArrayNotHasKey('candidates', $candidate);
        }
    }

    public function test_reference_candidates_are_limited_to_one_to_four_own_receipt_dates(): void
    {
        $h = $this->history();
        $h['daily'] = array_map(fn () => 1, $h['daily']);
        foreach ([0, 1, 2, 3, 4, 5, 6] as $days) {
            $h['receipts'] = array_slice($this->history()['receipts'], 0, $days);
            $result = app(SettingsRecommendationCalculator::class)->calculate($h);
            self::assertSame($days >= 1 && $days <= 4, isset($result['candidates']));
        }
        $h['receipts'] = array_fill(0, 8, $this->history()['receipts'][0]);
        $result = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertSame(1, $result['basis']['own_receipt_days']);
        self::assertArrayHasKey('history', $result['candidates']);
        $h['scope']['warehouse_id'] = 2;
        self::assertArrayNotHasKey('candidates', app(SettingsRecommendationCalculator::class)->calculate($h));
    }

    public function test_reference_uses_only_quantities_compatible_with_selected_case_or_pallet(): void
    {
        $h = $this->history();
        $h['scope']['channel'] = 'LOGISTICS';
        $h['daily'] = array_map(fn () => 1, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 3);
        foreach ([12, 24, 48] as $i => $quantity) {
            $h['receipts'][$i]['pieces'] = $quantity;
        }
        foreach ([['unit_mode' => 'CASE'], ['unit_mode' => 'PALLET', 'pallet_pieces' => 24]] as $options) {
            $result = app(SettingsRecommendationCalculator::class)->calculate($h, $options);
            $reference = $result['candidates']['history'];
            self::assertSame(24, $result['values']['order_multiple_pieces']);
            self::assertSame(24, $reference['values']['order_multiple_pieces']);
            self::assertSame(24, $reference['values']['min_order_pieces']);
            self::assertSame(48, $reference['values']['max_order_pieces']);
            self::assertSame([['pieces' => 24, 'count' => 1], ['pieces' => 48, 'count' => 1]], $reference['basis']['observed_quantity_counts']);
        }
        $h['receipts'] = [$h['receipts'][0]];
        $result = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'CASE']);
        self::assertArrayNotHasKey('candidates', $result);
    }

    public function test_five_own_days_with_few_compatible_days_do_not_offer_sparse_history_choice(): void
    {
        $h = $this->history();
        $h['daily'] = array_map(fn () => 1, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 5);
        foreach ([1, 2, 3, 4, 24] as $i => $quantity) {
            $h['receipts'][$i]['pieces'] = $quantity;
        }
        $result = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'CASE']);
        self::assertSame('sales', $result['basis']['basis_type']);
        self::assertArrayNotHasKey('candidates', $result);
    }

    public function test_reference_candidate_must_cover_weekly_demand_independently_of_sales(): void
    {
        $h = $this->history();
        $h['daily'] = array_map(fn () => 10, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 1);
        $h['receipts'][0]['pieces'] = 6;
        $result = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        self::assertTrue($result['available']);
        self::assertFalse($result['candidates']['history']['available']);
        self::assertSame([], $result['candidates']['history']['values']);
        self::assertStringContainsString('需要を補えません', $result['candidates']['history']['basis']['reason']);
    }

    public function test_valid_history_reference_is_retained_when_sales_candidate_cannot_cover_weekly_demand(): void
    {
        $h = $this->history();
        $h['daily'] = array_map(fn () => 2, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 2);
        $h['receipts'][0]['pieces'] = 6;
        $h['receipts'][1]['pieces'] = 24;
        $h['review_weekdays'] = [1];
        $h['review_interval_days'] = 7;
        $result = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE', 'review_days' => 1]);
        self::assertFalse($result['available']);
        self::assertFalse($result['candidates']['sales']['available']);
        self::assertTrue($result['candidates']['history']['available']);
        self::assertSame(24, $result['candidates']['history']['values']['max_order_pieces']);
    }

    public function test_arrival_gap_quantity_overflow_is_rejected(): void
    {
        $h = $this->history();
        $h['daily'] = array_map(fn () => 100000000, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 6);
        foreach ($h['receipts'] as &$receipt) {
            $receipt['pieces'] = 2000000000;
        }
        unset($receipt);
        $h['maximum_arrival_gap_days'] = 56;
        $result = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        self::assertFalse($result['available']);
        self::assertSame([], $result['values']);
        self::assertStringContainsString('保存できる上限', $result['basis']['reason']);
    }

    public function test_service_reads_history_once_and_preserves_pallet_master_for_both_candidates(): void
    {
        $h = $this->history();
        $h['scope']['channel'] = 'LOGISTICS';
        $h['daily'] = array_map(fn () => 1, $h['daily']);
        $h['receipts'] = array_slice($h['receipts'], 0, 2);
        $h['receipts'][0]['pieces'] = 24;
        $h['receipts'][1]['pieces'] = 48;
        $route = ['shipping_unit_mode' => 'PALLET', 'channel' => 'LOGISTICS', 'item_id' => 143024, 'source_warehouse_id' => 91];
        $history = \Mockery::mock(\App\Services\AutoReplenishment\SettingsRecommendationHistory::class);
        $history->shouldReceive('read')->once()->with(6, 1, $route)->andReturn($h);
        $master = (object) ['id' => 19, 'pieces_per_pallet' => 24, 'version' => 2, 'capacity_case' => 24];
        $pallets = \Mockery::mock(\App\Services\AutoReplenishment\PalletUnitService::class);
        $pallets->shouldReceive('forRoute')->once()->with(6, $route)->andReturn($master);
        $pallets->shouldReceive('assertCapacity')->once()->with($master, 24);
        $this->app->instance(\App\Services\AutoReplenishment\PalletUnitService::class, $pallets);
        $service = new \App\Services\AutoReplenishment\SettingsRecommendationService($history, new SettingsRecommendationCalculator);
        $result = $service->recommend(6, 1, $route, ['unit_mode' => 'PIECE']);
        self::assertSame((array) $master, $result['basis']['pallet_unit']);
        foreach ($result['candidates'] as $candidate) {
            self::assertSame((array) $master, $candidate['basis']['pallet_unit']);
            self::assertSame(24, $candidate['values']['order_multiple_pieces']);
            self::assertSame('PALLET', $candidate['basis']['unit_mode']);
        }
    }
}
