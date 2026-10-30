<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\LogisticsPickingDate;
use App\Services\AutoReplenishment\PolicySimulationService;
use App\Services\AutoReplenishment\ProposalSafety;
use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use App\Services\AutoReplenishment\SnapshotReader;
use App\Services\AutoReplenishment\StrategyCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SafetyStockSettingsTest extends TestCase
{
    private const LOTS = [384, 576, 768, 960, 1152, 1728, 2112, 2304, 3456];

    public static function history(): array
    {
        $daily = [];
        for ($i = 0; $i < 28; $i++) {
            $daily[CarbonImmutable::parse('2026-09-25')->addDays($i)->toDateString()] = $i < 12 ? 265 : 264;
        }
        $quantities = [576, 576, 768, 768, 960, 960, 960, 1152, 1152, 1728, 1728, 1728, 1728, 1728, 1728];

        return ['scope' => ['channel' => 'LOGISTICS', 'warehouse_id' => 2, 'packing_unit' => 24, 'capacity_case' => 24],
            'period' => ['start' => '2026-09-25', 'end' => '2026-10-22'], 'daily' => $daily,
            'nominal_lead_days' => 2, 'review_interval_days' => 1, 'maximum_arrival_gap_days' => 3,
            'receipts' => array_map(fn ($i, $q) => ['warehouse_id' => 2, 'pieces' => $q,
                'date' => CarbonImmutable::parse('2026-09-25')->addDays($i)->toDateString(), 'lead_days' => 2], array_keys($quantities), $quantities)];
    }

    public static function recommendation(array $options = [], ?array $history = null): array
    {
        return app(SettingsRecommendationCalculator::class)->calculate($history ?? self::history(), $options + [
            'safety_stock_days' => '5', 'existing_reorder_point' => 1920, 'shipping_quantities' => self::LOTS,
        ]);
    }

    private function policy(array $result): array
    {
        return $result['values'] + ['channel' => 'LOGISTICS', 'calculation_basis' => $result['basis']];
    }

    private function snapshot(int $stock, int $incoming = 0, int $claims = 0): array
    {
        return ['stock' => $stock, 'incoming' => $incoming, 'claims' => $claims, 'packing_unit' => 24,
            'business_date' => '2026-10-23', 'arrival_date' => '2026-10-25', 'next_arrival_date' => '2026-10-26',
            'shipping_unit' => ['mode' => 'SHIPMENT', 'quantities' => self::LOTS],
            'quantity_type' => 'CASE', 'capacity_case' => 24, 'route_snapshot' => ['effective_picking_lead_days' => 1]];
    }

    public function test_five_days_adds_arrival_waiting_consumption_without_expanding_to_a_pallet(): void
    {
        $r = self::recommendation();
        self::assertTrue($r['available']);
        self::assertSame(['reorder_point_pieces' => 2690, 'target_stock_pieces' => 3888, 'min_order_pieces' => 384,
            'order_multiple_pieces' => 192, 'max_order_pieces' => 1728, 'strategy_key' => 'arrival_adaptive_v1'], $r['values']);
        self::assertSame(5, $r['basis']['safety_stock_days']);
        self::assertSame(1344, $r['basis']['safety_stock_pieces']);
        self::assertSame(1728, $r['basis']['batch_quantity_pieces']);
        // The two-day weekday forecast rounds up to 529, rather than truncating the mean to 528.
        self::assertSame(2689, $r['basis']['normal_order_stock_pieces']);
        self::assertFalse($r['basis']['reorder_point_preserved']);
        self::assertSame('safety-stock-days-v2', $r['basis']['quantity_model']);
        self::assertSame(816, $r['basis']['safety_arrival_gap_adjustment_pieces']);
    }

    public function test_reorder_gate_and_target_are_calculated_together_and_old_inverted_gate_is_replaced(): void
    {
        foreach ([null, 1000, 3274] as $existing) {
            $r = self::recommendation(['existing_reorder_point' => $existing]);
            self::assertSame(2690, $r['values']['reorder_point_pieces']);
            self::assertSame(3888, $r['values']['target_stock_pieces']);
            self::assertFalse($r['basis']['reorder_point_preserved']);
        }
        $larger = self::recommendation(['safety_stock_days' => 6]);
        self::assertSame(4152, $larger['values']['target_stock_pieces']);
        self::assertSame(2954, $larger['values']['reorder_point_pieces']);
        self::assertSame(1728, $larger['basis']['batch_quantity_pieces']);
    }

    public function test_invalid_safety_days_do_not_return_values(): void
    {
        foreach ([null, '', 0, -1, 29, '1.5', 'abc'] as $days) {
            $r = self::recommendation(['safety_stock_days' => $days]);
            self::assertFalse($r['available']);
            self::assertSame([], $r['values']);
            self::assertStringContainsString('安全在庫日数は1〜28日の整数', $r['basis']['reason']);
        }
    }

    public function test_arrival_gap_and_long_lead_are_explicit_without_pallet_rounding(): void
    {
        $h = self::history();
        $h['maximum_arrival_gap_days'] = 20;
        $r = self::recommendation([], $h);
        self::assertSame(8376, $r['values']['target_stock_pieces']);
        self::assertSame(5304, $r['basis']['safety_arrival_gap_adjustment_pieces']);
        self::assertStringContainsString('入荷間隔', implode('', $r['basis']['warnings']));
        $h = self::history();
        $h['nominal_lead_days'] = 10;
        $r = self::recommendation([], $h);
        self::assertSame(3888, $r['values']['target_stock_pieces']);
        self::assertSame(4806, $r['values']['reorder_point_pieces']);
        self::assertStringContainsString('入荷までの出荷見込が通常量より多い', implode('', $r['basis']['warnings']));
        $r = app(SettingsRecommendationCalculator::class)->calculate(self::history(), ['safety_stock_days' => 5, 'unit_mode' => 'PALLET', 'pallet_pieces' => 576]);
        self::assertSame(3888, $r['values']['target_stock_pieces']);
        self::assertSame(576, $r['values']['order_multiple_pieces']);
    }

    public function test_sales_fallback_and_piece_orders_keep_batch_days_separate_from_safety_days(): void
    {
        $h = self::history();
        $h['scope']['channel'] = 'EOS';
        $h['receipts'] = [];
        foreach ([3, 5] as $days) {
            $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['safety_stock_days' => $days, 'review_days' => 7, 'unit_mode' => 'CASE']);
            self::assertSame(1872, $r['basis']['batch_quantity_pieces']);
            self::assertSame($days === 3 ? 3504 : 4032, $r['values']['target_stock_pieces']);
        }
        $h['scope']['packing_unit'] = 1;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['safety_stock_days' => 5, 'unit_mode' => 'PIECE']);
        self::assertSame(1, $r['basis']['safety_stock_rounding_unit']);
        self::assertSame(1323, $r['basis']['safety_stock_pieces']);
    }

    public function test_same_runtime_path_respects_boundary_existing_supply_negative_stock_and_case_output(): void
    {
        $r = self::recommendation();
        $policy = $this->policy($r);
        foreach ([[2688, 0, 0, 1728], [2689, 0, 0, 1728], [2690, 0, 0, 0], [3000, 0, 0, 0], [1872, 1728, 0, 0], [1872, 0, 1728, 0], [-6, 0, 0, 1728]] as [$stock, $incoming, $claims, $expected]) {
            $snapshot = $this->snapshot($stock, $incoming, $claims);
            $batch = app(StrategyCalculator::class)->calculate($policy, $snapshot);
            $reader = Mockery::mock(SnapshotReader::class);
            $reader->shouldReceive('readForSimulation')->once()->andReturn($snapshot);
            $preview = (new PolicySimulationService($reader, new ProposalSafety, new LogisticsPickingDate))->simulate(new WmsItemReorderPolicy($policy));
            self::assertSame($expected, $batch['quantity']);
            self::assertSame($batch['phase'], $preview['phase']);
            self::assertSame($expected, $preview['quantity_pieces']);
            self::assertSame(intdiv($expected, 24), $preview['order_quantity']);
            self::assertSame(5, $preview['quantity_settings']['safety_days']);
        }
    }

    public function test_batch_preparation_and_preview_share_saved_safety_settings_for_eos_and_logistics(): void
    {
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE wms_auto_order_run_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, policy_id INTEGER, effect_key TEXT, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, channel TEXT, phase TEXT, reason TEXT, quantity_pieces INTEGER, policy_version INTEGER, warehouse_version INTEGER, proposal_snapshot TEXT, created_at TEXT, updated_at TEXT)');
        $db->statement('CREATE TABLE wms_warehouse_auto_order_settings (warehouse_id INTEGER, replenishment_version INTEGER)');
        $r = self::recommendation();
        foreach (['EOS', 'LOGISTICS'] as $i => $channel) {
            $policy = new WmsItemReorderPolicy(array_replace($this->policy($r), ['id' => $i + 1,
                'warehouse_id' => 2, 'item_id' => 143024, 'channel' => $channel, 'version' => 1, 'is_enabled' => false]));
            // Round-trip the saved JSON representation used by a later worker request.
            $policy->calculation_basis = json_decode(json_encode($policy->calculation_basis), true);
            $snapshot = $this->snapshot(1872);
            if ($channel === 'EOS') {
                unset($snapshot['shipping_unit']);
            }
            $reader = Mockery::mock(SnapshotReader::class);
            $reader->shouldReceive('read')->once()->with($policy)->andReturn($snapshot);
            $reader->shouldReceive('readForSimulation')->once()->with($policy)->andReturn($snapshot);
            $execution = new \App\Services\AutoReplenishment\ExecutionService(
                Mockery::mock(\App\Services\AutoReplenishment\ReplenishmentGuard::class), $reader,
                Mockery::mock(\App\Services\AutoReplenishment\BudgetService::class),
                Mockery::mock(\App\Services\AutoReplenishment\ArtifactRecorder::class));
            $run = new \App\Models\WmsAutoOrderRun(['id' => $i + 1, 'client_id' => 6, 'warehouse_id' => 2]);
            $line = $execution->prepare($run, $policy);
            $preview = (new PolicySimulationService($reader, new ProposalSafety, new LogisticsPickingDate))->simulate($policy);
            self::assertSame('READY', $line->phase);
            self::assertSame($preview['phase'], $line->phase);
            self::assertSame(1728, (int) $line->quantity_pieces);
            self::assertSame($preview['quantity_pieces'], (int) $line->quantity_pieces);
            self::assertSame(5, $preview['quantity_settings']['safety_days']);
            self::assertFalse($policy->is_enabled);
            self::assertSame($line->id, $execution->prepare($run, $policy)->id);
        }
        self::assertSame(2, $db->table('wms_auto_order_run_lines')->count());
        DB::disconnect('sakemaru');
    }

    public function test_normal_order_boundary_does_not_subtract_forecast_from_negative_stock_twice(): void
    {
        $r = self::recommendation();
        $boundary = app(\App\Services\AutoReplenishment\SafetyStockSettings::class)->normalOrderBoundary(1920, 3072, 1728, 528, 1728);
        self::assertSame(-384, $boundary);
        $legacyPolicy = array_replace($this->policy($r), ['reorder_point_pieces' => 1920, 'target_stock_pieces' => 3072]);
        $at = app(StrategyCalculator::class)->calculate($legacyPolicy, $this->snapshot($boundary, 1728));
        $above = app(StrategyCalculator::class)->calculate($legacyPolicy, $this->snapshot($boundary + 1, 1728));
        self::assertSame(1728, $at['quantity']);
        self::assertLessThan(1728, $above['quantity']);
        self::assertStringContainsString('現在庫 -384バラ以下', implode('', $at['formula']));
    }

    public function test_existing_policy_without_days_keeps_identical_quantities_and_formula(): void
    {
        $r = app(SettingsRecommendationCalculator::class)->calculate(self::history(), ['shipping_quantities' => self::LOTS]);
        self::assertArrayNotHasKey('safety_stock_days', $r['basis']);
        $policy = $this->policy($r);
        $snapshot = $this->snapshot(500);
        $before = app(StrategyCalculator::class)->calculate($policy, $snapshot);
        $policy['calculation_basis']['safety_stock_days'] = 5;
        $policy['calculation_basis']['safety_stock_pieces'] = 1344;
        $after = app(StrategyCalculator::class)->calculate($policy, $snapshot);
        self::assertSame($before['quantity'], $after['quantity']);
        self::assertSame($before['phase'], $after['phase']);
        self::assertStringNotContainsString('安全在庫', implode('', $before['formula']));
    }

    public function test_overflow_returns_explanation_instead_of_corrupt_settings(): void
    {
        $h = self::history();
        $h['daily'] = array_fill_keys(array_keys($h['daily']), 2147483647);
        $r = self::recommendation(['safety_stock_days' => 28], $h);
        self::assertFalse($r['available']);
        self::assertStringContainsString('上限', $r['basis']['reason']);
    }

    public function test_operator_example_and_controls_use_current_values_and_same_strategy_without_database_queries(): void
    {
        $recommendation = self::recommendation();
        $form = $recommendation['values'] + ['channel' => 'LOGISTICS', 'shipping_unit_mode' => 'SHIPMENT'];
        DB::connection('sakemaru')->beforeExecuting(fn () => throw new \RuntimeException('No DB queries allowed for recommendation rendering'));
        Livewire::test(SafetyStockViewFixture::class, ['form' => $form, 'recommendation' => $recommendation])
            ->assertSee('安全在庫日数')->assertSee('安全在庫 5日分 1,344バラ')
            ->assertSee('現在庫 2,689バラ')->assertSee('1,728バラ発注')
            ->set('form.max_order_pieces', 1152)->assertSee('1,152バラ発注')
            ->set('form.max_order_pieces', '')->assertSee('数量例は表示できません');
    }
}

class SafetyStockViewFixture extends Component
{
    public array $form = [];

    public array $recommendation = [];

    public array $shippingProfile = ['quantities' => [384, 576, 768, 960, 1152, 1728, 2112, 2304, 3456]];

    public string $recommendationSafetyDays = '5';

    public string $appliedRecommendationSafetyDays = '5';

    public string $recommendationReviewDays = '7';

    public bool $recommendationPreferencesChanged = false;

    public array $eventSetup = [];

    public function render()
    {
        return <<<'BLADE'
        <div>@include('filament.pages.auto-replenishment-recommendation-actions', ['recommendationReady'=>true,'helperItem'=>(object)['id'=>1],'orderUnit'=>['label'=>'ケース発注','automatic_label'=>'ケース発注','piece_orderable'=>false,'reason'=>'ケース24入']])
        @include('filament.pages.auto-replenishment-recommendation')</div>
        BLADE;
    }
}
