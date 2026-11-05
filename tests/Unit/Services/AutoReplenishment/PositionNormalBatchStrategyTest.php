<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\InventoryProjection;
use App\Services\AutoReplenishment\PositionNormalBatchStrategy;
use App\Services\AutoReplenishment\ProposalSafety;
use App\Services\AutoReplenishment\StrategyCalculator;
use App\Services\AutoReplenishment\SupplyWindow;
use Tests\TestCase;

class PositionNormalBatchStrategyTest extends TestCase
{
    public static function policy(array $changes = []): array
    {
        return $changes + ['channel' => 'EOS', 'strategy_key' => PositionNormalBatchStrategy::KEY,
            'reorder_point_pieces' => 150, 'target_stock_pieces' => 300, 'min_order_pieces' => 24,
            'max_order_pieces' => 192, 'order_multiple_pieces' => 24,
            'calculation_basis' => ['mean_daily' => 10, 'batch_quantity_pieces' => 96]];
    }

    public static function snapshot(array $changes = []): array
    {
        return $changes + ['stock' => 100, 'incoming' => 0, 'incoming_today' => 0, 'claims' => 0, 'supply_events' => [],
            'packing_unit' => 24, 'business_date' => '2026-10-31', 'arrival_date' => '2026-11-02', 'shipping_unit' => ['mode' => 'STANDARD']];
    }

    private function calculate(array $policy = [], array $snapshot = []): array
    {
        $p = self::policy($policy);
        $s = self::snapshot($snapshot);
        $r = app(StrategyCalculator::class)->calculate($p, $s);
        app(ProposalSafety::class)->validate($p, $r, $s['packing_unit'], $s['shipping_unit']);

        return $r;
    }

    public function test_spec_examples_ip_gate_equality_and_usual_quantity_instead_of_fill_to_ceiling(): void
    {
        $r = $this->calculate(['reorder_point_pieces' => 1920, 'target_stock_pieces' => 4584, 'max_order_pieces' => 1728],
            ['stock' => 1067, 'incoming' => 1728, 'supply_events' => [['date' => '2026-11-03', 'pieces' => 1728]]]);
        self::assertSame('POSITION_SUFFICIENT', $r['reason_code']);
        self::assertSame(2795, $r['decision_inventory']['position']);
        self::assertSame(0, $r['quantity']);
        self::assertSame(0, $this->calculate(['reorder_point_pieces' => 192], ['stock' => 192])['quantity']);
        foreach ([300 => 96, 150 => 48, 100 => 0] as $ceiling => $quantity) {
            $r = $this->calculate(['target_stock_pieces' => $ceiling]);
            self::assertSame($quantity, $r['quantity']);
            self::assertSame(20, $r['quantity_calculation']['consumption']);
            self::assertSame(80, $r['quantity_calculation']['cap_position']);
        }
        $r = $this->calculate(['reorder_point_pieces' => 2000, 'target_stock_pieces' => 4000, 'max_order_pieces' => 1728,
            'calculation_basis' => ['mean_daily' => 10, 'batch_quantity_pieces' => 2400]], ['stock' => 1000]);
        self::assertSame(1728, $r['quantity']);
    }

    public function test_today_is_added_once_unknown_is_not_plotted_and_far_supply_does_not_suppress(): void
    {
        $events = [['date' => '2026-10-31', 'pieces' => 24], ['date' => '2026-11-03', 'pieces' => 48]];
        $r = $this->calculate([], ['incoming' => 72, 'supply_events' => $events]);
        self::assertSame(172, $r['decision_inventory']['position']);
        self::assertSame(124, $r['decision_inventory']['available_today']);
        self::assertSame(48, $r['decision_inventory']['future']);
        self::assertSame(0, $r['quantity']);
        $r = $this->calculate([], ['incoming' => 60, 'supply_events' => [['date' => null, 'pieces' => 60]]]);
        self::assertSame(100, $r['decision_inventory']['available_today']);
        self::assertSame(160, $r['decision_inventory']['position']);
        self::assertSame('SUPPLY_DATE_UNKNOWN', $r['warnings'][0]['code']);
        self::assertSame(0, $r['quantity']);
        $r = $this->calculate([], ['incoming' => 1000, 'supply_events' => [['date' => '2026-12-31', 'pieces' => 1000]]]);
        self::assertSame(96, $r['quantity']);
        self::assertSame(1000, $r['decision_inventory']['excluded']);
        // Real receipt transfers 24 pieces from supply into H and leaves IP unchanged.
        $r2 = $this->calculate([], ['stock' => 124, 'incoming' => 48, 'supply_events' => [$events[1]]]);
        self::assertSame(172, $r2['decision_inventory']['position']);
    }

