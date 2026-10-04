<?php

namespace Tests\Unit\Filament;

use App\Filament\Pages\WmsOrderRegistration;
use App\Filament\Pages\WmsOrderRegistrationHq;
use App\Filament\Resources\WmsStockTransferCandidates\Pages\ListWmsStockTransferCandidates;
use App\Services\AutoOrder\HqOrderRegistrationReferenceService;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use Carbon\Carbon;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use ReflectionProperty;
use Tests\TestCase;

/** Runs the real page queries against disposable, in-memory fixtures only. */
class StatisticsReadOptimizationTest extends TestCase
{
    private StatisticsReadFixtureConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $this->assertSame('', $pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file']);
        $pdo->sqliteCreateFunction('greatest', fn (...$values) => max($values));
        $pdo->sqliteCreateFunction('concat_ws', fn ($separator, ...$values) => implode($separator, array_filter($values, fn ($value) => $value !== null)));
        $pdo->sqliteCreateFunction('regexp', fn ($pattern, $value) => preg_match('/'.$pattern.'/', (string) $value));

        $this->connection = new StatisticsReadFixtureConnection($pdo, ':memory:', '', ['name' => 'sakemaru', 'database' => ':memory:']);
        DB::purge('sakemaru');
        DB::extend('statistics_fixture', fn () => $this->connection);
        config(['database.default' => 'sakemaru', 'database.connections.sakemaru' => ['driver' => 'statistics_fixture', 'database' => ':memory:']]);
        $this->assertSame($this->connection, DB::connection('sakemaru'));

        Carbon::setTestNow('2026-10-04 12:00:00');
        $this->createFixture();
        $search = Mockery::mock(OrderRegistrationSearchService::class)->makePartial();
        $search->shouldReceive('incomingWarehouseId')->andReturnUsing(fn ($warehouseId) => $warehouseId);
        $search->shouldReceive('jxContractorIds')->andReturn([10]);
        $search->shouldReceive('defaultExpectedArrivalDate')->andReturn('2026-10-05');
        $this->app->instance(OrderRegistrationSearchService::class, $search);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    public function test_hq_search_preserves_all_fields_and_reads_weekly_sales_once(): void
    {
        $legacy = $this->hqPage(true);
        $this->connection->dailyQueries = [];
        $before = $legacy->searchItemsForModal(999, perPage: 100);
        $this->assertCount(2, $this->connection->dailyQueries);

        $page = $this->hqPage();
        $this->connection->dailyQueries = [];
        $after = $page->searchItemsForModal(999, perPage: 100);
        $this->assertCount(1, $this->connection->dailyQueries);
        $this->assertSame($before, $after);
        $rows = collect($after['data'])->keyBy('id');
        $this->assertSame(21, $rows[1]['sales_week1_qty']);
        $this->assertSame(0, $rows[2]['sales_week1_qty']);
        $this->assertSame(-5, $rows[3]['sales_week1_qty']);
        $this->assertSame(7, $rows[1]['sales_week2_qty']);
        $this->assertSame(5, $rows[1]['sales_week3_qty']);
        $this->assertSame(21, $rows[1]['previous_month_sales_qty']);

        // No persistent cache: the next search sees changes made after the first search.
        $this->connection->table('stats_item_warehouse_daily_sales')->where('warehouse_id', 91)->where('item_id', 1)->where('business_date', '2026-10-04')->update(['shipped_piece_qty' => 30]);
        $refreshed = $page->searchItemsForModal(91, perPage: 100);
        $this->assertSame(27, collect($refreshed['data'])->firstWhere('id', 1)['sales_week1_qty']);
    }

    public function test_hq_preview_preserves_quantities_filters_and_reference_fields(): void
    {
        $legacy = $this->hqPage(true);
        $this->preparePreview($legacy);
        $this->connection->dailyQueries = [];
        $legacy->calculateSalesBasedExternalOrderPreview();
        $this->assertNull($legacy->salesBasedExternalOrderPreviewError);
        $this->assertCount(3, $this->connection->dailyQueries);

        $page = $this->hqPage();
        $this->preparePreview($page);
        $this->connection->dailyQueries = [];
        $page->calculateSalesBasedExternalOrderPreview();
        $this->assertCount(2, $this->connection->dailyQueries);
        $this->assertSame($legacy->salesBasedExternalOrderPreviewRows, $page->salesBasedExternalOrderPreviewRows);
        $this->assertSame($legacy->salesBasedExternalOrderPreviewConditions, $page->salesBasedExternalOrderPreviewConditions);
        $this->assertSame([1], array_column($page->salesBasedExternalOrderPreviewRows, 'item_id'));
        $row = $page->salesBasedExternalOrderPreviewRows[0];
        $this->assertSame(18, $row['sales_qty']);
        $this->assertSame(14, $row['order_piece_qty']);
        $this->assertSame(6.0, $row['daily_avg_qty']);
        $this->assertSame(21, $row['sales_week1_qty']);
    }

    public function test_normal_page_keeps_its_weekly_sales_and_empty_search_skips_statistics(): void
    {
        $page = new WmsOrderRegistration;
        $this->connection->dailyQueries = [];
        $result = $page->searchItemsForModal(91, perPage: 100);
        $this->assertCount(1, $this->connection->dailyQueries);
        $this->assertSame(27, collect($result['data'])->firstWhere('id', 1)['sales_week1_qty']);

        $this->connection->dailyQueries = [];
        $result = $this->hqPage()->searchItemsForModal(91, itemCode: 'missing');
        $this->assertSame([], $result['data']);
        $this->assertCount(0, $this->connection->dailyQueries);
    }

    public function test_transfer_preview_early_warehouse_filter_preserves_complete_result(): void
    {
        $user = Mockery::mock();
        $user->shouldReceive('getSelectedWarehouseId')->andReturn(91);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(1);

        $legacy = $this->transferPage();
        $this->connection->legacyTransferScope = true;
        $legacy->calculateSalesBasedTransferPreview();
        $this->assertNull($legacy->salesBasedTransferPreviewError);

        $this->connection->legacyTransferScope = false;
        $this->connection->dailyQueries = [];
        $page = $this->transferPage();
        $page->calculateSalesBasedTransferPreview();
        $this->assertSame($legacy->salesBasedTransferPreviewRows, $page->salesBasedTransferPreviewRows);
        $this->assertSame($legacy->salesBasedTransferPreviewConditions, $page->salesBasedTransferPreviewConditions);
        $this->assertCount(1, $this->connection->dailyQueries);
        $this->assertStringContainsString('from "stats_item_warehouse_daily_sales" where "warehouse_id" in (?, ?)', $this->connection->dailyQueries[0]);
        $this->assertSame([92], array_column($page->salesBasedTransferPreviewRows, 'satellite_warehouse_id'));
        $this->assertSame(18, $page->salesBasedTransferPreviewRows[0]['sales_qty']);
        $this->assertSame(15, $page->salesBasedTransferPreviewRows[0]['order_piece_qty']);
    }

    private function hqPage(bool $legacy = false): WmsOrderRegistrationHq
    {
        $page = $legacy ? new LegacyStatisticsHqPage : new WmsOrderRegistrationHq;
        $service = Mockery::mock(HqOrderRegistrationReferenceService::class, [app(OrderRegistrationSearchService::class)])->makePartial();
        $service->shouldReceive('hqWarehouseId')->andReturn(91);
        $service->shouldReceive('lastIncomingSchedules', 'lastPurchases')->andReturn([]);
        $service->shouldReceive('salesExclusionLabel')->andReturn('店舗');
        $service->shouldReceive('contractorSupplierLinks')->andReturn(['suppliers' => [], 'links' => ['10' => [100, 101]]]);
        $service->shouldReceive('branchWholesaleQuantities')->andReturnUsing(function ($warehouseId, $itemIds, $ranges): array {
            $result = [];
            foreach ($itemIds as $itemId) {
                foreach ($ranges as $key => $range) {
                    $result[$itemId][$key] = in_array($key, ['sales_week1_qty', 'period'], true)
                        ? ([1 => 6, 2 => 8, 3 => 2][$itemId] ?? 0) : 0;
                }
            }
            return $result;
        });
        (new ReflectionProperty(WmsOrderRegistrationHq::class, 'hqReferenceService'))->setValue($page, $service);
        $page->warehouseId = 91;

        return $page;
    }

    private function preparePreview(WmsOrderRegistrationHq $page): void
    {
        $page->salesStartDate = '2026-10-02';
        $page->salesEndDate = '2026-10-04';
        $page->selectedExternalOrderContractorIds = [10];
        $page->externalOrderContractorsData = [['id' => 10]];
        $page->externalOrderJxContractorsData = [['id' => 10]];
        $page->selectedExternalOrderCategory2Ids = [5];
        $page->externalOrderCategory2Data = [['id' => 5], ['id' => 6]];
        $page->selectedHqSupplierIds = [100];
    }

    private function transferPage(): ListWmsStockTransferCandidates
    {
        $page = new ListWmsStockTransferCandidates;
        $page->selectedSalesBasedTransferCategory2Ids = [5];
        $page->salesBasedTransferCategory2Data = [['id' => 5], ['id' => 6]];
        $page->mountedActions = [['name' => 'fixture', 'data' => ['sales_start_date' => '2026-10-02', 'sales_end_date' => '2026-10-04']]];

        return $page;
    }

    private function createFixture(): void
    {
        $tables = [
            'client_settings' => 'id integer, client_id integer, system_date text',
            'items' => 'id integer, code text, name text, packaging text, volume integer, volume_unit text, capacity_case integer, capacity_carton integer, item_category2_id integer, end_of_sale_type text, is_ended integer, is_active integer, start_of_sale_date text, end_of_sale_date text',
            'item_contractors' => 'id integer, warehouse_id integer, item_id integer, contractor_id integer, supplier_id integer, purchase_unit integer, safety_stock integer, note text',
            'contractors' => 'id integer, code text, name text, is_active integer, is_auto_change_order integer',
            'suppliers' => 'id integer, partner_id integer, is_active integer',
            'partners' => 'id integer, code text, name text, is_active integer',
            'item_categories' => 'id integer, code text, is_active integer',
            'item_search_information' => 'id integer, item_id integer, search_string text, quantity_type text, code_type text, priority integer, is_used_for_ordering integer, is_active integer',
            'stats_item_warehouse_sales_summaries' => 'warehouse_id integer, item_id integer',
            'stats_item_warehouse_daily_sales' => 'business_date text, warehouse_id integer, item_id integer, shipped_piece_qty integer, sales_piece_qty integer, return_piece_qty integer, transfer_piece_qty integer',
            'real_stocks' => 'warehouse_id integer, item_id integer, available_quantity integer',
            'wms_order_incoming_schedules' => 'id integer, warehouse_id integer, item_id integer, status text, quantity_type text, expected_quantity integer, received_quantity integer, order_date text, expected_arrival_date text',
            'item_incoming_default_locations' => 'warehouse_id integer, item_id integer, location_id integer',
            'locations' => 'id integer, code1 text, code2 text, code3 text',
            'warehouses' => 'id integer, code text, name text, is_active integer',
            'wms_contractor_settings' => 'id integer, contractor_id integer, transmission_type text, supply_warehouse_id integer',
            'wms_warehouse_auto_order_settings' => 'warehouse_id integer, is_auto_order_enabled integer',
            'wms_auto_order_job_controls' => 'id integer, settlement_status text, process_name text, warehouse_id integer, started_at text, created_by integer',
            'wms_v_stock_available' => 'warehouse_id integer, item_id integer, real_stock_id integer, available_for_wms integer',
        ];
        foreach ($tables as $table => $columns) {
            $this->connection->statement("CREATE TABLE {$table} ({$columns})");
        }
        $this->connection->table('client_settings')->insert(['id' => 1, 'client_id' => 1, 'system_date' => '2026-10-04']);
        foreach ([91, 92, 93] as $warehouseId) {
            $this->connection->table('warehouses')->insert(['id' => $warehouseId, 'code' => (string) $warehouseId, 'name' => 'Warehouse '.$warehouseId, 'is_active' => 1]);
            $this->connection->table('wms_warehouse_auto_order_settings')->insert(['warehouse_id' => $warehouseId, 'is_auto_order_enabled' => $warehouseId === 93 ? 0 : 1]);
        }
        foreach ([10, 20] as $contractorId) {
            $this->connection->table('contractors')->insert(['id' => $contractorId, 'code' => (string) $contractorId, 'name' => 'Contractor '.$contractorId, 'is_active' => 1, 'is_auto_change_order' => 1]);
        }
        $this->connection->table('wms_contractor_settings')->insert(['id' => 20, 'contractor_id' => 20, 'transmission_type' => 'INTERNAL', 'supply_warehouse_id' => 91]);
        foreach ([100, 101] as $supplierId) {
            $this->connection->table('suppliers')->insert(['id' => $supplierId, 'partner_id' => $supplierId, 'is_active' => 1]);
            $this->connection->table('partners')->insert(['id' => $supplierId, 'code' => (string) $supplierId, 'name' => 'Supplier '.$supplierId, 'is_active' => 1]);
        }
        $this->connection->table('item_categories')->insert(['id' => 5, 'code' => '5', 'is_active' => 1]);
        foreach ([1 => 24, 2 => 5, 3 => -3, 4 => 8, 5 => 10] as $itemId => $quantity) {
            $this->connection->table('items')->insert(['id' => $itemId, 'code' => 'ITEM'.$itemId, 'name' => 'Item '.$itemId, 'packaging' => '', 'volume' => 750, 'volume_unit' => 'MILLILITER', 'capacity_case' => 12, 'capacity_carton' => 6, 'item_category2_id' => $itemId === 5 ? 6 : 5, 'end_of_sale_type' => 'NORMAL', 'is_ended' => 0, 'is_active' => 1]);
            $this->connection->table('item_contractors')->insert(['id' => $itemId, 'warehouse_id' => 91, 'item_id' => $itemId, 'contractor_id' => 10, 'supplier_id' => $itemId === 4 ? 101 : 100, 'purchase_unit' => 1, 'safety_stock' => 0, 'note' => 'fixture']);
            $this->connection->table('item_search_information')->insert(['id' => $itemId, 'item_id' => $itemId, 'search_string' => '123'.$itemId, 'quantity_type' => 'PIECE', 'code_type' => 'JAN', 'priority' => 1, 'is_used_for_ordering' => 1, 'is_active' => 1]);
            $this->daily('2026-10-04', 91, $itemId, $quantity);
        }
        foreach (['2026-09-01' => 6, '2026-09-14' => 5, '2026-09-27' => 7, '2026-09-30' => 3] as $date => $quantity) {
            $this->daily($date, 91, 1, $quantity);
        }
        $this->connection->table('real_stocks')->insert(['warehouse_id' => 91, 'item_id' => 1, 'available_quantity' => 4]);
        foreach ([92, 93] as $warehouseId) {
            $this->connection->table('item_contractors')->insert(['id' => $warehouseId, 'warehouse_id' => $warehouseId, 'item_id' => 1, 'contractor_id' => 20, 'supplier_id' => 100, 'purchase_unit' => 1, 'safety_stock' => 0]);
            $this->daily('2026-10-03', $warehouseId, 1, $warehouseId === 92 ? 20 : 9000);
        }
        $this->daily('2026-10-04', 92, 1, -2);
        $this->daily('2026-10-01', 92, 1, 8000);
        $this->connection->table('wms_v_stock_available')->insert(['warehouse_id' => 92, 'item_id' => 1, 'real_stock_id' => 1, 'available_for_wms' => 3]);
    }

    private function daily(string $date, int $warehouseId, int $itemId, int $quantity): void
    {
        $this->connection->table('stats_item_warehouse_daily_sales')->insert(['business_date' => $date, 'warehouse_id' => $warehouseId, 'item_id' => $itemId, 'shipped_piece_qty' => $quantity, 'sales_piece_qty' => max(0, $quantity), 'return_piece_qty' => max(0, -$quantity), 'transfer_piece_qty' => 0]);
    }
}

/** Reinstates exactly the old redundant read while retaining the same final HQ logic. */
class LegacyStatisticsHqPage extends WmsOrderRegistrationHq
{
    protected function shouldLoadBaseWeeklySalesQuantities(): bool
    {
        return true;
    }
}

class StatisticsReadFixtureConnection extends SQLiteConnection
{
    public array $dailyQueries = [];

    public bool $legacyTransferScope = false;

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (str_contains($query, '"stats_item_warehouse_daily_sales"')) {
            $this->dailyQueries[] = $query;
        }
        if ($this->legacyTransferScope && str_contains($query, 'as satellite_warehouse_id')) {
            // Baseline: only neutralize the newly added inner warehouse predicate.
            $query = preg_replace('/(from "stats_item_warehouse_daily_sales" where )("warehouse_id" in \([^)]*\))/', '$1(1 = 1 OR $2)', $query);
        }
        // Incoming schedules are empty in this fixture. SQLite lacks MySQL's ordered
        // GROUP_CONCAT syntax; MAX has the same empty result for that ancillary lookup.
        $query = preg_replace('/SUBSTRING_INDEX\(\s*GROUP_CONCAT\(.*?\),\s*\',\',\s*1\s*\)/s', 'MAX(expected_arrival_date)', $query);

        return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
    }
}
