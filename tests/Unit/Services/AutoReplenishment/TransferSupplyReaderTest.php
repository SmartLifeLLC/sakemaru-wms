<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoOrder\JxOrderArrivalDateAdjustmentService;
use App\Services\AutoOrder\OrderOutputQuantityResolver;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use App\Services\AutoReplenishment\JxRouteFingerprint;
use App\Services\AutoReplenishment\OrderRouteEligibility;
use App\Services\AutoReplenishment\SnapshotReader;
use App\Services\AutoReplenishment\TransferSupplyReader;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/** Isolated connection only: no migrations, RefreshDatabase or local/business DB writes. */
class TransferSupplyReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('sakemaru');
        $tables = [
            'warehouses' => 'id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, is_virtual INTEGER DEFAULT 0',
            'items' => "id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, code TEXT, name TEXT, capacity_case INTEGER DEFAULT 24, capacity_carton INTEGER DEFAULT 6, is_active INTEGER DEFAULT 1, is_ended INTEGER DEFAULT 0, end_of_sale_type TEXT DEFAULT 'NORMAL', start_of_sale_date TEXT, end_of_sale_date TEXT",
            'stock_transfers' => 'id INTEGER PRIMARY KEY, trade_id INTEGER, client_id INTEGER DEFAULT 6, from_warehouse_id INTEGER DEFAULT 91, to_warehouse_id INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1, is_delivered INTEGER DEFAULT 0',
            'trades' => "id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, is_active INTEGER DEFAULT 1, is_latest INTEGER DEFAULT 1, trade_category TEXT DEFAULT 'STOCK_TRANSFER', trade_direction TEXT DEFAULT 'NORMAL', is_returned INTEGER DEFAULT 0",
            'trade_items' => "id INTEGER PRIMARY KEY, trade_id INTEGER, client_id INTEGER DEFAULT 6, item_id INTEGER DEFAULT 143024, stock_allocation_id INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1, quantity INTEGER DEFAULT 768, quantity_type TEXT DEFAULT 'PIECE', capacity_case INTEGER DEFAULT 24, capacity_carton INTEGER DEFAULT 6, total_piece_quantity INTEGER DEFAULT 768",
            'stock_transfer_lot_allocations' => 'id INTEGER PRIMARY KEY, stock_transfer_id INTEGER, trade_item_id INTEGER, quantity INTEGER, to_real_stock_lot_id INTEGER, is_set_component INTEGER DEFAULT 0',
            'wms_replenishment_effects' => "id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, warehouse_id INTEGER DEFAULT 1, item_id INTEGER DEFAULT 143024, source_type TEXT DEFAULT 'transfer', source_id INTEGER, stock_transfer_id INTEGER, queue_id INTEGER, state TEXT DEFAULT 'OPEN', capacity_case INTEGER DEFAULT 24, capacity_carton INTEGER DEFAULT 6, reconciliation_required INTEGER DEFAULT 0",
            'wms_replenishment_effect_sources' => 'id INTEGER PRIMARY KEY, effect_id INTEGER, incoming_schedule_id INTEGER, stock_applied_pieces INTEGER DEFAULT 0',
            'wms_stock_transfer_candidates' => "id INTEGER PRIMARY KEY, satellite_warehouse_id INTEGER DEFAULT 1, hub_warehouse_id INTEGER DEFAULT 91, item_id INTEGER DEFAULT 143024, transfer_quantity INTEGER DEFAULT 768, quantity_type TEXT DEFAULT 'PIECE', status TEXT DEFAULT 'EXECUTED', created_at TEXT DEFAULT '2026-10-01 00:00:00'",
            'wms_order_incoming_schedules' => "id INTEGER PRIMARY KEY, warehouse_id INTEGER DEFAULT 1, item_id INTEGER DEFAULT 143024, transfer_candidate_id INTEGER, order_candidate_id INTEGER, source_incoming_schedule_id INTEGER, source_warehouse_id INTEGER, stock_transfer_id INTEGER, order_source TEXT DEFAULT 'TRANSFER', expected_quantity INTEGER DEFAULT 768, received_quantity INTEGER DEFAULT 0, quantity_type TEXT DEFAULT 'PIECE', status TEXT DEFAULT 'PENDING', purchase_queue_id INTEGER",
            'stock_transfer_queue' => "id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, action_type TEXT DEFAULT 'CREATE', to_warehouse_code TEXT DEFAULT '1', request_id TEXT, stock_transfer_id INTEGER, items TEXT, status TEXT DEFAULT 'FINISHED', is_success INTEGER DEFAULT 1, created_at TEXT DEFAULT '2026-10-01 00:00:00'",
            'purchase_create_queue' => "id INTEGER PRIMARY KEY, status TEXT DEFAULT 'BEFORE', is_success INTEGER DEFAULT 0, slip_number TEXT",
            'client_settings' => "id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, is_active INTEGER DEFAULT 1, system_date TEXT DEFAULT '2026-10-14'",
            'stock_allocations' => 'id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, code INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1',
            'wms_warehouse_auto_order_settings' => 'warehouse_id INTEGER, replenishment_config TEXT',
            'item_contractors' => 'id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, warehouse_id INTEGER DEFAULT 1, item_id INTEGER DEFAULT 143024, contractor_id INTEGER DEFAULT 10, supplier_id INTEGER DEFAULT 10',
            'contractors' => 'id INTEGER PRIMARY KEY, client_id INTEGER DEFAULT 6, code TEXT, name TEXT, is_active INTEGER DEFAULT 1',
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
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

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

    private function snapshot(): array
    {
        foreach (['client_settings', 'stock_allocations', 'item_contractors', 'real_stocks'] as $table) {
            $this->insert($table, ['id' => 1]);
        }
        $this->insert('contractors', ['id' => 10, 'code' => '10', 'name' => '問屋']);
        $this->insert('suppliers', ['id' => 10]);
        $route = Mockery::mock(OrderRouteEligibility::class);
        $route->shouldReceive('contractorIds')->andReturn([10]);
        $route->shouldReceive('assertEligible');
        $this->app->instance(OrderRouteEligibility::class, $route);
        $dates = Mockery::mock(JxOrderArrivalDateAdjustmentService::class);
        $dates->shouldReceive('earliestArrivalDate')->andReturn(Carbon::parse('2026-10-15'));
        $this->app->instance(JxOrderArrivalDateAdjustmentService::class, $dates);
        $search = Mockery::mock(OrderRegistrationSearchService::class);
        $search->shouldReceive('orderingCodeForItem')->andReturn('0123456789012');
        $this->app->instance(OrderRegistrationSearchService::class, $search);
        $output = Mockery::mock(OrderOutputQuantityResolver::class);
        $output->shouldReceive('resolve')->andReturn(['ordering_unit_quantity' => null]);
        $this->app->instance(OrderOutputQuantityResolver::class, $output);
        $fingerprint = Mockery::mock(JxRouteFingerprint::class);
        $fingerprint->shouldReceive('fingerprint')->andReturn('test-route');
        $this->app->instance(JxRouteFingerprint::class, $fingerprint);

        return (new SnapshotReader)->readForSimulation(new WmsItemReorderPolicy([
            'client_id' => 6, 'warehouse_id' => 1, 'item_id' => 143024, 'contractor_id' => 10, 'supplier_id' => 10, 'channel' => 'EOS',
        ]));
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
}