    public function test_sixty_day_inclusive_boundary_preserves_sunday_unknown_and_invalid_dates(): void
    {
        $window = app(SupplyWindow::class)->classify([
            ['date' => '2026-12-30', 'pieces' => 1], ['date' => '2026-12-31', 'pieces' => 2],
            ['date' => null, 'pieces' => 3], ['date' => '2026-10-30', 'pieces' => 4],
            ['date' => '2026-02-30', 'pieces' => 5], ['date' => 'not a date', 'pieces' => 6],
            ['date' => '2026-11-01', 'pieces' => 7],
        ], '2026-10-31');
        self::assertSame(8, $window['future']);
        self::assertSame(2, $window['excluded']);
        self::assertSame(18, $window['undated']);
        self::assertSame(0, $window['today']);
        self::assertSame('2026-11-01', $window['events'][6]['date']);
        self::assertCount(4, $window['warnings']);
        $window = app(SupplyWindow::class)->classify([['date' => '2026-12-27', 'pieces' => 9]], '2026-10-28');
        self::assertSame(9, $window['future']); // Sunday D+60 is included on its recorded date.
        self::assertSame(0, $window['excluded']);
    }

    public function test_shipment_values_are_not_combined_and_minimum_excludes_sixteen_cases(): void
    {
        $p = ['channel' => 'LOGISTICS', 'reorder_point_pieces' => 1000, 'min_order_pieces' => 576,
            'max_order_pieces' => 1728, 'order_multiple_pieces' => 192,
            'calculation_basis' => ['mean_daily' => 10, 'batch_quantity_pieces' => 1728]];
        $s = ['shipping_unit' => ['mode' => 'SHIPMENT', 'quantities' => [384, 576, 768, 1728]]];
        self::assertSame(0, $this->calculate($p + ['target_stock_pieces' => 580], $s)['quantity']); // room 500
        self::assertSame(768, $this->calculate($p + ['target_stock_pieces' => 880], $s)['quantity']);
        self::assertSame(768, $this->calculate($p + ['target_stock_pieces' => 1280], $s)['quantity']); // no 384+768
    }

    public function test_lcm_non_multiple_maximum_negative_stock_missing_normal_and_invalid_inputs(): void
    {
        $r = $this->calculate(['order_multiple_pieces' => 6, 'min_order_pieces' => 10, 'max_order_pieces' => 25], ['packing_unit' => 4]);
        self::assertSame(24, $r['quantity']);
        self::assertSame(12, $r['quantity_calculation']['unit']);
        $r = $this->calculate([], ['stock' => -6]);
        self::assertSame(-6, $r['quantity_calculation']['remaining']);
        self::assertSame(96, $r['quantity']);
        $r = $this->calculate(['calculation_basis' => ['mean_daily' => 10]]);
        self::assertSame(24, $r['quantity']);
        self::assertContains('NORMAL_BATCH_MISSING', array_column($r['warnings'], 'code'));
        foreach ([['min_order_pieces' => 193], ['order_multiple_pieces' => 0], ['order_multiple_pieces' => 193],
            ['calculation_basis' => []], ['calculation_basis' => ['mean_daily' => 10, 'batch_quantity_pieces' => 0]]] as $p) {
            self::assertSame('HOLD', $this->calculate($p)['phase']);
        }
        foreach ([['stock' => null], ['incoming' => 10], ['claims' => 10], ['packing_unit' => 0], ['arrival_date' => '2026-11-15'],
            ['supply_events' => [['pieces' => -1]]], ['incoming' => 2, 'supply_events' => [['supply_id' => 'x', 'pieces' => 1], ['supply_id' => 'x', 'pieces' => 1]]]] as $s) {
            self::assertSame('HOLD', $this->calculate([], $s)['phase']);
        }
    }

