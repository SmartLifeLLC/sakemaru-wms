<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\IncomingAvailability;
use App\Services\AutoReplenishment\InventoryProjection;
use App\Services\AutoReplenishment\LogisticsPickingDate;
use App\Services\AutoReplenishment\PolicySimulationService;
use App\Services\AutoReplenishment\ProposalSafety;
use App\Services\AutoReplenishment\SnapshotReader;
use App\Services\AutoReplenishment\StrategyCalculator;
use Mockery;
use Tests\TestCase;

class IncomingAvailabilityTest extends TestCase
{
    public function test_today_is_moved_once_without_changing_total_supply_or_the_input(): void
    {
        $raw = ['stock' => -6, 'incoming' => 80, 'incoming_today' => 60, 'claims' => 10];
        $original = $raw;
        $service = new IncomingAvailability;
        $calculated = $service->forCalculation($raw);
        self::assertSame(54, $calculated['stock']);
        self::assertSame(20, $calculated['incoming']);
        self::assertSame(10, $calculated['claims']);
        self::assertSame(0, $calculated['incoming_today']);
        self::assertSame($original['stock'] + $original['incoming'] + $original['claims'],
            $calculated['stock'] + $calculated['incoming'] + $calculated['claims']);
        self::assertSame($original, $raw);
        self::assertSame($calculated, $service->forCalculation($calculated));
    }

    public function test_future_overdue_unknown_and_sunday_do_not_become_available_today(): void
    {
        $service = new IncomingAvailability;
        self::assertTrue($service->isToday('2026-10-19', '2026-10-19'));
        foreach ([null, '2026-10-18', '2026-10-20'] as $date) {
            self::assertFalse($service->isToday($date, '2026-10-19'));
        }
        self::assertFalse($service->isToday('2026-10-18', '2026-10-18'));
        $raw = ['stock' => 30, 'incoming' => 60, 'claims' => 5];
        self::assertSame($raw, $service->forCalculation($raw));
    }

    public function test_actual_transfer_dates_include_overdue_and_unknown_supply_without_sunday_receipts(): void
    {
        $service = new IncomingAvailability;
        foreach ([null, '2026-10-28', '2026-10-30', '2026-10-31'] as $date) {
            self::assertSame('2026-10-31', $service->transferDate($date, '2026-10-31'));
        }
        self::assertSame('2026-11-02', $service->transferDate(null, '2026-11-01'));
        self::assertSame('2026-11-02', $service->transferDate('2026-11-01', '2026-10-31'));
        self::assertSame('2026-11-04', $service->transferDate('2026-11-04', '2026-10-31'));
        self::assertNull($service->transferDate(null, null));
    }

