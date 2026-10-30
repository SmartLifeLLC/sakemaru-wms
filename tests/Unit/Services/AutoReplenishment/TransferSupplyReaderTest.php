<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoOrder\OrderOutputQuantityResolver;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use App\Services\AutoReplenishment\ArrivalCalendar;
use App\Services\AutoReplenishment\GenerationSchedule;
use App\Services\AutoReplenishment\JxRouteFingerprint;
use App\Services\AutoReplenishment\LogisticsPickingDate;
use App\Services\AutoReplenishment\OrderRouteEligibility;
use App\Services\AutoReplenishment\PolicySimulationService;
use App\Services\AutoReplenishment\ProposalSafety;
use App\Services\AutoReplenishment\SnapshotReader;
use App\Services\AutoReplenishment\TransferSupplyReader;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Isolated connection only: no migrations, RefreshDatabase or local/business DB writes. */
class TransferSupplyReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('sakemaru');
        config(['cache.stores.file' => ['driver' => 'array']]);
        \Illuminate\Support\Facades\Cache::forgetDriver('file');
        $tables = [
            'warehouses' => 'id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, is_virtual INTEGER DEFAULT 0',
            'items' => "id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, code TEXT, name TEXT, capacity_case INTEGER DEFAULT 24, capacity_carton INTEGER DEFAULT 6, is_active INTEGER DEFAULT 1, is_ended INTEGER DEFAULT 0, end_of_sale_type TEXT DEFAULT 'NORMAL', start_of_sale_date TEXT, end_of_sale_date TEXT",
            'item_quantity_information' => 'item_id INTEGER, quantity INTEGER, can_order INTEGER',
            'stock_transfers' => 'id INTEGER PRIMARY KEY, trade_id INTEGER, client_id INTEGER DEFAULT 6, from_warehouse_id INTEGER DEFAULT 91, to_warehouse_id INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1, is_delivered INTEGER DEFAULT 0, delivered_date TEXT, picking_date TEXT, picking_status TEXT',
            'trades' => "id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, is_active INTEGER DEFAULT 1, is_latest INTEGER DEFAULT 1, trade_category TEXT DEFAULT 'STOCK_TRANSFER', trade_direction TEXT DEFAULT 'NORMAL', is_returned INTEGER DEFAULT 0",
            'trade_items' => "id INTEGER PRIMARY KEY, trade_id INTEGER, client_id INTEGER DEFAULT 6, item_id INTEGER DEFAULT 143024, stock_allocation_id INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1, quantity INTEGER DEFAULT 768, quantity_type TEXT DEFAULT 'PIECE', capacity_case INTEGER DEFAULT 24, capacity_carton INTEGER DEFAULT 6, total_piece_quantity INTEGER DEFAULT 768, has_shortage INTEGER DEFAULT 0",
            'stock_transfer_lot_allocations' => 'id INTEGER PRIMARY KEY, stock_transfer_id INTEGER, trade_item_id INTEGER, quantity INTEGER, to_real_stock_lot_id INTEGER, is_set_component INTEGER DEFAULT 0',
            'wms_replenishment_effects' => "id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, warehouse_id INTEGER DEFAULT 1, item_id INTEGER DEFAULT 143024, source_type TEXT DEFAULT 'transfer', source_id INTEGER, stock_transfer_id INTEGER, queue_id INTEGER, state TEXT DEFAULT 'OPEN', ordered_pieces INTEGER DEFAULT 0, capacity_case INTEGER DEFAULT 24, capacity_carton INTEGER DEFAULT 6, reconciliation_required INTEGER DEFAULT 0",
            'wms_replenishment_effect_sources' => 'id INTEGER PRIMARY KEY, effect_id INTEGER, incoming_schedule_id INTEGER, stock_applied_pieces INTEGER DEFAULT 0',
            'wms_stock_transfer_candidates' => "id INTEGER PRIMARY KEY, satellite_warehouse_id INTEGER DEFAULT 1, hub_warehouse_id INTEGER DEFAULT 91, item_id INTEGER DEFAULT 143024, transfer_quantity INTEGER DEFAULT 768, quantity_type TEXT DEFAULT 'PIECE', status TEXT DEFAULT 'EXECUTED', created_at TEXT DEFAULT '2026-10-01 00:00:00'",
            'wms_order_incoming_schedules' => "id INTEGER PRIMARY KEY, warehouse_id INTEGER DEFAULT 1, item_id INTEGER DEFAULT 143024, transfer_candidate_id INTEGER, order_candidate_id INTEGER, source_incoming_schedule_id INTEGER, source_warehouse_id INTEGER, stock_transfer_id INTEGER, order_source TEXT DEFAULT 'TRANSFER', expected_quantity INTEGER DEFAULT 768, received_quantity INTEGER DEFAULT 0, quantity_type TEXT DEFAULT 'PIECE', status TEXT DEFAULT 'PENDING', purchase_queue_id INTEGER, expected_arrival_date TEXT",
            'stock_transfer_queue' => "id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, action_type TEXT DEFAULT 'CREATE', to_warehouse_code TEXT DEFAULT '1', request_id TEXT, stock_transfer_id INTEGER, items TEXT, status TEXT DEFAULT 'FINISHED', is_success INTEGER DEFAULT 1, created_at TEXT DEFAULT '2026-10-01 00:00:00'",
            'purchase_create_queue' => "id INTEGER PRIMARY KEY, status TEXT DEFAULT 'BEFORE', is_success INTEGER DEFAULT 0, slip_number TEXT",
            'client_settings' => "id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, is_active INTEGER DEFAULT 1, system_date TEXT DEFAULT '2026-10-14', default_picking_lead_days INTEGER DEFAULT 1",
            'stock_allocations' => 'id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, code INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1',
            'wms_warehouse_auto_order_settings' => 'warehouse_id INTEGER, replenishment_config TEXT',
            'warehouse_stock_transfer_delivery_courses' => 'from_warehouse_id INTEGER, to_warehouse_id INTEGER, delivery_course_id INTEGER, picking_lead_days INTEGER',
            'item_contractors' => 'id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, warehouse_id INTEGER DEFAULT 1, item_id INTEGER DEFAULT 143024, contractor_id INTEGER DEFAULT 10, supplier_id INTEGER DEFAULT 10',
            'contractors' => 'id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, code TEXT, name TEXT, is_active INTEGER DEFAULT 1',
            'wms_contractor_settings' => 'contractor_id INTEGER, transmission_type TEXT, supply_warehouse_id INTEGER',
            'suppliers' => 'id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6',
            'real_stocks' => 'id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, warehouse_id INTEGER DEFAULT 1, item_id INTEGER DEFAULT 143024, stock_allocation_id INTEGER DEFAULT 1, current_quantity INTEGER DEFAULT 100, reserved_quantity INTEGER DEFAULT 0',
            'wms_order_candidates' => "id INTEGER PRIMARY KEY, warehouse_id INTEGER DEFAULT 1, item_id INTEGER DEFAULT 143024, order_quantity INTEGER DEFAULT 100, quantity_type TEXT DEFAULT 'PIECE', status TEXT DEFAULT 'PENDING'",
            'wms_auto_order_run_lines' => 'id INTEGER PRIMARY KEY, run_id INTEGER, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, generated_at TEXT, phase TEXT, order_candidate_id INTEGER, transfer_candidate_id INTEGER',
            'wms_auto_order_runs' => 'id INTEGER PRIMARY KEY, is_preview INTEGER DEFAULT 0',
            'wms_auto_order_artifact_links' => 'line_id INTEGER, artifact_type TEXT, artifact_id INTEGER',
        ];
        foreach ($tables as $table => $columns) {
            DB::connection('sakemaru')->statement("CREATE TABLE {$table} ({$columns})");
        }
        $this->insert('warehouses', ['id' => 1, 'code' => '1', 'name' => '本店']);
        $this->insert('items', ['id' => 143024, 'code' => '143024', 'name' => 'スーパードライ']);
        $this->insert('item_quantity_information', ['item_id' => 143024, 'quantity' => 1, 'can_order' => 1]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private SnapshotReader $reader;

    private function insert(string $table, array $values): void
    {
        DB::connection('sakemaru')->table($table)->insert($values);
    }

    private function transfer(int $id = 1, array $header = [], array $line = [], array $trade = []): void
    {
        $this->insert('trades', $trade + ['id' => $id]);
        $this->insert('stock_transfers', $header + ['id' => $id, 'trade_id' => $id]);
        $this->insert('trade_items', $line + ['id' => $id, 'trade_id' => $id]);
    }

    private function candidate(int $id = 1, array $values = []): void
    {
        $this->insert('wms_stock_transfer_candidates', $values + ['id' => $id]);
    }

    private function schedule(int $id = 1, array $values = []): void
    {
        $this->insert('wms_order_incoming_schedules', $values + ['id' => $id]);
    }

    private function queue(int $id = 1, array $values = [], array $candidateIds = [1]): void
    {
        $this->insert('stock_transfer_queue', $values + ['id' => $id, 'request_id' => 'transfer-create-'.$candidateIds[0],
            'items' => json_encode(array_map(fn ($c) => ['item_code' => '143024', 'quantity' => 768, 'quantity_type' => 'PIECE', 'note' => '移動候補ID: '.$c], $candidateIds), JSON_UNESCAPED_UNICODE)]);
    }

    private function receipt(int $pieces, int $id = 1, int $lineId = 1): void
    {
        $this->insert('stock_transfer_lot_allocations', ['id' => $id, 'stock_transfer_id' => 1, 'trade_item_id' => $lineId, 'quantity' => $pieces, 'to_real_stock_lot_id' => 100 + $id]);
    }

    private function read(): array
    {
        return (new TransferSupplyReader)->read(6, 1, [143024], [1])[143024];
    }

    public function test_unmapped_actual_transfer_is_incoming_without_a_schedule(): void
    {
        $this->transfer();
        self::assertSame(768, $this->read()['incoming']);
    }

    public function test_case_and_carton_use_document_capacity_instead_of_current_item_capacity(): void
    {
        $this->transfer(1, [], ['quantity' => 5, 'quantity_type' => 'CASE', 'total_piece_quantity' => 120]);
        $this->transfer(2, [], ['quantity' => 3, 'quantity_type' => 'CARTON', 'total_piece_quantity' => 18]);
        DB::connection('sakemaru')->table('items')->update(['capacity_case' => 12, 'capacity_carton' => 2]);
        self::assertSame(138, $this->read()['incoming']);
    }

    public function test_actual_quantity_replaces_multiple_schedules_and_an_approved_candidate(): void
    {
        $this->transfer();
        $this->candidate(1, ['status' => 'APPROVED', 'transfer_quantity' => 1536]);
        $this->schedule(1, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1, 'expected_quantity' => 1536]);
        $this->schedule(2, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1]);
        $r = $this->read();
        self::assertSame(768, $r['incoming']);
        self::assertSame(0, $r['claims']);
    }

    public function test_distinct_transfers_of_the_same_quantity_are_both_counted(): void
    {
        $this->transfer(1);
        $this->transfer(2);
        self::assertSame(1536, $this->read()['incoming']);
    }

    public function test_delivered_transfer_removes_stale_pending_schedule_from_incoming(): void
    {
        $this->transfer(1, ['is_delivered' => 1]);
        $this->candidate();
        $this->schedule(1, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1]);
        self::assertSame(0, $this->read()['incoming']);
    }

    public function test_zero_quantity_is_zero_supply_without_holding(): void
    {
        $this->transfer(1, [], ['quantity' => 0, 'total_piece_quantity' => 0]);
        self::assertSame(0, $this->read()['incoming']);
    }

    public function test_other_warehouses_allocations_inactive_and_non_latest_trades_are_excluded(): void
    {
        $this->transfer(1, ['to_warehouse_id' => 2]);
        $this->transfer(2, [], ['stock_allocation_id' => 2]);
        $this->transfer(3, [], ['is_active' => 0]);
        $this->transfer(4, [], [], ['is_latest' => 0]);
        $this->transfer(5, [], [], ['is_returned' => 1]);
        $this->transfer(6, ['from_warehouse_id' => 1]);
        self::assertSame(0, $this->read()['incoming']);
    }

    public function test_received_lots_are_removed_even_when_other_items_keep_transfer_undelivered(): void
    {
        $this->transfer();
        $this->receipt(768);
        self::assertSame(0, $this->read()['incoming']);
    }

    public function test_partial_receipt_subtracts_only_stock_applied_pieces(): void
    {
        $this->transfer();
        $this->receipt(120);
        $this->schedule(1, ['stock_transfer_id' => 1, 'expected_quantity' => 32, 'received_quantity' => 5, 'quantity_type' => 'CASE', 'status' => 'PARTIAL']);
        self::assertSame(648, $this->read()['incoming']);
    }

    public function test_recorded_receipt_without_stock_application_is_held(): void
    {
        $this->transfer();
        $this->schedule(1, ['stock_transfer_id' => 1, 'received_quantity' => 120, 'status' => 'PARTIAL']);
        self::assertStringContainsString('実在庫へ反映されていません', $this->read()['error']);
    }

    public function test_inconsistent_document_total_is_held(): void
    {
        $this->transfer(1, [], ['quantity' => 5, 'quantity_type' => 'CASE', 'total_piece_quantity' => 5]);
        self::assertStringContainsString('総バラ数が一致しません', $this->read()['error']);
    }

    public function test_quantity_less_than_applied_receipt_is_held(): void
    {
        $this->transfer();
        $this->receipt(769);
        self::assertStringContainsString('受入数量', $this->read()['error']);
    }

    public function test_schedules_count_once_during_asynchronous_creation(): void
    {
        $this->candidate(1, ['status' => 'APPROVED']);
        $this->schedule(1, ['transfer_candidate_id' => 1, 'expected_quantity' => 500]);
        $this->schedule(2, ['transfer_candidate_id' => 1, 'expected_quantity' => 268]);
        $this->queue(1, ['status' => 'BEFORE']);
        $r = $this->read();
        self::assertSame(768, $r['incoming']);
        self::assertSame(0, $r['claims']);
    }

    public function test_finished_creation_queue_resolves_actual_transfer_before_schedule_sync(): void
    {
        $this->transfer(1, [], ['quantity' => 1728, 'total_piece_quantity' => 1728]);
        $this->candidate(1, ['transfer_quantity' => 1536]);
        $this->schedule(1, ['transfer_candidate_id' => 1, 'expected_quantity' => 1536]);
        $this->queue(1, ['stock_transfer_id' => 1]);
        self::assertSame(1728, $this->read()['incoming']);
    }

    public function test_duplicate_or_edited_schedules_do_not_change_the_queued_supply(): void
    {
        $this->candidate();
        $this->schedule(1, ['transfer_candidate_id' => 1, 'expected_quantity' => 100]);
        $this->schedule(2, ['transfer_candidate_id' => 1, 'expected_quantity' => 100]);
        $this->queue(1, ['status' => 'BEFORE']);
        self::assertSame(768, $this->read()['incoming']);
    }

    public function test_hashed_group_request_is_resolved_by_candidate_notes(): void
    {
        $this->transfer();
        $this->candidate();
        $this->schedule(1, ['transfer_candidate_id' => 1]);
        $this->queue(1, ['stock_transfer_id' => 1, 'request_id' => 'transfer-create-group-abcdef123'], [1, 2]);
        self::assertSame(768, $this->read()['incoming']);
    }

    public function test_orphan_schedule_and_missing_actual_transfer_are_held(): void
    {
        $this->schedule(1, ['stock_transfer_id' => 999]);
        self::assertStringContainsString('移動伝票が見つかりません', $this->read()['error']);
    }

    public function test_split_child_inherits_transfer_identity(): void
    {
        $this->transfer();
        $this->candidate();
        $this->schedule(1, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1, 'status' => 'CONFIRMED']);
        $this->schedule(2, ['source_incoming_schedule_id' => 1]);
        self::assertSame(768, $this->read()['incoming']);
    }

    public function test_deleted_schedule_does_not_erase_actual_transfer_supply(): void
    {
        $this->transfer();
        $this->candidate();
        $this->insert('wms_replenishment_effects', ['id' => 1, 'source_id' => 1, 'stock_transfer_id' => 1, 'state' => 'UNKNOWN', 'reconciliation_required' => 1]);
        $this->insert('wms_replenishment_effect_sources', ['id' => 1, 'effect_id' => 1, 'incoming_schedule_id' => 999]);
        self::assertSame(768, $this->read()['incoming']);
    }

    public function test_pending_candidate_without_generated_supply_remains_a_claim(): void
    {
        $this->candidate(1, ['status' => 'PENDING', 'transfer_quantity' => 2, 'quantity_type' => 'CASE']);
        self::assertSame(48, $this->read()['claims']);
        self::assertSame(0, $this->read()['incoming']);
    }

    public function test_different_candidate_and_schedule_transfers_are_held(): void
    {
        $this->transfer();
        $this->transfer(2);
        $this->candidate();
        $this->schedule(1, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1]);
        $this->queue(1, ['stock_transfer_id' => 2]);
        self::assertStringContainsString('移動伝票が一致しません', $this->read()['error']);
    }

    public function test_one_bad_item_does_not_hold_other_items_in_the_same_batch(): void
    {
        $this->transfer(1, [], ['quantity' => -1, 'total_piece_quantity' => -1]);
        $this->insert('items', ['id' => 211351]);
        $this->transfer(2, [], ['item_id' => 211351]);
        $r = (new TransferSupplyReader)->read(6, 1, [143024, 211351], [1]);
        self::assertArrayHasKey('error', $r[143024]);
        self::assertSame(768, $r[211351]['incoming']);
    }

    private function snapshot(array $policyValues = [], array $warehouseConfig = [], ?callable $arrivalResolver = null): array
    {
        foreach (['client_settings', 'stock_allocations', 'item_contractors', 'real_stocks'] as $table) {
            $this->insert($table, ['id' => 1]);
        }
        $this->insert('contractors', ['id' => 10, 'code' => '10', 'name' => '問屋']);
        $this->insert('wms_contractor_settings', ['contractor_id' => 10, 'transmission_type' => ($policyValues['channel'] ?? '') === 'LOGISTICS' ? 'INTERNAL' : 'JX_FINET', 'supply_warehouse_id' => 91]);
        $this->insert('suppliers', ['id' => 10]);
        if ($warehouseConfig !== []) {
            $this->insert('wms_warehouse_auto_order_settings', ['warehouse_id' => 1, 'replenishment_config' => json_encode($warehouseConfig)]);
        }
        if (($policyValues['channel'] ?? '') === 'LOGISTICS') {
            $this->insert('warehouses', ['id' => 91, 'code' => '91', 'name' => '移動元']);
            $this->insert('warehouse_stock_transfer_delivery_courses', ['from_warehouse_id' => 91, 'to_warehouse_id' => 1, 'delivery_course_id' => 101, 'picking_lead_days' => 1]);
        }
        $route = Mockery::mock(OrderRouteEligibility::class);
        $route->shouldReceive('contractorIds')->andReturn([10]);
        $route->shouldReceive('assertEligible');
        $route->shouldReceive('assertReferenceEligible')->with(6, 10);
        $this->app->instance(OrderRouteEligibility::class, $route);
        $dates = Mockery::mock(ArrivalCalendar::class);
        $dates->shouldReceive('referenceArrivalDates')->andReturn(['current' => Carbon::parse('2026-10-15')]);
        if ($arrivalResolver) {
            $dates->shouldReceive('earliestArrivalDate')->andReturnUsing($arrivalResolver);
        } else {
            $dates->shouldReceive('earliestArrivalDate')->andReturn(Carbon::parse('2026-10-15'));
        }
        $this->app->instance(ArrivalCalendar::class, $dates);
        $search = Mockery::mock(OrderRegistrationSearchService::class);
        $search->shouldReceive('orderingCodeForItem')->andReturn('0123456789012');
        $this->app->instance(OrderRegistrationSearchService::class, $search);
        $output = Mockery::mock(OrderOutputQuantityResolver::class);
        $output->shouldReceive('resolve')->andReturn(['ordering_unit_quantity' => null]);
        $this->app->instance(OrderOutputQuantityResolver::class, $output);
        $fingerprint = Mockery::mock(JxRouteFingerprint::class);
        $fingerprint->shouldReceive('fingerprint')->andReturn('test-route');
        $this->app->instance(JxRouteFingerprint::class, $fingerprint);

        $this->reader = new SnapshotReader;

        return $this->reader->readForSimulation(new WmsItemReorderPolicy($policyValues + [
            'client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024, 'contractor_id' => 10, 'supplier_id' => 10, 'channel' => 'EOS',
        ]));
    }

    #[DataProvider('simulationCutoffCases')]
    public function test_simulation_uses_graph_schedule_while_execution_keeps_actual_cutoff(string $businessDate, string $clock, array $config, string $expectedSimulation, string $expectedExecution): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $values = [
            'client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024, 'contractor_id' => 10,
            'supplier_id' => 10, 'channel' => 'EOS', 'strategy_key' => 'arrival_batch_v1',
            'calculation_basis' => ['mean_daily' => 28.61],
            'reorder_point_pieces' => 264, 'target_stock_pieces' => 288,
            'min_order_pieces' => 192, 'max_order_pieces' => 192, 'order_multiple_pieces' => 24,
        ];
        $this->snapshot($values, $config, fn (int $contractor, int $warehouse, Carbon $day) => $day->copy()->addDay());
        DB::connection('sakemaru')->table('client_settings')->update(['system_date' => $businessDate]);
        DB::connection('sakemaru')->table('real_stocks')->update(['current_quantity' => 127]);
        $this->travelTo(Carbon::parse($clock, 'Asia/Tokyo'));
        $calendar = app(ArrivalCalendar::class);
        $calendar->shouldReceive('earliestArrivalDates')->andReturnUsing(fn ($contractor, $warehouse, $days) => array_map(fn ($day) => $day->copy()->addDay(), $days));
        $calendar->shouldReceive('deliveryDays')->andReturn(['weekdays' => range(1, 6), 'holidays' => [], 'sunday_master_enabled' => false]);
        $graph = app(\App\Services\AutoReplenishment\InventoryProjectionCalendar::class)->read(1, 10, 'EOS', $businessDate, $config);
        $policy = new WmsItemReorderPolicy($values);
        $reader = new SnapshotReader;
        $test = (new PolicySimulationService($reader, new ProposalSafety, new LogisticsPickingDate))->simulate($policy);
        self::assertSame($expectedSimulation, $test['arrival_date']);
        self::assertSame($graph['arrivals'][$businessDate], $test['arrival_date']);
        self::assertSame($businessDate.' '.app(GenerationSchedule::class)->executionTimeForDate($businessDate, $config).':00', $test['simulation_at']);
        $snapshot = $reader->readForSimulation($policy, includeSupplyDates: true);
        $projection = app(\App\Services\AutoReplenishment\InventoryProjection::class)->calculate($values, [
            'snapshot' => $snapshot, 'calendar' => $graph,
            'demand' => ['weekdays' => array_fill(0, 7, ['mean' => 28.61])],
            'picking_lead_days' => 0, 'observed_at' => $clock,
        ]);
        self::assertSame($test['simulation_at'], $projection['simulation_at']);
        self::assertSame($test['phase'], $projection['days'][0]['phase']);
        self::assertSame($test['quantity_pieces'], $projection['days'][0]['ordered']);
        self::assertSame($expectedSimulation === Carbon::parse($businessDate)->addDay()->toDateString() ? 0 : 192, $test['quantity_pieces']);
        // readOnly avoids cache/lock side effects but must not opt execution into simulation timing.
        $execution = $reader->read($policy, readOnly: true);
        self::assertSame($expectedExecution, $execution['arrival_date']);
        self::assertArrayNotHasKey('simulation_at', $execution);
        DB::connection('sakemaru')->statement('CREATE TABLE item_prices (id INTEGER, item_id INTEGER, is_active INTEGER, start_date TEXT)');
        $execution = $reader->read($policy);
        self::assertSame($expectedExecution, $execution['arrival_date']);
        self::assertArrayNotHasKey('simulation_at', $execution);
        self::assertSame(Carbon::parse($clock, 'Asia/Tokyo')->toIso8601String(), now()->toIso8601String());
        self::assertSame(0, DB::connection('sakemaru')->table('wms_order_candidates')->count());
        self::assertSame(0, DB::connection('sakemaru')->table('wms_auto_order_run_lines')->count());
    }

    public static function simulationCutoffCases(): array
    {
        return [
            'morning schedule viewed after cutoff' => ['2026-10-14', '2026-10-14 13:30:00', ['execution_time' => '10:00'], '2026-10-15', '2026-10-16'],
            'cutoff schedule viewed before cutoff' => ['2026-10-14', '2026-10-14 12:59:59', ['execution_time' => '13:00'], '2026-10-16', '2026-10-15'],
            'just before configured cutoff' => ['2026-10-14', '2026-10-14 13:00:00', ['execution_time' => '12:59'], '2026-10-15', '2026-10-16'],
            'both after cutoff' => ['2026-10-14', '2026-10-14 13:00:00', ['execution_time' => '13:00'], '2026-10-16', '2026-10-16'],
            'Sunday uses separate time without weekday cutoff' => ['2026-10-18', '2026-10-18 23:30:00', ['execution_time' => '10:00', 'sunday_execution_time' => '22:00'], '2026-10-19', '2026-10-19'],
            'business date differs from wall date' => ['2026-10-14', '2026-10-24 23:30:00', ['execution_time' => '10:00'], '2026-10-15', '2026-10-16'],
        ];
    }

    public function test_fax_editor_snapshot_needs_no_jx_code_and_cannot_be_used_for_execution(): void
    {
        $snapshot = $this->snapshot(['channel' => 'FAX', 'is_enabled' => false]);
        self::assertSame(100, $snapshot['stock']);
        self::assertNull($snapshot['ordering_code']);
        self::assertNull($snapshot['jx_route_hash']);
        $this->expectExceptionMessage('FAX発注は参考計算のみ');
        (new SnapshotReader)->read(new WmsItemReorderPolicy(['channel' => 'FAX', 'is_enabled' => false]));
    }

    public function test_saved_or_enabled_fax_is_rejected_even_by_simulation(): void
    {
        foreach ([false, true] as $saved) {
            $policy = new WmsItemReorderPolicy(['channel' => 'FAX', 'is_enabled' => ! $saved]);
            $policy->exists = $saved;
            try {
                (new SnapshotReader)->readForSimulation($policy);
                self::fail('Only an unsaved disabled FAX editor can be simulated.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('FAX発注は参考計算のみ', $e->getMessage());
            }
        }
    }

    #[DataProvider('masterOrderUnitChannels')]
    public function test_actual_case_shipments_change_logistics_auto_unit_but_not_eos(array $route): void
    {
        $this->transfer(1, ['picking_date' => '2026-10-01', 'picking_status' => 'SHIPPED']);
        $this->candidate();
        $this->schedule(1, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1]);
        $snapshot = $this->snapshot($route);
        self::assertSame(($route['channel'] ?? 'EOS') === 'LOGISTICS' ? 'CASE' : 'PIECE', $snapshot['quantity_type']);
        self::assertSame(($route['channel'] ?? 'EOS') === 'LOGISTICS' ? 24 : 1, $snapshot['packing_unit']);
        self::assertSame(768, $snapshot['incoming']);
    }

    public function test_external_and_transfer_supply_are_combined_and_candidate_is_not_added_twice(): void
    {
        $this->transfer();
        $this->candidate();
        $this->schedule(1, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1]);
        $this->insert('wms_order_candidates', ['id' => 1, 'order_quantity' => 120]);
        $this->schedule(2, ['order_source' => 'MANUAL', 'order_candidate_id' => 1, 'expected_quantity' => 120]);
        $r = $this->snapshot();
        self::assertSame(100, $r['stock']);
        self::assertSame(768, $r['incoming_transfer']);
        self::assertSame(120, $r['incoming_external']);
        self::assertSame(888, $r['incoming']);
        self::assertSame(0, $r['claims']);
    }

    public static function masterOrderUnitChannels(): array
    {
        return ['EOS' => [[]], 'LOGISTICS' => [['channel' => 'LOGISTICS', 'source_warehouse_id' => 91, 'delivery_course_id' => 101]]];
    }

    public function test_distant_external_and_transfer_supply_remains_counted_and_receipts_do_not_double_count(): void
    {
        $this->transfer(1, ['delivered_date' => '2026-12-31'], ['quantity' => 30, 'total_piece_quantity' => 30]);
        $this->schedule(1, ['order_source' => 'MANUAL', 'expected_quantity' => 120, 'expected_arrival_date' => '2026-12-31']);
        $before = $this->snapshot();
        self::assertSame(150, $before['incoming']);
        self::assertSame(250, $before['stock'] + $before['incoming']);
        $policy = new WmsItemReorderPolicy(['client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024,
            'contractor_id' => 10, 'supplier_id' => 10, 'channel' => 'EOS', 'strategy_key' => 'arrival_adaptive_v1',
            'reorder_point_pieces' => 250, 'target_stock_pieces' => 250, 'min_order_pieces' => 1,
            'max_order_pieces' => 150, 'order_multiple_pieces' => 1, 'calculation_basis' => ['mean_daily' => 0.1, 'batch_quantity_pieces' => 30]]);
        $calculate = fn () => (new PolicySimulationService(new SnapshotReader, new ProposalSafety, new LogisticsPickingDate))->simulate($policy);
        self::assertSame('NO_ORDER', $calculate()['phase']);

        // Reproduce the state committed by receipt integration, not a new receipt writer.
        $db = DB::connection('sakemaru');
        $this->insert('purchase_create_queue', ['id' => 1, 'status' => 'FINISHED', 'is_success' => 1, 'slip_number' => 'received']);
        $db->table('wms_order_incoming_schedules')->where('id', 1)->update(['received_quantity' => 24, 'status' => 'PARTIAL', 'purchase_queue_id' => 1]);
        $db->table('real_stocks')->where('id', 1)->update(['current_quantity' => 124]);
        $partial = (new SnapshotReader)->readForSimulation($policy);
        self::assertSame(126, $partial['incoming']);
        self::assertSame(250, $partial['stock'] + $partial['incoming']);
        self::assertSame('NO_ORDER', $calculate()['phase']);

        $db->table('stock_transfers')->where('id', 1)->update(['is_delivered' => 1]);
        $db->table('real_stocks')->where('id', 1)->update(['current_quantity' => 154]);
        $receivedTransfer = (new SnapshotReader)->readForSimulation($policy);
        self::assertSame(0, $receivedTransfer['incoming_transfer']);
        self::assertSame(96, $receivedTransfer['incoming_external']);
        self::assertSame(250, $receivedTransfer['stock'] + $receivedTransfer['incoming']);

        $db->table('wms_order_incoming_schedules')->where('id', 1)->update(['received_quantity' => 120, 'status' => 'CONFIRMED']);
        $db->table('real_stocks')->where('id', 1)->update(['current_quantity' => 250]);
        $complete = (new SnapshotReader)->readForSimulation($policy);
        self::assertSame(0, $complete['incoming']);
        self::assertSame(250, $complete['stock']);
        self::assertSame('NO_ORDER', $calculate()['phase']);
    }

    public function test_master_warning_is_refreshed_and_does_not_change_batch_or_test_quantities(): void
    {
        $values = ['client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024, 'contractor_id' => 10, 'supplier_id' => 10,
            'channel' => 'LOGISTICS', 'source_warehouse_id' => 91, 'delivery_course_id' => 101,
            'strategy_key' => 'threshold_v1', 'reorder_point_pieces' => 200, 'min_order_pieces' => 24,
            'max_order_pieces' => 120, 'order_multiple_pieces' => 24];
        $this->snapshot($values);
        DB::connection('sakemaru')->statement('CREATE TABLE item_prices (id INTEGER, item_id INTEGER, is_active INTEGER, start_date TEXT)');
        DB::connection('sakemaru')->statement('CREATE TABLE item_search_information (id INTEGER, item_id INTEGER, search_string TEXT, is_used_for_ordering INTEGER, is_active INTEGER)');
        DB::connection('sakemaru')->getPdo()->sqliteCreateFunction('regexp', fn ($pattern, $value) => preg_match('/'.$pattern.'/', $value));
        $this->insert('item_search_information', ['id' => 1, 'item_id' => 143024, 'search_string' => '0123456789012', 'is_used_for_ordering' => 1, 'is_active' => 1]);
        $policy = new WmsItemReorderPolicy($values);
        $reader = new SnapshotReader;
        $reader->prime(collect([$policy]));
        self::assertSame([], $reader->read($policy, readOnly: true)['master_warnings']);
        DB::connection('sakemaru')->table('wms_contractor_settings')->update(['supply_warehouse_id' => 92]);
        // Final confirmation clears proposal caches and rereads current masters.
        $snapshot = $reader->read($policy, excludeLineId: 99, readOnly: true);
        $proposal = app(\App\Services\AutoReplenishment\StrategyCalculator::class)->calculate($values, $snapshot);
        $test = (new PolicySimulationService($reader, new ProposalSafety, new LogisticsPickingDate))->simulate($policy);
        self::assertCount(1, $snapshot['master_warnings']);
        self::assertStringContainsString('移動元倉庫', $snapshot['master_warnings'][0]);
        self::assertSame($snapshot['master_warnings'], $test['master_warnings']);
        self::assertSame('READY', $proposal['phase']);
        self::assertSame(120, $proposal['quantity']);
        self::assertSame($proposal['quantity'], $test['quantity_pieces']);
        self::assertSame(91, $policy->source_warehouse_id);
        self::assertSame(0, DB::connection('sakemaru')->table('wms_order_candidates')->count());
    }

    #[DataProvider('masterOrderUnitChannels')]
    public function test_snapshot_uses_master_piece_permission_for_both_order_channels(array $policy): void
    {
        $db = DB::connection('sakemaru');
        $db->table('item_quantity_information')->where('item_id', 143024)->update(['quantity' => 6]);
        $s = $this->snapshot($policy);
        self::assertSame('CASE', $s['quantity_type']);
        self::assertSame(24, $s['packing_unit']);
        self::assertFalse($s['order_unit']['piece_orderable']);
    }

    public function test_negative_stock_can_order_and_is_not_coerced_to_zero_or_unknown(): void
    {
        $this->snapshot();
        DB::connection('sakemaru')->table('real_stocks')->where('id', 1)->update(['current_quantity' => -5]);
        $reader = new SnapshotReader;
        $r = (new \App\Services\AutoReplenishment\PolicySimulationService($reader, new \App\Services\AutoReplenishment\ProposalSafety, new \App\Services\AutoReplenishment\LogisticsPickingDate))->simulate(new WmsItemReorderPolicy([
            'client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024, 'contractor_id' => 10, 'supplier_id' => 10, 'channel' => 'EOS', 'strategy_key' => 'threshold_v1',
            'reorder_point_pieces' => 100, 'min_order_pieces' => 24, 'max_order_pieces' => 120, 'order_multiple_pieces' => 6,
        ]));
        self::assertSame('READY', $r['phase']);
        self::assertSame(-5, $r['stock']);
        self::assertSame(108, $r['quantity_pieces']);
        self::assertStringContainsString('現在庫 -5', implode('', $r['formula']));
        self::assertSame(0, DB::connection('sakemaru')->table('wms_order_candidates')->count());
        self::assertSame(0, DB::connection('sakemaru')->table('wms_auto_order_run_lines')->count());
    }

    public function test_receipt_switches_to_current_stock_without_leaving_stale_schedule_supply(): void
    {
        $this->transfer();
        $this->schedule(1, ['stock_transfer_id' => 1]);
        $before = $this->snapshot();
        self::assertSame(768, $before['incoming']);
        DB::connection('sakemaru')->transaction(function () {
            DB::connection('sakemaru')->table('real_stocks')->where('id', 1)->increment('current_quantity', 768);
            DB::connection('sakemaru')->table('stock_transfers')->where('id', 1)->update(['is_delivered' => 1]);
        });
        $r = (new SnapshotReader)->readForSimulation(new WmsItemReorderPolicy([
            'client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024, 'contractor_id' => 10, 'supplier_id' => 10, 'channel' => 'EOS',
        ]));
        self::assertSame(868, $r['stock']);
        self::assertSame(0, $r['incoming']);
        self::assertSame($before['stock'] + $before['incoming'], $r['stock'] + $r['incoming']);
        self::assertSame(0, DB::connection('sakemaru')->table('wms_auto_order_run_lines')->count());
    }

    public function test_external_partial_receipt_counts_the_remaining_supply_after_stock_application(): void
    {
        $this->insert('purchase_create_queue', ['id' => 1, 'status' => 'FINISHED', 'is_success' => 1, 'slip_number' => 'P1']);
        $this->schedule(1, ['order_source' => 'MANUAL', 'expected_quantity' => 120, 'received_quantity' => 24, 'status' => 'PARTIAL', 'purchase_queue_id' => 1]);
        self::assertSame(96, $this->snapshot()['incoming_external']);
    }

    public function test_external_case_supply_uses_the_order_capacity_and_is_not_readded_as_a_claim(): void
    {
        DB::connection('sakemaru')->table('items')->update(['capacity_case' => 12]);
        $this->insert('wms_order_candidates', ['id' => 1, 'order_quantity' => 2, 'quantity_type' => 'CASE']);
        $this->insert('wms_replenishment_effects', ['id' => 1, 'source_type' => 'order', 'source_id' => 1, 'capacity_case' => 24]);
        $this->schedule(1, ['order_source' => 'AUTO', 'order_candidate_id' => 1, 'expected_quantity' => 2, 'quantity_type' => 'CASE']);
        $this->insert('wms_replenishment_effect_sources', ['id' => 1, 'effect_id' => 1, 'incoming_schedule_id' => 1]);
        $r = $this->snapshot();
        self::assertSame(48, $r['incoming_external']);
        self::assertSame(0, $r['claims']);
    }

    public function test_finished_purchase_is_observed_without_mutating_a_stale_supply_projection(): void
    {
        $this->insert('purchase_create_queue', ['id' => 1, 'status' => 'FINISHED', 'is_success' => 1, 'slip_number' => 'P1']);
        $this->insert('wms_replenishment_effects', ['id' => 1, 'source_type' => 'order', 'source_id' => 99, 'state' => 'RECEIPT_PENDING']);
        $this->schedule(1, ['order_source' => 'MANUAL', 'expected_quantity' => 120, 'received_quantity' => 24, 'status' => 'PARTIAL', 'purchase_queue_id' => 1]);
        $this->insert('wms_replenishment_effect_sources', ['id' => 1, 'effect_id' => 1, 'incoming_schedule_id' => 1]);
        self::assertSame(96, $this->snapshot()['incoming_external']);
        self::assertSame('RECEIPT_PENDING', DB::connection('sakemaru')->table('wms_replenishment_effects')->where('id', 1)->value('state'));
    }

    private function settledSplitMismatch(): void
    {
        $this->insert('wms_order_candidates', ['id' => 1, 'order_quantity' => 2, 'quantity_type' => 'CASE', 'status' => 'EXECUTED']);
        $this->insert('wms_replenishment_effects', ['id' => 1, 'source_type' => 'order', 'source_id' => 1,
            'state' => 'UNKNOWN', 'reconciliation_required' => 1, 'capacity_case' => 4, 'ordered_pieces' => 8]);
        foreach ([1, 2] as $id) {
            $this->insert('purchase_create_queue', ['id' => $id, 'status' => 'FINISHED', 'is_success' => 1, 'slip_number' => 'P'.$id]);
            $this->schedule($id, ['order_source' => 'MANUAL', 'order_candidate_id' => $id === 1 ? 1 : null,
                'source_incoming_schedule_id' => $id === 2 ? 1 : null, 'expected_quantity' => 2,
                'received_quantity' => $id, 'quantity_type' => 'CASE', 'status' => 'TRANSMITTED', 'purchase_queue_id' => $id]);
            $this->insert('wms_replenishment_effect_sources', ['id' => $id, 'effect_id' => 1, 'incoming_schedule_id' => $id]);
        }
    }

    public function test_settled_split_mismatch_warns_without_holding_or_changing_current_stock(): void
    {
        $this->settledSplitMismatch();
        $snapshot = $this->snapshot();
        self::assertSame(100, $snapshot['stock']);
        self::assertSame(0, $snapshot['incoming']);
        self::assertSame(0, $snapshot['claims']);
        self::assertSame(8, $snapshot['supply_warnings'][0]['ordered_pieces']);
        self::assertSame(16, $snapshot['supply_warnings'][0]['expected_pieces']);
        self::assertSame(12, $snapshot['supply_warnings'][0]['received_pieces']);
        self::assertSame($snapshot['supply_warnings'], $this->reader->lastObservation()['supply_warnings']);
        $db = DB::connection('sakemaru');
        $before = [];
        foreach (['real_stocks', 'wms_order_candidates', 'wms_order_incoming_schedules', 'wms_replenishment_effects', 'wms_replenishment_effect_sources', 'purchase_create_queue'] as $table) {
            $before[$table] = $db->table($table)->orderBy('id')->get()->toJson();
        }
        $policy = new WmsItemReorderPolicy(['client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024,
            'contractor_id' => 10, 'supplier_id' => 10, 'channel' => 'EOS', 'strategy_key' => 'threshold_v1',
            'reorder_point_pieces' => 150, 'target_stock_pieces' => 200, 'min_order_pieces' => 1,
            'max_order_pieces' => 100, 'order_multiple_pieces' => 1]);
        $test = app(PolicySimulationService::class)->simulate($policy);
        self::assertNotSame('HOLD', $test['phase']);
        self::assertSame($snapshot['supply_warnings'], $test['supply_warnings']);
        self::assertContains($snapshot['supply_warnings'][0]['message'], $test['formula']);
        // Exercise the production reader, including final-confirmation locking.
        $db->statement('CREATE TABLE item_prices (id INTEGER, item_id INTEGER, is_active INTEGER, start_date TEXT)');
        $execution = $db->transaction(fn () => (new SnapshotReader)->read($policy, excludeLineId: 999));
        self::assertSame($snapshot['supply_warnings'], $execution['supply_warnings']);
        self::assertSame(100, $execution['stock']);
        self::assertSame(0, $execution['incoming']);
        foreach ($before as $table => $json) {
            self::assertSame($json, $db->table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_settled_mismatch_does_not_discard_other_outstanding_orders(): void
    {
        $this->settledSplitMismatch();
        $this->insert('wms_order_candidates', ['id' => 3, 'order_quantity' => 20, 'status' => 'EXECUTED']);
        $this->schedule(3, ['order_source' => 'MANUAL', 'order_candidate_id' => 3, 'expected_quantity' => 20, 'received_quantity' => 0]);
        $snapshot = $this->snapshot();
        self::assertSame(20, $snapshot['incoming']);
        self::assertSame(0, $snapshot['claims']);
        self::assertCount(1, $snapshot['supply_warnings']);
    }

    #[DataProvider('unsettledMismatchCases')]
    public function test_unsettled_or_broken_supply_is_not_treated_as_stock_applied(string $table, array $changes, bool $remove): void
    {
        $this->settledSplitMismatch();
        $q = DB::connection('sakemaru')->table($table)->where('id', 2);
        $remove ? $q->delete() : $q->update($changes);
        $this->expectExceptionMessage('既発注供給の削除・分割・取消の照合が必要');
        $this->snapshot();
    }

    public static function unsettledMismatchCases(): array
    {
        return [
            'purchase processing' => ['purchase_create_queue', ['status' => 'PROCESSING'], false],
            'purchase failed' => ['purchase_create_queue', ['is_success' => 0], false],
            'purchase without document' => ['purchase_create_queue', ['slip_number' => null], false],
            'missing queue' => ['purchase_create_queue', [], true],
            'unreceived remainder' => ['wms_order_incoming_schedules', ['status' => 'PARTIAL'], false],
            'pending receipt' => ['wms_order_incoming_schedules', ['status' => 'PENDING'], false],
            'cancelled receipt' => ['wms_order_incoming_schedules', ['status' => 'CANCELLED'], false],
            'missing receipt' => ['wms_order_incoming_schedules', [], true],
            'different warehouse' => ['wms_order_incoming_schedules', ['warehouse_id' => 2], false],
            'different item' => ['wms_order_incoming_schedules', ['item_id' => 211351], false],
            'zero receipt' => ['wms_order_incoming_schedules', ['received_quantity' => 0], false],
        ];
    }

    public function test_external_partial_receipt_waits_for_actual_stock_application(): void
    {
        $this->schedule(1, ['order_source' => 'MANUAL', 'expected_quantity' => 120, 'received_quantity' => 24, 'status' => 'PARTIAL']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('実在庫へ反映されていません');
        $this->snapshot();
    }

    public function test_external_completed_schedule_with_pending_purchase_does_not_disappear(): void
    {
        $this->insert('purchase_create_queue', ['id' => 1]);
        $this->schedule(1, ['order_source' => 'MANUAL', 'expected_quantity' => 120, 'received_quantity' => 120, 'status' => 'CONFIRMED', 'purchase_queue_id' => 1]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('仕入連携が未完了');
        $this->snapshot();
    }

    /** A purchase linkage that failed for good more than 30 days ago is reported, not a reason to refuse the snapshot. */
    public function test_external_receipt_with_an_old_failed_purchase_linkage_is_reported_instead_of_blocking(): void
    {
        $this->insert('purchase_create_queue', ['id' => 1, 'status' => 'FINISHED', 'is_success' => 0]); // failed (e.g. invalid supplier code), 5 months ago
        $this->schedule(1, ['order_source' => 'MANUAL', 'expected_quantity' => 48, 'received_quantity' => 48, 'status' => 'TRANSMITTED', 'purchase_queue_id' => 1, 'expected_arrival_date' => '2026-05-08']);
        $snapshot = $this->snapshot();
        self::assertSame(0, $snapshot['incoming_external']);
        self::assertSame(['count' => 1, 'pieces' => 48, 'latest_arrival' => '2026-05-08'], $this->reader->lastObservation()['stale_purchase_linkages']);
        // The same failure within the last 30 days, or a queue still running, keeps blocking.
        $this->schedule(2, ['order_source' => 'MANUAL', 'expected_quantity' => 24, 'received_quantity' => 24, 'status' => 'TRANSMITTED', 'purchase_queue_id' => 1, 'expected_arrival_date' => '2026-10-01']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('仕入連携が未完了');
        (new SnapshotReader)->readForSimulation(new WmsItemReorderPolicy(['client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024, 'contractor_id' => 10, 'supplier_id' => 10, 'channel' => 'EOS']));
    }

    public function test_external_duplicate_schedules_are_held_instead_of_counting_the_same_order_twice(): void
    {
        $this->insert('wms_order_candidates', ['id' => 1, 'order_quantity' => 120]);
        $this->schedule(1, ['order_source' => 'MANUAL', 'order_candidate_id' => 1, 'expected_quantity' => 120]);
        $this->schedule(2, ['order_source' => 'MANUAL', 'order_candidate_id' => 1, 'expected_quantity' => 120]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('重複・分割内容');
        $this->snapshot();
    }

    public function test_external_separate_orders_of_equal_quantities_are_not_deduplicated(): void
    {
        foreach ([1, 2] as $id) {
            $this->insert('wms_order_candidates', ['id' => $id, 'order_quantity' => 120]);
            $this->schedule($id, ['order_source' => 'MANUAL', 'order_candidate_id' => $id, 'expected_quantity' => 120]);
        }
        self::assertSame(240, $this->snapshot()['incoming_external']);
    }

    public function test_external_split_schedules_sum_to_one_order_without_losing_the_remainder(): void
    {
        $this->insert('wms_order_candidates', ['id' => 1, 'order_quantity' => 120]);
        $this->schedule(1, ['order_source' => 'MANUAL', 'order_candidate_id' => 1, 'expected_quantity' => 80, 'received_quantity' => 80, 'status' => 'CONFIRMED']);
        $this->schedule(2, ['order_source' => 'MANUAL', 'order_candidate_id' => 1, 'expected_quantity' => 40, 'source_incoming_schedule_id' => 1]);
        $r = $this->snapshot();
        self::assertSame(40, $r['incoming_external']);
        self::assertSame(0, $r['claims']);
    }

    public function test_external_candidate_link_to_another_warehouse_is_held(): void
    {
        $this->insert('wms_order_candidates', ['id' => 1, 'warehouse_id' => 2]);
        $this->schedule(1, ['order_source' => 'MANUAL', 'order_candidate_id' => 1]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('発注候補の倉庫・商品が一致しません');
        $this->snapshot();
    }

    public function test_cancelled_transfer_does_not_leave_a_schedule_or_candidate_as_supply(): void
    {
        $this->transfer(1, ['is_active' => 0]);
        $this->candidate(1, ['status' => 'APPROVED']);
        $this->schedule(1, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1]);
        $r = $this->read();
        self::assertSame(0, $r['incoming']);
        self::assertSame(0, $r['claims']);
    }

    public function test_deleted_schedule_with_conflicting_generation_evidence_is_held(): void
    {
        $this->transfer();
        $this->transfer(2);
        $this->candidate();
        $this->insert('wms_replenishment_effects', ['id' => 1, 'source_id' => 1, 'stock_transfer_id' => 1]);
        $this->queue(1, ['stock_transfer_id' => 2]);
        self::assertStringContainsString('移動伝票が一致しません', $this->read()['error']);
    }

    public function test_split_cycle_is_held_instead_of_recounting_the_same_supply(): void
    {
        $this->schedule(1, ['source_incoming_schedule_id' => 2]);
        $this->schedule(2, ['source_incoming_schedule_id' => 1]);
        self::assertStringContainsString('循環', $this->read()['error']);
    }

    public function test_simulation_keeps_all_reads_in_one_transaction(): void
    {
        $levels = [];
        DB::connection('sakemaru')->beforeExecuting(function ($sql) use (&$levels) {
            if (preg_match('/^\s*select\b/i', $sql)) {
                $levels[] = DB::connection('sakemaru')->transactionLevel();
            }
        });
        self::assertSame(100, $this->snapshot()['stock']);
        self::assertNotEmpty($levels);
        self::assertSame([1], array_values(array_unique($levels)));
    }

    #[DataProvider('nextEosArrivalCases')]
    public function test_adaptive_snapshot_resolves_next_eos_arrival_from_scheduled_time(string $now, array $config, array $expectedOrderDays, string $expectedArrival): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $this->travelTo(Carbon::parse($now, 'Asia/Tokyo'));
        $orderDays = [];
        $snapshot = $this->snapshot(['strategy_key' => 'arrival_adaptive_v1'], $config,
            function (int $contractor, int $warehouse, Carbon $orderDay) use (&$orderDays) {
                self::assertSame(10, $contractor);
                self::assertSame(1, $warehouse);
                $orderDays[] = $orderDay->toDateString();

                return $orderDay->copy()->addDay();
            });
        self::assertSame($expectedOrderDays, $orderDays);
        self::assertSame($expectedArrival, $snapshot['next_arrival_date']);
        self::assertSame(0, DB::connection('sakemaru')->table('wms_order_candidates')->count());
        self::assertSame(0, DB::connection('sakemaru')->table('wms_auto_order_run_lines')->count());
    }

    public static function nextEosArrivalCases(): array
    {
        return [
            'next scheduled afternoon passes the existing weekday cutoff' => [
                '2026-10-14 10:00:00', ['weekdays' => [3, 4], 'execution_time' => '14:00', 'sunday_execution_time' => '22:00'],
                ['2026-10-15', '2026-10-16'], '2026-10-17',
            ],
            'current afternoon does not move the next morning order' => [
                '2026-10-14 14:00:00', ['weekdays' => [3, 4], 'execution_time' => '10:00', 'sunday_execution_time' => '22:00'],
                ['2026-10-14', '2026-10-15'], '2026-10-16',
            ],
            'Sunday evening retains the existing Sunday exception' => [
                '2026-10-14 10:00:00', ['weekdays' => [3, 0], 'execution_time' => '10:00', 'sunday_execution_time' => '22:00'],
                ['2026-10-14', '2026-10-18'], '2026-10-19',
            ],
        ];
    }

    public function test_adaptive_logistics_snapshot_keeps_existing_arrival_rules_after_disabled_weekdays(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $this->travelTo(Carbon::parse('2026-10-14 14:00:00', 'Asia/Tokyo'));
        $orderDays = [];
        $snapshot = $this->snapshot([
            'strategy_key' => 'arrival_adaptive_v1', 'channel' => 'LOGISTICS',
            'source_warehouse_id' => 91, 'delivery_course_id' => 101,
        ], ['weekdays' => [3], 'execution_time' => '14:00'],
            function (int $contractor, int $warehouse, Carbon $orderDay) use (&$orderDays) {
                $orderDays[] = $orderDay->toDateString();

                // Represent the existing route/calendar resolver selecting the second day.
                return $orderDay->copy()->addDays(2);
            });
        self::assertSame(['2026-10-14', '2026-10-21'], $orderDays);
        self::assertSame('2026-10-16', $snapshot['arrival_date']);
        self::assertSame('2026-10-23', $snapshot['next_arrival_date']);
        self::assertSame(1, $snapshot['route_snapshot']['effective_picking_lead_days']);
        self::assertSame('2026-10-15', (new LogisticsPickingDate)->resolve($snapshot['arrival_date'], 1));
        self::assertSame(0, DB::connection('sakemaru')->table('stock_transfers')->count());
    }

    public function test_changed_recommendation_schedule_holds_simulation_without_creating_orders(): void
    {
        $config = ['weekdays' => [1, 2, 3, 4, 5], 'execution_time' => '10:00', 'sunday_execution_time' => '22:00'];
        $this->snapshot([], $config);
        $saved = $config;
        $saved['weekdays'] = range(0, 6);
        $policy = new WmsItemReorderPolicy([
            'client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024, 'contractor_id' => 10,
            'supplier_id' => 10, 'channel' => 'EOS', 'strategy_key' => 'arrival_adaptive_v1',
            'calculation_basis' => ['mean_daily' => 1, 'batch_quantity_pieces' => 6,
                'review_schedule_fingerprint' => (new GenerationSchedule)->fingerprint($saved)],
            'reorder_point_pieces' => 20, 'target_stock_pieces' => 16,
            'min_order_pieces' => 1, 'max_order_pieces' => 12, 'order_multiple_pieces' => 1,
        ]);
        $result = (new PolicySimulationService(new SnapshotReader, new ProposalSafety, new LogisticsPickingDate))->simulate($policy);
        self::assertSame('HOLD', $result['phase']);
        self::assertSame(0, $result['quantity_pieces']);
        self::assertStringContainsString('実行曜日・時刻が変更', $result['reason']);
        self::assertStringContainsString('再計算して保存', $result['reason']);
        self::assertSame(0, DB::connection('sakemaru')->table('wms_order_candidates')->count());
        self::assertSame(0, DB::connection('sakemaru')->table('wms_auto_order_run_lines')->count());
    }

    public function test_unchanged_serialized_schedule_accepts_new_basis_and_old_adaptive_basis_stays_usable(): void
    {
        $config = ['weekdays' => [3, 4], 'execution_time' => '10:00'];
        $serialized = ['weekdays' => ['4', '3', '4'], 'execution_time' => '10:00'];
        $snapshot = $this->snapshot([
            'strategy_key' => 'arrival_adaptive_v1',
            'calculation_basis' => ['review_schedule_fingerprint' => (new GenerationSchedule)->fingerprint($serialized)],
        ], $config, fn (int $contractor, int $warehouse, Carbon $orderDay) => $orderDay->copy()->addDay());
        self::assertSame('2026-10-16', $snapshot['next_arrival_date']);
        $old = (new SnapshotReader)->readForSimulation(new WmsItemReorderPolicy([
            'client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024, 'contractor_id' => 10,
            'supplier_id' => 10, 'channel' => 'EOS', 'strategy_key' => 'arrival_adaptive_v1',
            'calculation_basis' => ['mean_daily' => 1, 'batch_quantity_pieces' => 6],
        ]));
        self::assertSame('2026-10-16', $old['next_arrival_date']);
    }

    #[DataProvider('legacySnapshotStrategies')]
    public function test_legacy_strategy_does_not_load_next_arrival_or_validate_new_schedule(string $strategy): void
    {
        $calls = 0;
        $snapshot = $this->snapshot([
            'strategy_key' => $strategy,
            'calculation_basis' => ['review_schedule_fingerprint' => 'stale'],
        ], ['weekdays' => []], function () use (&$calls) {
            $calls++;

            return Carbon::parse('2026-10-15');
        });
        self::assertSame(1, $calls);
        self::assertSame('2026-10-15', $snapshot['arrival_date']);
        self::assertNull($snapshot['next_arrival_date']);
    }

    public static function legacySnapshotStrategies(): array
    {
        return [['arrival_batch_v1'], ['threshold_v1'], ['threshold_capped_v1']];
    }

    #[DataProvider('unavailableNextArrivalCases')]
    public function test_missing_next_arrival_only_holds_small_orders_that_need_the_waiting_decision(int $stock, string $phase, int $quantity): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Asia/Tokyo'));
        $values = [
            'client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024, 'contractor_id' => 10,
            'supplier_id' => 10, 'channel' => 'EOS', 'strategy_key' => 'arrival_adaptive_v1',
            'calculation_basis' => ['mean_daily' => 1, 'batch_quantity_pieces' => 6],
            'reorder_point_pieces' => 20, 'target_stock_pieces' => 16,
            'min_order_pieces' => 1, 'max_order_pieces' => 12, 'order_multiple_pieces' => 1,
        ];
        $snapshot = $this->snapshot($values, ['weekdays' => [3, 4], 'execution_time' => '10:00'],
            fn (int $contractor, int $warehouse, Carbon $orderDay): ?Carbon => $orderDay->toDateString() === '2026-10-14'
                ? Carbon::parse('2026-10-15') : null);
        self::assertSame('2026-10-15', $snapshot['arrival_date']);
        self::assertNull($snapshot['next_arrival_date']);
        DB::connection('sakemaru')->table('real_stocks')->where('id', 1)->update(['current_quantity' => $stock]);
        $result = (new PolicySimulationService(new SnapshotReader, new ProposalSafety, new LogisticsPickingDate))
            ->simulate(new WmsItemReorderPolicy($values));
        self::assertSame($phase, $result['phase']);
        self::assertSame($quantity, $result['quantity_pieces']);
        self::assertSame('2026-10-15', $result['arrival_date']);
        self::assertNull($result['next_arrival_date']);
        if ($phase === 'HOLD') {
            self::assertStringContainsString('次回の入荷予定日を確認できません', $result['reason']);
        }
        self::assertSame(0, DB::connection('sakemaru')->table('wms_order_candidates')->count());
        self::assertSame(0, DB::connection('sakemaru')->table('wms_auto_order_run_lines')->count());
    }

    public static function unavailableNextArrivalCases(): array
    {
        return [
            'usual batch can use the valid current arrival' => [0, 'READY', 12],
            'small quantity cannot decide whether waiting is safe' => [16, 'HOLD', 0],
        ];
    }

    public function test_dated_projection_uses_transfer_remainder_once_and_keeps_external_supply(): void
    {
        $this->transfer(1, ['delivered_date' => '2026-10-20']);
        $this->receipt(24);
        $this->candidate();
        $this->schedule(1, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1, 'expected_arrival_date' => '2026-10-19']);
        $this->schedule(2, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1, 'expected_arrival_date' => '2026-10-21']);
        $this->schedule(3, ['order_source' => 'MANUAL', 'expected_quantity' => 50, 'expected_arrival_date' => '2026-10-22']);
        $normal = $this->snapshot();
        self::assertArrayNotHasKey('supply_events', $normal);
        $policy = new WmsItemReorderPolicy(['client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024,
            'contractor_id' => 10, 'supplier_id' => 10, 'channel' => 'EOS', 'strategy_key' => 'threshold_v1']);
        $dated = (new SnapshotReader)->readForSimulation($policy, includeSupplyDates: true);
        self::assertSame(794, $dated['incoming']);
        self::assertSame($normal['incoming'], array_sum(array_column($dated['supply_events'], 'pieces')));
        self::assertSame([
            ['source' => 'transfer', 'date' => '2026-10-20', 'pieces' => 744],
            ['source' => 'external', 'date' => '2026-10-22', 'pieces' => 50],
        ], $dated['supply_events']);
        DB::connection('sakemaru')->table('stock_transfers')->where('id', 1)->update(['is_delivered' => 1]);
        $done = (new SnapshotReader)->readForSimulation($policy, includeSupplyDates: true);
        self::assertSame(50, $done['incoming']);
        self::assertCount(1, $done['supply_events']);
    }

    public function test_pending_transfer_split_dates_do_not_invent_multiple_arrivals(): void
    {
        $this->candidate();
        $this->queue(1, ['status' => 'BEFORE']);
        $this->schedule(1, ['transfer_candidate_id' => 1, 'expected_arrival_date' => '2026-10-20']);
        $this->schedule(2, ['transfer_candidate_id' => 1, 'expected_arrival_date' => '2026-10-21']);
        $r = (new TransferSupplyReader)->read(6, 1, [143024], [1], includeDates: true)[143024];
        self::assertSame(768, $r['incoming']);
        self::assertSame([['source' => 'transfer', 'date' => null, 'pieces' => 768]], $r['supply_events']);
    }

    public function test_snapshot_counts_today_transfer_remainder_and_external_supply_without_stock_updates(): void
    {
        $this->transfer(1, ['delivered_date' => '2026-10-14']);
        $this->receipt(24);
        $this->candidate();
        $this->schedule(1, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1, 'expected_arrival_date' => '2026-10-14']);
        $this->schedule(2, ['stock_transfer_id' => 1, 'transfer_candidate_id' => 1, 'expected_arrival_date' => '2026-10-14']);
        $this->schedule(3, ['order_source' => 'MANUAL', 'expected_quantity' => 50, 'expected_arrival_date' => '2026-10-14']);
        $normal = $this->snapshot();
        self::assertSame(794, $normal['incoming']);
        self::assertSame(794, $normal['incoming_today']);
        self::assertSame(100, $normal['stock']);
        self::assertArrayNotHasKey('supply_events', $normal);
        $policy = new WmsItemReorderPolicy(['client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024,
            'contractor_id' => 10, 'supplier_id' => 10, 'channel' => 'EOS', 'strategy_key' => 'threshold_v1']);
        $dated = (new SnapshotReader)->readForSimulation($policy, includeSupplyDates: true);
        self::assertSame($normal['incoming_today'], array_sum(array_column($dated['supply_events'], 'pieces')));
        $calculated = app(\App\Services\AutoReplenishment\IncomingAvailability::class)->forCalculation($normal);
        self::assertSame(894, $calculated['stock']);
        self::assertSame(0, $calculated['incoming']);
        self::assertSame(100, (int) DB::connection('sakemaru')->table('real_stocks')->value('current_quantity'));
        self::assertSame(0, (int) DB::connection('sakemaru')->table('wms_order_incoming_schedules')->where('id', 3)->value('received_quantity'));
    }

    public function test_pending_transfer_today_is_one_supply_and_ambiguous_dates_remain_pending(): void
    {
        $this->candidate();
        $this->queue(1, ['status' => 'BEFORE']);
        $this->schedule(1, ['transfer_candidate_id' => 1, 'expected_arrival_date' => '2026-10-14']);
        $this->schedule(2, ['transfer_candidate_id' => 1, 'expected_arrival_date' => '2026-10-14']);
        $r = (new TransferSupplyReader)->read(6, 1, [143024], [1], availabilityDate: '2026-10-14')[143024];
        self::assertSame(768, $r['incoming']);
        self::assertSame(768, $r['incoming_today']);
        self::assertArrayNotHasKey('supply_events', $r);
        DB::connection('sakemaru')->table('wms_order_incoming_schedules')->where('id', 2)->update(['expected_arrival_date' => '2026-10-15']);
        $r = (new TransferSupplyReader)->read(6, 1, [143024], [1], availabilityDate: '2026-10-14')[143024];
        self::assertSame(768, $r['incoming']);
        self::assertSame(0, $r['incoming_today']);
    }

    public function test_sunday_transfer_is_kept_incoming_instead_of_available(): void
    {
        $this->transfer(1, ['delivered_date' => '2026-10-18']);
        $r = (new TransferSupplyReader)->read(6, 1, [143024], [1], availabilityDate: '2026-10-18')[143024];
        self::assertSame(768, $r['incoming']);
        self::assertSame(0, $r['incoming_today']);
    }

    public function test_overdue_actual_transfer_without_schedule_is_plotted_once_and_matches_batch(): void
    {
        $this->transfer(1, ['delivered_date' => '2026-10-16']);
        $this->receipt(24);
        $supply = (new TransferSupplyReader)->read(6, 1, [143024], [1], includeDates: true, availabilityDate: '2026-10-19')[143024];
        self::assertSame(744, $supply['incoming']);
        self::assertSame(744, $supply['incoming_today']);
        self::assertSame([['source' => 'transfer', 'date' => '2026-10-19', 'pieces' => 744]], $supply['supply_events']);
        $policy = array_replace(InventoryProjectionTest::policy(), ['reorder_point_pieces' => 1500,
            'target_stock_pieces' => 2000, 'min_order_pieces' => 24, 'max_order_pieces' => 768, 'order_multiple_pieces' => 24]);
        $input = InventoryProjectionTest::inputs();
        $input['snapshot'] = array_replace($input['snapshot'], $supply, ['packing_unit' => 24, 'capacity_case' => 24]);
        $graph = app(\App\Services\AutoReplenishment\InventoryProjection::class)->calculate($policy, $input);
        $snapshot = $input['snapshot'] + ['arrival_date' => '2026-10-21', 'next_arrival_date' => '2026-10-22',
            'route_snapshot' => ['effective_picking_lead_days' => 1]];
        $batch = app(\App\Services\AutoReplenishment\StrategyCalculator::class)->calculate($policy, $snapshot);
        $reader = Mockery::mock(SnapshotReader::class);
        $reader->shouldReceive('readForSimulation')->andReturn($snapshot);
        $simulation = (new PolicySimulationService($reader, new ProposalSafety, new LogisticsPickingDate))
            ->simulate(new WmsItemReorderPolicy($policy));
        self::assertSame(834, $graph['days'][0]['after_receipt']);
        self::assertSame(0, $graph['days'][0]['pending_at_decision']);
        self::assertSame(744, array_sum(array_column($graph['days'], 'existing_receipt')));
        self::assertSame($batch['quantity'], $graph['days'][0]['ordered']);
        self::assertSame($batch['formula'], $graph['days'][0]['formula']);
        self::assertSame($batch['quantity'], $simulation['quantity_pieces']);
        self::assertSame($batch['phase'], $simulation['phase']);
        self::assertSame(0, (int) DB::connection('sakemaru')->table('stock_transfers')->value('is_delivered'));
        self::assertSame(24, (int) DB::connection('sakemaru')->table('stock_transfer_lot_allocations')->sum('quantity'));

        // Receipt completion moves the same pieces into stock, never into stock AND supply.
        $this->receipt(744, 2);
        $received = (new TransferSupplyReader)->read(6, 1, [143024], [1], includeDates: true, availabilityDate: '2026-10-19')[143024];
        self::assertSame(0, $received['incoming']);
        $input['snapshot'] = array_replace($input['snapshot'], $received, ['stock' => 834]);
        $after = app(\App\Services\AutoReplenishment\InventoryProjection::class)->calculate($policy, $input);
        self::assertSame(array_column($graph['days'], 'stock'), array_column($after['days'], 'stock'));
        self::assertSame($graph['orders'], $after['orders']);
    }

    public function test_undated_actual_transfer_is_included_but_unexecuted_candidate_is_not_received(): void
    {
        $this->transfer();
        $this->candidate(2, ['status' => 'PENDING']);
        $r = (new TransferSupplyReader)->read(6, 1, [143024], [1], includeDates: true, availabilityDate: '2026-10-19')[143024];
        self::assertSame(768, $r['incoming_today']);
        self::assertSame(768, $r['claims']);
        self::assertSame([['source' => 'transfer', 'date' => '2026-10-19', 'pieces' => 768]], $r['supply_events']);
    }

    public function test_partial_external_receipt_counts_only_today_remainder_and_preserves_real_stock(): void
    {
        $this->schedule(1, ['order_source' => 'MANUAL', 'expected_quantity' => 50, 'received_quantity' => 10,
            'expected_arrival_date' => '2026-10-14', 'status' => 'PARTIAL']);
        $this->insert('wms_replenishment_effects', ['id' => 1, 'source_type' => 'order', 'source_id' => 100]);
        $this->insert('wms_replenishment_effect_sources', ['id' => 1, 'effect_id' => 1, 'incoming_schedule_id' => 1, 'stock_applied_pieces' => 10]);
        $raw = $this->snapshot();
        self::assertSame(40, $raw['incoming']);
        self::assertSame(40, $raw['incoming_today']);
        $calculated = app(\App\Services\AutoReplenishment\IncomingAvailability::class)->forCalculation($raw);
        self::assertSame(140, $calculated['stock']);
        self::assertSame(0, $calculated['incoming']);
        self::assertSame(100, (int) DB::connection('sakemaru')->table('real_stocks')->value('current_quantity'));
        self::assertSame(10, (int) DB::connection('sakemaru')->table('wms_order_incoming_schedules')->value('received_quantity'));
    }
}