    public function test_graph_uses_same_decisions_rolling_window_and_never_receives_unknown_supply(): void
    {
        $p = array_replace(InventoryProjectionTest::policy(), ['strategy_key' => PositionNormalBatchStrategy::KEY]);
        $input = InventoryProjectionTest::inputs();
        $input['snapshot']['incoming'] = 140;
        $input['snapshot']['supply_events'] = [['date' => '2026-12-19', 'pieces' => 100], ['date' => null, 'pieces' => 40]];
        $chart = app(InventoryProjection::class)->calculate($p, $input);
        self::assertSame(100, $chart['days'][0]['decision_inventory']['excluded']);
        self::assertSame(100, $chart['days'][1]['decision_inventory']['future']);
        self::assertSame(0, array_sum(array_column($chart['days'], 'received')));
        self::assertSame(0, $chart['order_count']);
        self::assertGreaterThan(0, $chart['shortage_days']); // Future/unknown supply never becomes physical stock early.
        foreach ($chart['days'] as $day) {
            $snapshot = array_replace($input['snapshot'], ['stock' => $day['opening'], 'business_date' => $day['date'],
                'arrival_date' => $input['calendar']['arrivals'][$day['date']]]);
            $r = app(StrategyCalculator::class)->calculate($p, $snapshot);
            self::assertSame($r['decision_inventory'], $day['decision_inventory']);
            self::assertSame($r['quantity'], $day['ordered']);
        }
    }

    public function test_graph_receives_today_new_orders_and_future_sunday_supply_once(): void
    {
        $p = array_replace(InventoryProjectionTest::policy(), ['strategy_key' => PositionNormalBatchStrategy::KEY]);
        $input = InventoryProjectionTest::inputs();
        $input['snapshot']['stock'] = 0;
        $input['snapshot']['incoming'] = 120;
        $input['snapshot']['supply_events'] = [['date' => '2026-10-25', 'pieces' => 120]];
        foreach ($input['calendar']['arrivals'] as $date => &$arrival) {
            $arrival = $date; // Explicit same-day fixture; dates in the production calendar have already been resolved.
        }
        unset($arrival);
        $chart = app(InventoryProjection::class)->calculate($p, $input);
        self::assertSame(120, array_sum(array_column($chart['days'], 'existing_receipt')));
        self::assertSame(120, $chart['days'][6]['existing_receipt']);
        self::assertSame(0, $chart['days'][7]['existing_receipt']);
        self::assertSame(array_sum(array_column($chart['orders'], 'pieces')), array_sum(array_column($chart['days'], 'new_receipt')));
    }