    public function test_invalid_today_remainder_and_overflow_are_rejected(): void
    {
        foreach ([['stock' => 30, 'incoming' => 20, 'incoming_today' => 21],
            ['stock' => 30, 'incoming' => 20, 'incoming_today' => -1],
            ['stock' => 2147483647, 'incoming' => 1, 'incoming_today' => 1]] as $raw) {
            try {
                (new IncomingAvailability)->forCalculation($raw);
                self::fail('Invalid supply must not be counted.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('当日入荷', $e->getMessage());
            }
        }
    }

    public function test_today_receipt_makes_graph_test_and_batch_calculator_agree_without_writes(): void
    {
        $policy = InventoryProjectionTest::policy();
        foreach ([30, -6] as $stock) {
            $input = InventoryProjectionTest::inputs();
            $input['snapshot']['stock'] = $stock;
            $input['snapshot']['incoming'] = $input['snapshot']['incoming_today'] = 60;
            $input['snapshot']['supply_events'] = [['date' => '2026-10-19', 'pieces' => 60]];
            $snapshot = $input['snapshot'] + ['arrival_date' => '2026-10-21', 'next_arrival_date' => '2026-10-22',
                'route_snapshot' => ['effective_picking_lead_days' => 1]];
            $original = serialize($input);
            $graph = app(InventoryProjection::class)->calculate($policy, $input);
            $batch = app(StrategyCalculator::class)->calculate($policy, $snapshot);
            $reader = Mockery::mock(SnapshotReader::class);
            $reader->shouldReceive('readForSimulation')->andReturn($snapshot);
            $test = (new PolicySimulationService($reader, new ProposalSafety, new LogisticsPickingDate))
                ->simulate(new WmsItemReorderPolicy($policy));
            self::assertSame($batch['phase'], $test['phase']);
            self::assertSame($batch['quantity'], $test['quantity_pieces']);
            self::assertSame($batch['phase'], $graph['days'][0]['phase']);
            self::assertSame($batch['quantity'], $graph['days'][0]['ordered']);
            self::assertSame($batch['formula'], $graph['days'][0]['formula']);
            self::assertSame($stock, $test['stock']);
            self::assertSame(60, $test['incoming_today']);
            self::assertSame($stock + 60, $graph['days'][0]['after_receipt']);
            self::assertSame(0, $graph['days'][0]['pending_at_decision']);
            self::assertSame(60, array_sum(array_column($graph['days'], 'existing_receipt')));
            self::assertSame($original, serialize($input));
            $received = $input;
            $received['snapshot']['stock'] = $stock + 60;
            $received['snapshot']['incoming'] = $received['snapshot']['incoming_today'] = 0;
            $received['snapshot']['supply_events'] = [];
            $after = app(InventoryProjection::class)->calculate($policy, $received);
            self::assertSame(array_column($graph['days'], 'stock'), array_column($after['days'], 'stock'));
            self::assertSame(array_column($graph['days'], 'ordered'), array_column($after['days'], 'ordered'));
            if ($stock === 30) {
                self::assertSame('NO_ORDER', $test['phase']);
                self::assertSame(0, $test['quantity_pieces']);
                $future = $snapshot;
                $future['incoming_today'] = 0;
                self::assertSame(50, app(StrategyCalculator::class)->calculate($policy, $future)['quantity']);
            }
        }
    }

    public function test_threshold_gate_and_explanation_use_today_available_stock(): void
    {
        $policy = InventoryProjectionTest::policy();
        $policy['strategy_key'] = 'threshold_v1';
        $policy['target_stock_pieces'] = 200;
        $snapshot = ['stock' => 90, 'incoming' => 30, 'incoming_today' => 30, 'claims' => 0,
            'packing_unit' => 10, 'quantity_type' => 'CASE', 'capacity_case' => 10];
        $reader = Mockery::mock(SnapshotReader::class);
        $reader->shouldReceive('readForSimulation')->andReturn($snapshot + ['arrival_date' => '2026-10-21',
            'route_snapshot' => ['effective_picking_lead_days' => 1]]);
        $result = (new PolicySimulationService($reader, new ProposalSafety, new LogisticsPickingDate))
            ->simulate(new WmsItemReorderPolicy($policy));
        self::assertSame('NO_ORDER', $result['phase']);
        self::assertSame(90, $result['stock']);
        self::assertStringContainsString('現在庫 120 ≧ 発注点 100', implode('', $result['formula']));
        self::assertStringContainsString('未入荷残 30 → 0', implode('', $result['formula']));
    }

    public function test_preparation_and_saved_policy_preview_reuse_raw_today_supply(): void
    {
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        \Illuminate\Support\Facades\DB::purge('sakemaru');
        $db = \Illuminate\Support\Facades\DB::connection('sakemaru');
        $db->statement('CREATE TABLE wms_auto_order_run_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, policy_id INTEGER, effect_key TEXT, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, channel TEXT, phase TEXT, reason TEXT, quantity_pieces INTEGER, policy_version INTEGER, warehouse_version INTEGER, proposal_snapshot TEXT, created_at TEXT, updated_at TEXT)');
        $db->statement('CREATE TABLE wms_warehouse_auto_order_settings (warehouse_id INTEGER, replenishment_version INTEGER)');
        foreach (['EOS', 'LOGISTICS'] as $i => $channel) {
            $policy = new WmsItemReorderPolicy(InventoryProjectionTest::policy() + ['id' => $i + 1, 'client_id' => 6,
                'warehouse_id' => 1, 'item_id' => 123, 'version' => 1]);
            $policy->channel = $channel;
            $snapshot = InventoryProjectionTest::inputs()['snapshot'] + ['arrival_date' => '2026-10-21',
                'next_arrival_date' => '2026-10-22', 'route_snapshot' => ['effective_picking_lead_days' => 1]];
            $snapshot['stock'] = 30;
            $snapshot['incoming'] = $snapshot['incoming_today'] = 60;
            $reader = Mockery::mock(SnapshotReader::class);
            $reader->shouldReceive('read')->once()->andReturn($snapshot);
            $reader->shouldReceive('readForSimulation')->once()->andReturn($snapshot);
            $execution = new \App\Services\AutoReplenishment\ExecutionService(
                Mockery::mock(\App\Services\AutoReplenishment\ReplenishmentGuard::class), $reader,
                Mockery::mock(\App\Services\AutoReplenishment\BudgetService::class),
                Mockery::mock(\App\Services\AutoReplenishment\ArtifactRecorder::class));
            $run = new \App\Models\WmsAutoOrderRun(['id' => $i + 1, 'client_id' => 6, 'warehouse_id' => 1]);
            $line = $execution->prepare($run, $policy);
            $test = (new PolicySimulationService($reader, new ProposalSafety, new LogisticsPickingDate))->simulate($policy);
            self::assertSame('NO_ORDER', $line->phase);
            self::assertSame($test['phase'], $line->phase);
            self::assertSame(0, (int) $line->quantity_pieces);
            self::assertSame($test['quantity_pieces'], (int) $line->quantity_pieces);
            self::assertSame(30, $line->proposal_snapshot['stock']);
            self::assertSame(60, $line->proposal_snapshot['incoming']);
            self::assertSame(60, $line->proposal_snapshot['incoming_today']);
        }
        $db->disconnect();
    }
}
