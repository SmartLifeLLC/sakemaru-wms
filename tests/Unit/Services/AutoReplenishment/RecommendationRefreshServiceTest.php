<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\PalletUnitService;
use App\Services\AutoReplenishment\PolicyService;
use App\Services\AutoReplenishment\RecommendationRefreshService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use App\Services\AutoReplenishment\SettingsRecommendationService;
use App\Services\AutoReplenishment\ShippingQuantityService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RecommendationRefreshServiceTest extends TestCase
{
    private array $basis;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE wms_item_reorder_policies (id INTEGER PRIMARY KEY, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, is_configured INTEGER, is_enabled INTEGER, reorder_point_pieces INTEGER, max_order_pieces INTEGER, min_order_pieces INTEGER, order_multiple_pieces INTEGER, target_stock_pieces INTEGER, channel TEXT, contractor_id INTEGER, supplier_id INTEGER, source_warehouse_id INTEGER, delivery_course_id INTEGER, strategy_key TEXT, arrival_rule TEXT, version INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT, calculation_basis TEXT, order_unit_mode TEXT)');
        $this->basis = ['unit_mode' => 'PIECE', 'review_days' => 7, 'safety_stock_days' => 5, 'batch_rule' => 'mean-demand-times-review-days',
            'suggested_values' => ['reorder_point_pieces' => 100, 'target_stock_pieces' => 150, 'min_order_pieces' => 24, 'max_order_pieces' => 72, 'order_multiple_pieces' => 24],
            'manually_adjusted_fields' => []];
        $row = fn (int $id, int $item, array $extra = []) => array_replace(['id' => $id, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => $item, 'is_configured' => 1, 'is_enabled' => 0,
            'reorder_point_pieces' => 100, 'target_stock_pieces' => 150, 'min_order_pieces' => 24, 'max_order_pieces' => 72, 'order_multiple_pieces' => 24,
            'channel' => 'EOS', 'contractor_id' => 10, 'supplier_id' => 10, 'strategy_key' => 'arrival_adaptive_v1', 'version' => 3, 'order_unit_mode' => 'AUTO',
            'calculation_basis' => json_encode($this->basis)], $extra);
        $db->table('wms_item_reorder_policies')->insert([
            $row(1, 101), $row(2, 102), $row(3, 103),
            $row(4, 104, ['calculation_basis' => json_encode(['manually_adjusted_fields' => ['target_stock_pieces']] + $this->basis)]),
            $row(5, 105, ['strategy_key' => 'threshold_capped_v1']),
            $row(6, 106, ['calculation_basis' => null]),
        ]);
        $guard = Mockery::mock(ReplenishmentGuard::class);
        $guard->shouldReceive('control')->andReturn((object) ['reset_state' => 'COMPLETE']);
        $guard->shouldReceive('event')->andReturnNull();
        $this->app->instance(ReplenishmentGuard::class, $guard);
        foreach ([PalletUnitService::class, ShippingQuantityService::class] as $class) {
            $binding = Mockery::mock($class);
            $binding->shouldReceive('binding')->andReturnNull();
            $this->app->instance($class, $binding);
        }
    }

    private function recommendation(array $values): array
    {
        return ['available' => true, 'values' => $values + ['strategy_key' => 'arrival_adaptive_v1'], 'basis' => $this->basis + ['suggested_values' => $values]];
    }

    public function test_only_recommendation_based_adaptive_policies_without_manual_edits_are_refreshed(): void
    {
        $service = new RecommendationRefreshService(Mockery::mock(SettingsRecommendationService::class), Mockery::mock(PolicyService::class), app(ReplenishmentGuard::class));
        self::assertSame([1, 2, 3], $service->candidates(6)->all());
        self::assertSame([], $service->candidates(6, 99)->all());
    }

    public function test_changes_within_the_guard_are_saved_with_the_saved_conditions_and_larger_ones_are_held(): void
    {
        $recommendations = Mockery::mock(SettingsRecommendationService::class);
        $recommendations->shouldReceive('recommend')->with(6, 2, Mockery::on(fn ($form) => $form['item_id'] === 101 && $form['shipping_unit_mode'] === 'STANDARD' && ! array_key_exists('calculation_basis', $form)),
            ['unit_mode' => 'PIECE', 'review_days' => 7, 'safety_stock_days' => 5, 'existing_reorder_point' => 100])
            ->once()->andReturn($this->recommendation(['reorder_point_pieces' => 130, 'target_stock_pieces' => 190, 'min_order_pieces' => 24, 'max_order_pieces' => 72, 'order_multiple_pieces' => 24]));
        $recommendations->shouldReceive('recommend')->with(6, 2, Mockery::on(fn ($form) => $form['item_id'] === 102), Mockery::any())
            ->once()->andReturn($this->recommendation(['reorder_point_pieces' => 100, 'target_stock_pieces' => 400, 'min_order_pieces' => 24, 'max_order_pieces' => 72, 'order_multiple_pieces' => 24]));
        $recommendations->shouldReceive('recommend')->with(6, 2, Mockery::on(fn ($form) => $form['item_id'] === 103), Mockery::any())
            ->once()->andReturn($this->recommendation(['reorder_point_pieces' => 100, 'target_stock_pieces' => 150, 'min_order_pieces' => 24, 'max_order_pieces' => 72, 'order_multiple_pieces' => 24]));
        $policies = Mockery::mock(PolicyService::class);
        $policies->shouldReceive('saveWithState')->once()
            ->with(6, 2, Mockery::on(fn ($data) => $data['item_id'] === 101 && $data['reorder_point_pieces'] === 130 && $data['target_stock_pieces'] === 190 && $data['version'] === 3), false, 42, Mockery::type('array'))
            ->andReturn(new WmsItemReorderPolicy(['version' => 4]));
        $service = new RecommendationRefreshService($recommendations, $policies, app(ReplenishmentGuard::class));
        $result = $service->refresh(6, null, 2.0, 42, true);
        self::assertSame(['UPDATED' => 1, 'FORECAST_UPDATED' => 0, 'UNCHANGED' => 1, 'HELD' => 1, 'UNAVAILABLE' => 0, 'ERROR' => 0], $result['summary']);
        self::assertSame(['reorder_point_pieces' => ['from' => 100, 'to' => 130], 'target_stock_pieces' => ['from' => 150, 'to' => 190]], $result['rows'][0]['changes']);
        self::assertSame(4, $result['rows'][0]['version']);
        self::assertStringContainsString('×2.67', $result['rows'][1]['reason']);
        self::assertSame('UNCHANGED', $result['rows'][2]['outcome']);
    }

    public function test_a_decrease_that_unwinds_an_event_add_on_is_not_held_and_the_last_report_lists_held_products(): void
    {
        // Policy 101 was saved during 年末年始 with target 450 (baseline 150 + add-on). After the event the recommendation returns to 160:
        // ×0.36 against the saved value, but ×1.07 against the event-free baseline → saved, so the high setting does not linger.
        $eventBasis = $this->basis + ['event_adjustment' => ['baseline' => ['reorder_point_pieces' => 100, 'target_stock_pieces' => 150, 'max_order_pieces' => 72, 'min_order_pieces' => 24]]];
        DB::connection('sakemaru')->table('wms_item_reorder_policies')->where('id', 1)->update(['target_stock_pieces' => 450, 'reorder_point_pieces' => 300, 'calculation_basis' => json_encode($eventBasis)]);
        DB::connection('sakemaru')->table('wms_item_reorder_policies')->whereIn('id', [3, 4, 5, 6])->delete();
        $recommendations = Mockery::mock(SettingsRecommendationService::class);
        $recommendations->shouldReceive('recommend')->with(6, 2, Mockery::on(fn ($form) => $form['item_id'] === 101), Mockery::any())
            ->once()->andReturn($this->recommendation(['reorder_point_pieces' => 105, 'target_stock_pieces' => 160, 'min_order_pieces' => 24, 'max_order_pieces' => 72, 'order_multiple_pieces' => 24]));
        // Policy 102 has no event baseline: the same kind of drop is held as before.
        $recommendations->shouldReceive('recommend')->with(6, 2, Mockery::on(fn ($form) => $form['item_id'] === 102), Mockery::any())
            ->once()->andReturn($this->recommendation(['reorder_point_pieces' => 100, 'target_stock_pieces' => 40, 'min_order_pieces' => 24, 'max_order_pieces' => 72, 'order_multiple_pieces' => 24]));
        $policies = Mockery::mock(PolicyService::class);
        $policies->shouldReceive('saveWithState')->once()->with(6, 2, Mockery::on(fn ($data) => $data['item_id'] === 101 && $data['target_stock_pieces'] === 160), false, 42, Mockery::type('array'))
            ->andReturn(new WmsItemReorderPolicy(['version' => 4]));
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE wms_auto_order_events (id INTEGER PRIMARY KEY, client_id INTEGER, line_id INTEGER, actor_id INTEGER, event TEXT, payload TEXT, created_at TEXT)');
        $db->statement('CREATE TABLE items (id INTEGER, client_id INTEGER, code INTEGER, name TEXT)');
        $db->table('items')->insert([['id' => 102, 'client_id' => 6, 'code' => 102, 'name' => '保留される商品']]);
        $guard = Mockery::mock(ReplenishmentGuard::class);
        $guard->shouldReceive('control')->andReturn((object) ['reset_state' => 'COMPLETE']);
        $guard->shouldReceive('event')->once()->andReturnUsing(fn ($client, $event, $payload) => $db->table('wms_auto_order_events')->insert(['client_id' => $client, 'event' => $event, 'payload' => json_encode($payload), 'created_at' => '2026-12-28 05:10:00']));
        $service = new RecommendationRefreshService($recommendations, $policies, $guard);
        $result = $service->refresh(6, null, 2.0, 42, true);
        self::assertSame(['UPDATED' => 1, 'FORECAST_UPDATED' => 0, 'UNCHANGED' => 0, 'HELD' => 1, 'UNAVAILABLE' => 0, 'ERROR' => 0], $result['summary']);
        self::assertSame('UPDATED', $result['rows'][0]['outcome']);
        self::assertSame('HELD', $result['rows'][1]['outcome']);
        self::assertNull($service->lastReport(6, 99)['held'][0] ?? null);
        $report = $service->lastReport(6, 2);
        self::assertSame('2026-12-28 05:10:00', $report['at']);
        self::assertSame(1, $report['summary']['HELD']);
        self::assertSame([['102', '保留される商品', 'HELD']], array_map(fn ($r) => [$r['item_code'], $r['item_name'], $r['outcome']], $report['held']));
        self::assertStringContainsString('target_stock_pieces が 150 → 40', $report['held'][0]['reason']);
        self::assertNull($service->lastReport(7));
    }

    public function test_same_quantities_with_newer_demand_evidence_are_saved_without_the_change_guard(): void
    {
        $profile = fn (string $anchor) => ['version' => 'weekday-demand-v1', 'anchor_date' => $anchor, 'period' => ['start' => '2026-09-03', 'end' => '2026-09-30'], 'mean' => 10.0,
            'weekdays' => array_fill(0, 7, ['mean' => 10.0, 'days' => 4, 'total' => 40, 'fallback' => false]), 'observed_days' => 28, 'missing_days' => 0, 'warnings' => []];
        DB::connection('sakemaru')->table('wms_item_reorder_policies')->whereIn('id', [2, 3])->delete();
        DB::connection('sakemaru')->table('wms_item_reorder_policies')->where('id', 1)->update(['calculation_basis' => json_encode($this->basis + ['demand_profile' => $profile('2026-10-01')])]);
        $values = ['reorder_point_pieces' => 100, 'target_stock_pieces' => 150, 'min_order_pieces' => 24, 'max_order_pieces' => 72, 'order_multiple_pieces' => 24];
        $fresh = $this->recommendation($values);
        $fresh['basis']['demand_profile'] = $profile('2026-10-29') + ['event_forecast' => ['version' => 'event-forecast-v1', 'start' => '2026-10-29', 'days' => ['2026-10-29' => 30.0], 'events' => []]];
        $recommendations = Mockery::mock(SettingsRecommendationService::class);
        $recommendations->shouldReceive('recommend')->twice()->andReturn($fresh);
        $policies = Mockery::mock(PolicyService::class);
        $policies->shouldReceive('saveWithState')->once()
            ->with(6, 2, Mockery::on(fn ($data) => $data['item_id'] === 101 && $data['target_stock_pieces'] === 150 && $data['version'] === 3), false, 42,
                Mockery::on(fn ($basis) => $basis['demand_profile']['anchor_date'] === '2026-10-29'))
            ->andReturn(new WmsItemReorderPolicy(['version' => 4]));
        $service = new RecommendationRefreshService($recommendations, $policies, app(ReplenishmentGuard::class));
        $dry = $service->refresh(6, null, 2.0, 42, false);
        self::assertSame('FORECAST_UPDATED', $dry['rows'][0]['outcome']);
        self::assertSame([], $dry['rows'][0]['changes']);
        $applied = $service->refresh(6, null, 2.0, 42, true);
        self::assertSame(['UPDATED' => 0, 'FORECAST_UPDATED' => 1, 'UNCHANGED' => 0, 'HELD' => 0, 'UNAVAILABLE' => 0, 'ERROR' => 0], $applied['summary']);
        self::assertSame(4, $applied['rows'][0]['version']);
    }

    public function test_dry_run_never_saves_and_errors_are_reported_per_policy(): void
    {
        $recommendations = Mockery::mock(SettingsRecommendationService::class);
        $recommendations->shouldReceive('recommend')->with(6, 2, Mockery::on(fn ($form) => $form['item_id'] === 101), Mockery::any())
            ->andReturn($this->recommendation(['reorder_point_pieces' => 110, 'target_stock_pieces' => 160, 'min_order_pieces' => 24, 'max_order_pieces' => 72, 'order_multiple_pieces' => 24]));
        $recommendations->shouldReceive('recommend')->with(6, 2, Mockery::on(fn ($form) => $form['item_id'] === 102), Mockery::any())
            ->andThrow(new \RuntimeException('商品または選択倉庫が無効です。'));
        $recommendations->shouldReceive('recommend')->with(6, 2, Mockery::on(fn ($form) => $form['item_id'] === 103), Mockery::any())
            ->andReturn(['available' => false, 'values' => [], 'basis' => ['reason' => '需要の観測日数が不足しています。']]);
        $policies = Mockery::mock(PolicyService::class);
        $policies->shouldNotReceive('saveWithState');
        $service = new RecommendationRefreshService($recommendations, $policies, app(ReplenishmentGuard::class));
        $result = $service->refresh(6, 2, 2.0, 42, false);
        self::assertSame(['UPDATED' => 1, 'FORECAST_UPDATED' => 0, 'UNCHANGED' => 0, 'HELD' => 0, 'UNAVAILABLE' => 1, 'ERROR' => 1], $result['summary']);
        self::assertSame('商品または選択倉庫が無効です。', $result['rows'][1]['reason']);
        self::assertSame('需要の観測日数が不足しています。', $result['rows'][2]['reason']);
        $this->expectException(\RuntimeException::class);
        $service->refresh(6, null, 20.0, 42, false);
    }
}