    public function test_replay_and_graph_match_the_shared_calculator_for_each_day(): void
    {
        $p = array_replace(InventoryProjectionTest::policy(), ['strategy_key' => PositionNormalBatchStrategy::KEY]);
        $input = InventoryProjectionTest::inputs();
        $input['snapshot']['incoming'] = 30;
        $input['snapshot']['supply_events'] = [['date' => '2026-10-19', 'pieces' => 10], ['date' => '2026-10-25', 'pieces' => 20]];
        $chart = app(InventoryProjection::class)->calculate($p, $input);
        $arrivals = $input['calendar']['arrivals'];
        $calendar = \App\Services\AutoReplenishment\Replay\ReplayCalendar::scheduled(fn ($date) => array_map(
            fn ($generation, $arrival) => ['generation_date' => $generation, 'arrival_date' => $arrival], array_keys($arrivals), array_values($arrivals)));
        $series = ['client_id' => 6, 'warehouse_id' => 1, 'item_id' => 1, 'capacity_case' => 10, 'packing_unit' => 10,
            'channel' => 'LOGISTICS', 'saved_policy' => $p, 'demand' => array_fill_keys(array_column($chart['days'], 'date'), 10)];
        $replay = app(\App\Services\AutoReplenishment\Replay\ReplayEngine::class)->run($series,
            ['name' => 'position', 'mode' => 'saved', 'start' => '2026-10-19', 'end' => '2026-11-01', 'initial_stock' => 90,
                'initial_supply' => $input['snapshot']['supply_events'], 'warmup_days' => 0], $calendar);
        foreach ($chart['days'] as $i => $day) {
            $actual = $replay['days'][$i];
            foreach (['ordered', 'stock', 'received', 'phase', 'decision_inventory', 'quantity_calculation'] as $field) {
                self::assertSame($day[$field], $actual[$field], $day['date'].' '.$field);
            }
        }
        unset($arrivals['2026-10-19']);
        $skipCalendar = \App\Services\AutoReplenishment\Replay\ReplayCalendar::scheduled(fn ($date) => array_map(
            fn ($generation, $arrival) => ['generation_date' => $generation, 'arrival_date' => $arrival], array_keys($arrivals), array_values($arrivals)));
        $skip = app(\App\Services\AutoReplenishment\Replay\ReplayEngine::class)->run($series,
            ['name' => 'position-skip', 'mode' => 'saved', 'start' => '2026-10-19', 'end' => '2026-10-19', 'initial_stock' => 90,
                'initial_supply' => $input['snapshot']['supply_events'], 'warmup_days' => 0], $skipCalendar)['days'][0];
        self::assertSame('SKIP', $skip['phase']);
        self::assertSame(10, $skip['received']);
        self::assertSame(120, $skip['decision_inventory']['position']);
        self::assertSame(90, $skip['stock']);
    }

    public function test_new_strategy_requires_explicit_ceiling_and_does_not_enable_or_replace_legacy_settings(): void
    {
        $service = app(\App\Services\AutoReplenishment\PolicyService::class);
        $p = self::policy(['item_id' => 1, 'contractor_id' => 1, 'is_enabled' => true]);
        $valid = $service->validate($p);
        self::assertArrayNotHasKey('is_enabled', $valid);
        foreach ([null, 150, 149] as $ceiling) {
            try {
                $service->validate(array_replace($p, ['target_stock_pieces' => $ceiling]));
                self::fail('Invalid ceiling accepted');
            } catch (\Illuminate\Validation\ValidationException $e) {
                self::assertArrayHasKey('target_stock_pieces', $e->errors());
            }
        }
        self::assertFalse(config('replenishment.enabled'));
        self::assertFalse(config('replenishment.weekly_refresh.enabled'));
        self::assertFalse(config('replenishment.demand_events.weekly_prepare.enabled'));
    }

    public function test_recommendation_preserves_explicit_strategy_in_all_candidates_without_altering_quantities(): void
    {
        $old = ['available' => true, 'values' => self::policy(['strategy_key' => 'arrival_adaptive_v1']),
            'basis' => ['suggested_values' => self::policy(['strategy_key' => 'arrival_adaptive_v1'])]];
        $result = app(\App\Services\AutoReplenishment\SettingsRecommendationService::class)->withPositionStrategy($old + ['candidates' => ['sales' => $old]]);
        self::assertSame(PositionNormalBatchStrategy::KEY, $result['values']['strategy_key']);
        self::assertSame(PositionNormalBatchStrategy::KEY, $result['candidates']['sales']['values']['strategy_key']);
        self::assertSame(300, $result['values']['target_stock_pieces']);
        self::assertSame(96, $result['values']['calculation_basis']['batch_quantity_pieces']);
        self::assertSame('arrival_adaptive_v1', $old['values']['strategy_key']);
    }

    public function test_new_graph_renders_inventory_position_and_its_distinct_physical_line(): void
    {
        $p = array_replace(InventoryProjectionTest::policy(), ['strategy_key' => PositionNormalBatchStrategy::KEY]);
        $chart = app(InventoryProjection::class)->calculate($p, InventoryProjectionTest::inputs());
        \Livewire\Livewire::test(PositionProjectionFixture::class, ['inventoryProjection' => $chart])
            ->assertSee('発注判定在庫線を表示')->assertSee('見込在庫（入荷日に加算）')->assertSee('60日外');
    }

    public function test_next_opportunity_diagnostic_does_not_forbid_legitimate_consecutive_orders(): void
    {
        $policy = ['calculation_basis' => ['mean_daily' => 60, 'batch_quantity_pieces' => 96], 'target_stock_pieces' => 500, 'max_order_pieces' => 96];
        $first = $this->calculate($policy, ['next_generation_date' => '2026-11-01']);
        self::assertSame(96, $first['quantity']);
        self::assertSame(136, $first['quantity_calculation']['next_position_estimate']);
        self::assertContains('NEXT_POSITION_LOW', array_column($first['warnings'], 'code'));
        $next = $this->calculate($policy, ['stock' => 40, 'incoming' => 96, 'supply_events' => [['date' => '2026-11-02', 'pieces' => 96]],
            'business_date' => '2026-11-01', 'arrival_date' => '2026-11-03']);
        self::assertSame(136, $next['decision_inventory']['position']);
        self::assertSame(96, $next['quantity']);
    }

    public function test_recovery_covers_next_run_consumption_without_filling_to_ceiling(): void
    {
        $policy = ['reorder_point_pieces' => 1344, 'target_stock_pieces' => 1776, 'min_order_pieces' => 384,
            'max_order_pieces' => 1728, 'calculation_basis' => ['mean_daily' => 214, 'batch_quantity_pieces' => 384]];
        $first = $this->calculate($policy, ['stock' => 1064, 'business_date' => '2026-11-02',
            'arrival_date' => '2026-11-04', 'next_generation_date' => '2026-11-03']);
        self::assertSame(494, $first['quantity_calculation']['recovery_required']);
        self::assertSame(504, $first['quantity']);
        self::assertSame(1354, $first['quantity_calculation']['next_position_estimate']);
        self::assertSame('RECOVERY_BATCH', $first['reason_code']);
        $next = $this->calculate($policy, ['stock' => 850, 'incoming' => 504,
            'supply_events' => [['date' => '2026-11-04', 'pieces' => 504]],
            'business_date' => '2026-11-03', 'arrival_date' => '2026-11-04', 'next_generation_date' => '2026-11-04']);
        self::assertSame(0, $next['quantity']);
        self::assertSame('POSITION_SUFFICIENT', $next['reason_code']);
        // Arrival consumes no inventory position: the same supply moves into today's stock once.
        $arriving = $this->calculate($policy, ['stock' => 850, 'incoming' => 504,
            'supply_events' => [['date' => '2026-11-03', 'pieces' => 504]],
            'business_date' => '2026-11-03', 'arrival_date' => '2026-11-04', 'next_generation_date' => '2026-11-04']);
        self::assertSame(1354, $arriving['decision_inventory']['position']);
        self::assertSame(0, $arriving['quantity']);
    }

    public function test_recovery_obeys_ceiling_registered_quantities_and_pallets(): void
    {
        $policy = ['channel' => 'LOGISTICS', 'reorder_point_pieces' => 1344, 'target_stock_pieces' => 1776,
            'min_order_pieces' => 384, 'max_order_pieces' => 1728,
            'calculation_basis' => ['mean_daily' => 214, 'batch_quantity_pieces' => 384]];
        $snapshot = ['stock' => 1064, 'next_generation_date' => '2026-11-01'];
        $shipment = $this->calculate($policy, $snapshot + ['shipping_unit' => ['mode' => 'SHIPMENT', 'quantities' => [384, 576, 768, 1728]]]);
        self::assertSame(576, $shipment['quantity']);
        $pallet = $this->calculate(array_replace($policy, ['order_multiple_pieces' => 384]),
            $snapshot + ['shipping_unit' => ['mode' => 'PALLET', 'pieces_per_pallet' => 384]]);
        self::assertSame(768, $pallet['quantity']);
        // A tight ceiling wins over recovery. Do not exceed it or fabricate a smaller pallet.
        $limited = $this->calculate(array_replace($policy, ['target_stock_pieces' => 1050]), $snapshot);
        self::assertSame(408, $limited['quantity']);
        self::assertLessThanOrEqual(1050, $limited['quantity_calculation']['cap_position'] + $limited['quantity']);
        self::assertContains('NEXT_POSITION_LOW', array_column($limited['warnings'], 'code'));
    }

    public function test_weekly_recovery_equality_missing_schedule_and_small_normal(): void
    {
        $p = ['reorder_point_pieces' => 77, 'target_stock_pieces' => 101, 'min_order_pieces' => 24,
            'max_order_pieces' => 96, 'order_multiple_pieces' => 1,
            'calculation_basis' => ['mean_daily' => 37, 'batch_quantity_pieces' => 24]];
        $r = $this->calculate($p, ['stock' => 39, 'packing_unit' => 1, 'next_generation_date' => '2026-11-01']);
        self::assertSame(75, $r['quantity']);
        self::assertSame(77, $r['quantity_calculation']['next_position_estimate']);
        self::assertNotContains('NEXT_POSITION_LOW', array_column($r['warnings'], 'code'));
        $weekly = $this->calculate([], ['next_generation_date' => '2026-11-07']);
        self::assertSame(120, $weekly['quantity']); // R150 + 7*10 - IP100
        foreach ([null, 'bad', '2026-10-31', '2026-11-08'] as $date) {
            $fallback = $this->calculate([], ['next_generation_date' => $date]);
            self::assertSame(96, $fallback['quantity']);
            self::assertContains('NEXT_GENERATION_UNKNOWN', array_column($fallback['warnings'], 'code'));
        }
    }

    public function test_boundary_calendar_normal_adjustment_and_integer_limits(): void
    {
        foreach (['2027-12-31', '2028-01-01', '2028-02-01', '2028-02-29'] as $day) {
            $d = \Carbon\CarbonImmutable::parse($day);
            $inside = $d->addDays(60)->toDateString();
            $outside = $d->addDays(61)->toDateString();
            $w = app(SupplyWindow::class)->classify([['date' => $inside, 'pieces' => 3], ['date' => $outside, 'pieces' => 7]], $day);
            self::assertSame($inside, $w['cutoff_date']);
            self::assertSame(3, $w['future']);
            self::assertSame(10, $w['total']);
        }
        foreach ([1 => 24, 25 => 48, 200 => 192] as $normal => $expected) {
            $r = $this->calculate(['calculation_basis' => ['mean_daily' => 10, 'batch_quantity_pieces' => $normal]]);
            self::assertSame($expected, $r['quantity']);
        }
        foreach ([['calculation_basis' => ['mean_daily' => 10, 'batch_quantity_pieces' => '96']],
            ['calculation_basis' => ['mean_daily' => 10, 'batch_quantity_pieces' => 2147483648]], ['calculation_basis' => 'bad']] as $policy) {
            self::assertSame('HOLD', $this->calculate($policy)['phase']);
        }
        self::assertSame('HOLD', $this->calculate([], ['stock' => 2147483647, 'incoming' => 1,
            'supply_events' => [['date' => '2026-11-04', 'pieces' => 1]]])['phase']);
        self::assertSame('READY', $this->calculate([], ['arrival_date' => '2026-11-14'])['phase']);
        $r = $this->calculate(['channel' => 'LOGISTICS', 'order_multiple_pieces' => 144, 'min_order_pieces' => 144],
            ['shipping_unit' => ['mode' => 'PALLET', 'pieces_per_pallet' => 144]]);
        self::assertSame(144, $r['quantity']);
        self::assertSame('HOLD', $this->calculate(['channel' => 'LOGISTICS'],
            ['shipping_unit' => ['mode' => 'PALLET', 'pieces_per_pallet' => 144]])['phase']);
    }
}

class PositionProjectionFixture extends \Livewire\Component
{
    public array $inventoryProjection = [];

    public ?string $inventoryProjectionError = null;

    public array $form = ['channel' => 'EOS'];

    public function render()
    {
        return '<div>@include("filament.pages.auto-replenishment-projection")</div>';
    }
}
