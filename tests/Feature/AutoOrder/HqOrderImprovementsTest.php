<?php

namespace Tests\Feature\AutoOrder;

use App\Enums\QuantityType;
use App\Filament\Pages\WmsOrderRegistration;
use App\Filament\Pages\WmsOrderRegistrationHq;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\ContractorOrderConditionImport;
use App\Services\AutoOrder\ContractorOrderConditions;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use App\Services\AutoOrder\OrderRegistrationService;
use App\Services\AutoOrder\OrderReviewSalesSql;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HqOrderImprovementsTest extends TestCase
{
    private int $clientId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('sakemaru')->beginTransaction();
        $this->clientId = (int) DB::connection('sakemaru')->table('clients')->value('id');
        if (! $this->clientId) {
            $this->clientId = $this->fixture('clients');
        }
    }

    protected function tearDown(): void
    {
        DB::connection('sakemaru')->rollBack();
        parent::tearDown();
    }

    public function test_wave_origins_exclude_manual_transfers_even_when_picked_and_do_not_duplicate_lot_rows(): void
    {
        $warehouse = $this->fixture('warehouses');
        $item = $this->fixture('items');
        $wave = $this->fixture('wms_waves', ['status' => 'COMPLETED']);
        $task = $this->fixture('wms_picking_tasks', ['wave_id' => $wave]);
        $eligible = $this->transfer($warehouse, $item, 12);
        $manual = $this->transfer($warehouse, $item, 90);
        $notWaved = $this->transfer($warehouse, $item, 70);
        $manualQueue = $this->transfer($warehouse, $item, 80);
        $returned = $this->transfer($warehouse, $item, -2);
        foreach ([$eligible, $eligible, $manual, $manualQueue, $returned] as $transfer) {
            $this->fixture('wms_picking_item_results', ['picking_task_id' => $task, 'stock_transfer_id' => $transfer, 'source_type' => 'STOCK_TRANSFER']);
        }
        foreach ([$eligible, $notWaved, $returned] as $transfer) {
            $this->fixture('stock_transfer_queue', ['stock_transfer_id' => $transfer, 'request_id' => 'transfer-create-'.$transfer, 'action_type' => 'CREATE', 'is_success' => 1]);
        }
        $this->fixture('stock_transfer_queue', ['stock_transfer_id' => $manualQueue, 'request_id' => 'wms-warehouse-transfer-'.$manualQueue, 'action_type' => 'CREATE', 'is_success' => 1]);
        $this->assertSame(10, $this->sales($warehouse, $item));
        $shortage = $this->fixture('wms_shortages', ['wave_id' => $wave]);
        $allocation = $this->fixture('wms_shortage_allocations', ['shortage_id' => $shortage]);
        $proxy = $this->transfer($warehouse, $item, 5);
        $this->fixture('stock_transfer_queue', ['stock_transfer_id' => $proxy, 'request_id' => 'proxy-shipment-'.$allocation, 'action_type' => 'CREATE', 'is_success' => 1]);
        $this->assertSame(15, $this->sales($warehouse, $item));
    }

    public function test_query_rewrite_keeps_parameters_and_on_day_transfer_columns(): void
    {
        $sql = 'WITH transfers AS (SELECT ? FROM stock_transfers) SELECT SUM(d.shipped_piece_qty) FROM stats_item_warehouse_daily_sales d WHERE d.business_date BETWEEN ? AND ?';
        $updated = OrderReviewSalesSql::updateDataQuery($sql);
        $this->assertStringContainsString('FROM stock_transfers', $updated);
        $this->assertSame(3, substr_count($updated, '?'));
        $this->assertStringContainsString('FROM '.OrderReviewSalesSql::VIEW.' d', $updated);
        $this->assertSame($updated, OrderReviewSalesSql::updateDataQuery($updated));
    }

    public function test_logistics_corrections_inherit_the_origin_and_count_offsets_without_their_own_wave(): void
    {
        $warehouse = $this->fixture('warehouses');
        $item = $this->fixture('items');
        $original = $this->transfer($warehouse, $item, 12, ['is_latest' => 0]);
        $originalTrade = DB::connection('sakemaru')->table('stock_transfers')->where('id', $original)->value('trade_id');
        $offset = $this->transfer($warehouse, $item, -12, ['origin_trade_id' => $originalTrade, 'is_offset' => 1, 'is_latest' => 0]);
        $corrected = $this->transfer($warehouse, $item, 8, ['origin_trade_id' => $originalTrade, 'is_latest' => 0]);
        $this->fixture('stock_transfer_queue', ['stock_transfer_id' => $original, 'request_id' => 'transfer-create-group-'.$original, 'action_type' => 'CREATE', 'is_success' => 1]);
        // Only the correction was picked. Its origin and offset must still be included.
        $wave = $this->fixture('wms_waves', ['status' => 'COMPLETED']);
        $task = $this->fixture('wms_picking_tasks', ['wave_id' => $wave]);
        $this->fixture('wms_picking_item_results', ['picking_task_id' => $task, 'stock_transfer_id' => $corrected, 'source_type' => 'STOCK_TRANSFER']);
        $this->assertSame(8, $this->sales($warehouse, $item));
        $this->assertSame(3, DB::connection('sakemaru')->table(OrderReviewSalesSql::VIEW)->where('warehouse_id', $warehouse)->where('item_id', $item)->count());

        $correctedTrade = DB::connection('sakemaru')->table('stock_transfers')->where('id', $corrected)->value('trade_id');
        $this->transfer($warehouse, $item, -8, ['origin_trade_id' => $correctedTrade, 'is_offset' => 1, 'is_latest' => 0]);
        $latest = $this->transfer($warehouse, $item, 5, ['origin_trade_id' => $correctedTrade]);
        $this->assertSame(5, $this->sales($warehouse, $item));
        // A date correction cancels the old day and contributes to the corrected day.
        DB::connection('sakemaru')->table('stock_transfers')->where('id', $latest)->update(['picking_date' => '2026-10-22']);
        $this->assertSame(0, $this->sales($warehouse, $item));
        $this->assertSame(5, (int) DB::connection('sakemaru')->table(OrderReviewSalesSql::VIEW)->where('warehouse_id', $warehouse)->where('item_id', $item)->where('business_date', '2026-10-22')->sum('shipped_piece_qty'));
    }

    public function test_manual_corrections_are_excluded_even_when_the_serial_matches_a_logistics_transfer(): void
    {
        $warehouse = $this->fixture('warehouses');
        $item = $this->fixture('items');
        $logistics = $this->transfer($warehouse, $item, 12, ['serial_id' => 991]);
        $manual = $this->transfer($warehouse, $item, 90, ['serial_id' => 991, 'is_latest' => 0]);
        $manualTrade = DB::connection('sakemaru')->table('stock_transfers')->where('id', $manual)->value('trade_id');
        $this->transfer($warehouse, $item, -90, ['serial_id' => 991, 'origin_trade_id' => $manualTrade, 'is_offset' => 1, 'is_latest' => 0]);
        $corrected = $this->transfer($warehouse, $item, 80, ['serial_id' => 991, 'origin_trade_id' => $manualTrade]);
        $wave = $this->fixture('wms_waves', ['status' => 'COMPLETED']);
        $task = $this->fixture('wms_picking_tasks', ['wave_id' => $wave]);
        foreach ([$logistics, $manual, $corrected] as $transfer) {
            $this->fixture('wms_picking_item_results', ['picking_task_id' => $task, 'stock_transfer_id' => $transfer, 'source_type' => 'STOCK_TRANSFER']);
        }
        $this->fixture('stock_transfer_queue', ['stock_transfer_id' => $logistics, 'request_id' => 'transfer-create-'.$logistics, 'action_type' => 'CREATE', 'is_success' => 1]);
        $this->assertSame(12, $this->sales($warehouse, $item));
    }

    public function test_proxy_shipment_corrections_inherit_the_shortage_wave(): void
    {
        $warehouse = $this->fixture('warehouses');
        $item = $this->fixture('items');
        $wave = $this->fixture('wms_waves', ['status' => 'COMPLETED']);
        $shortage = $this->fixture('wms_shortages', ['wave_id' => $wave]);
        $allocation = $this->fixture('wms_shortage_allocations', ['shortage_id' => $shortage]);
        $original = $this->transfer($warehouse, $item, 10, ['is_latest' => 0]);
        $originTrade = DB::connection('sakemaru')->table('stock_transfers')->where('id', $original)->value('trade_id');
        $this->transfer($warehouse, $item, -10, ['origin_trade_id' => $originTrade, 'is_offset' => 1, 'is_latest' => 0]);
        $this->transfer($warehouse, $item, 7, ['origin_trade_id' => $originTrade]);
        $this->fixture('stock_transfer_queue', ['stock_transfer_id' => $original, 'request_id' => 'proxy-shipment-'.$allocation, 'action_type' => 'CREATE', 'is_success' => 1]);
        $this->assertSame(7, $this->sales($warehouse, $item));
    }

    public function test_store_order_grouped_transfer_is_counted_on_its_picking_date_without_duplicating_items(): void
    {
        // Same generation path as representative transfer 20247: three candidates, one transfer, then a wave.
        $warehouse = $this->fixture('warehouses');
        $trade = $this->fixture('trades', ['trade_category' => 'STOCK_TRANSFER']);
        $transfer = $this->fixture('stock_transfers', [
            'trade_id' => $trade,
            'from_warehouse_id' => $warehouse,
            'picking_date' => '2026-10-23',
            'delivered_date' => '2026-10-24',
        ]);
        $wave = $this->fixture('wms_waves', ['status' => 'COMPLETED', 'shipping_date' => '2026-10-23']);
        $task = $this->fixture('wms_picking_tasks', ['wave_id' => $wave]);
        $items = [];
        for ($index = 0; $index < 3; $index++) {
            $item = $this->fixture('items');
            $items[] = $item;
            $tradeItem = $this->fixture('trade_items', ['trade_id' => $trade, 'item_id' => $item, 'quantity' => 2, 'quantity_type' => 'PIECE', 'capacity_case' => 1]);
            $this->fixture('wms_picking_item_results', ['picking_task_id' => $task, 'stock_transfer_id' => $transfer, 'source_type' => 'STOCK_TRANSFER', 'item_id' => $item, 'trade_item_id' => $tradeItem]);
        }
        $this->fixture('stock_transfer_queue', [
            'stock_transfer_id' => $transfer,
            'request_id' => 'transfer-create-group-101-102-103',
            'action_type' => 'CREATE',
            'is_success' => 1,
        ]);
        $query = DB::connection('sakemaru')->table(OrderReviewSalesSql::VIEW)->where('warehouse_id', $warehouse)->whereIn('item_id', $items);
        $this->assertSame(6, (int) (clone $query)->where('business_date', '2026-10-23')->sum('shipped_piece_qty'));
        $this->assertSame(3, (clone $query)->where('business_date', '2026-10-23')->count());
        $this->assertSame(0, (clone $query)->where('business_date', '2026-10-24')->count());
    }

    public function test_warehouse_mismatch_origins_require_a_successful_queue(): void
    {
        $warehouse = $this->fixture('warehouses');
        $item = $this->fixture('items');
        foreach ([0 => 50, 1 => 7] as $success => $quantity) {
            $wave = $this->fixture('wms_waves', ['status' => 'COMPLETED']);
            $task = $this->fixture('wms_picking_tasks', ['wave_id' => $wave]);
            $earning = $this->fixture('earnings');
            $this->fixture('wms_picking_item_results', ['picking_task_id' => $task, 'earning_id' => $earning, 'source_type' => 'EARNING']);
            $transfer = $this->transfer($warehouse, $item, $quantity);
            $this->fixture('stock_transfer_queue', ['stock_transfer_id' => $transfer, 'request_id' => 'wh-mismatch-'.$earning, 'action_type' => 'CREATE', 'is_success' => $success]);
        }
        $this->assertSame(7, $this->sales($warehouse, $item));
    }

    public function test_hq_branch_shipments_are_excluded_including_virtual_warehouse_mapping(): void
    {
        $this->clientId = $this->fixture('clients');
        $hq = $this->fixture('warehouses', ['code' => 91]);
        $branch = $this->fixture('warehouses', ['code' => 10]);
        $virtual = $this->fixture('warehouses', ['code' => 45, 'stock_warehouse_id' => $branch]);
        $normal = $this->fixture('warehouses', ['code' => 92]);
        $item = $this->fixture('items');
        foreach ([$branch => 40, $virtual => 30, $normal => 6] as $shippingWarehouse => $quantity) {
            $course = $this->fixture('delivery_courses', ['warehouse_id' => $shippingWarehouse]);
            $trade = $this->fixture('trades', ['trade_category' => 'EARNING']);
            $this->fixture('trade_items', ['trade_id' => $trade, 'item_id' => $item, 'quantity' => $quantity, 'quantity_type' => 'PIECE', 'capacity_case' => 1]);
            $this->fixture('earnings', ['trade_id' => $trade, 'warehouse_id' => $hq, 'delivery_course_id' => $course, 'delivered_date' => '2026-10-21']);
        }
        $this->assertSame(6, $this->sales($hq, $item));
    }

    public function test_bulk_channel_changes_only_selected_visible_lines_and_is_atomic(): void
    {
        $this->mock(OrderRegistrationSearchService::class)->shouldReceive('jxContractorIds')->andReturn([11]);
        $page = new WmsOrderRegistrationHq;
        $page->lines = [
            ['contractor_id' => 11, 'item_code' => 'A', 'order_channel' => 'FAX', 'item_contractor_linked' => true],
            ['contractor_id' => 12, 'item_code' => 'B', 'order_channel' => 'FAX', 'item_contractor_linked' => true],
            ['contractor_id' => 11, 'item_code' => 'C', 'order_channel' => 'FAX', 'item_contractor_linked' => false],
        ];
        $page->bulkOrderChannel = 'EOS';
        $page->selectedRegistrationLineIndexes = ['0', '1', '2'];
        $before = $page->lines;
        $page->applyBulkOrderChannel();
        $this->assertSame($before, $page->lines);
        $page->lineContractorFilter = '11';
        $page->selectedRegistrationLineIndexes = ['0', '1'];
        $page->applyBulkOrderChannel();
        $this->assertSame('EOS', $page->lines[0]['order_channel']);
        $this->assertSame('FAX', $page->lines[1]['order_channel']);
        $this->assertSame('FAX', $page->lines[2]['order_channel']);
    }

    public function test_import_preserves_blank_existing_conditions_and_other_settings_and_is_idempotent(): void
    {
        $id = $this->fixture('contractors', ['code' => 987654]);
        $other = $this->fixture('contractors', ['code' => 987655]);
        $this->fixture('wms_contractor_settings', ['contractor_id' => $id, 'transmission_type' => 'FTP']);
        $this->fixture('wms_contractor_settings', ['contractor_id' => $other, 'order_conditions' => '既存の条件']);
        $rows = [['code' => '987654', 'conditions' => '3ケース・混載可'], ['code' => '987655', 'conditions' => '']];
        $import = new ContractorOrderConditionImport;
        $plan = $import->plan($rows, $this->clientId);
        $this->assertSame(['change' => 1, 'blank' => 1], $plan['counts']);
        $this->assertSame(1, $import->apply($plan));
        $this->assertSame('FTP', WmsContractorSetting::where('contractor_id', $id)->first()->transmission_type->value);
        $this->assertSame('既存の条件', WmsContractorSetting::where('contractor_id', $other)->first()->order_conditions);
        $this->assertSame(0, $import->apply($import->plan($rows, $this->clientId)));
    }

    public function test_import_rejects_concurrent_edits(): void
    {
        $id = $this->fixture('contractors', ['code' => 987656]);
        $import = new ContractorOrderConditionImport;
        $plan = $import->plan([['code' => '987656', 'conditions' => '条件A']], $this->clientId);
        $this->fixture('wms_contractor_settings', ['contractor_id' => $id, 'order_conditions' => '別の担当者が更新']);
        $this->expectException(\RuntimeException::class);
        $import->apply($plan);
    }

    public function test_supplier_conditions_use_the_related_contractor_and_preserve_both_notes(): void
    {
        config(['client.id' => $this->clientId]);
        $supplier = $this->fixture('suppliers');
        $otherSupplier = $this->fixture('suppliers');
        $contractor = $this->fixture('contractors', ['supplier_id' => $supplier]);
        $supplierContractor = $this->fixture('contractors', [
            'id' => max($otherSupplier, (int) DB::connection('sakemaru')->table('contractors')->max('id')) + 10,
            'supplier_id' => $otherSupplier,
        ]);
        $this->assertNotSame($otherSupplier, $supplierContractor);
        $duplicate = $this->fixture('contractors', ['supplier_id' => $otherSupplier]);
        $inactive = $this->fixture('contractors', ['supplier_id' => $otherSupplier, 'is_active' => 0]);
        $foreignClient = $this->fixture('clients');
        $foreignSupplier = $this->fixture('suppliers', ['client_id' => $foreignClient]);
        $foreignContractor = $this->fixture('contractors', ['client_id' => $foreignClient, 'supplier_id' => $foreignSupplier]);
        $this->fixture('wms_contractor_settings', ['contractor_id' => $foreignContractor, 'order_conditions' => '別クライアントの条件']);
        foreach ([$contractor => '発注先条件', $supplierContractor => '6本単位', $duplicate => '6本単位', $inactive => '無効な条件'] as $id => $note) {
            $this->fixture('wms_contractor_settings', ['contractor_id' => $id, 'order_conditions' => $note]);
        }
        $rows = app(ContractorOrderConditions::class)->attach([
            ['contractor_id' => $contractor, 'supplier_id' => $otherSupplier],
            ['contractor_id' => $contractor, 'supplier_id' => $supplier],
            ['contractor_id' => $contractor, 'supplier_id' => null],
            ['contractor_id' => $contractor, 'supplier_id' => $foreignSupplier],
        ]);
        $this->assertSame('発注先条件', $rows[0]['contractor_order_conditions']);
        $this->assertSame('6本単位', $rows[0]['supplier_order_conditions']);
        $this->assertSame('', $rows[1]['supplier_order_conditions']);
        $this->assertSame('', $rows[2]['supplier_order_conditions']);
        $this->assertSame('', $rows[3]['supplier_order_conditions']);
        $this->assertSame([], app(ContractorOrderConditions::class)->attach([]));
    }

    public function test_hq_capacity_one_case_price_applies_to_search_sales_and_quantity_changes(): void
    {
        $row = $this->hqPriceRow(1);
        foreach (['search', 'sales'] as $source) {
            $page = new WmsOrderRegistrationHq;
            $page->warehouseId = $row['warehouse_id'];
            $page->{$source.'Rows'} = [$row];
            $page->{$source.'Quantities'} = [$row['key'] => ['case' => 12]];
            $source === 'search' ? $page->addSearchRow($row['key']) : $page->addSalesRow($row['key']);

            $this->assertSame(7840.0, $page->lines[0]['purchase_unit_price']);
            $this->assertSame('ITEM_PRICE', $page->lines[0]['purchase_unit_price_source']);
            $this->assertSame('CASE', $page->lines[0]['quantity_type']);
            $this->assertSame(12, $page->lines[0]['total_piece_quantity']);
            $this->assertSame(94080.0, $page->lines[0]['purchase_unit_price'] * $page->lines[0]['order_quantity']);

            $page->setLineOrderQuantity(0, 'PIECE', 5);
            $this->assertSame(7840.0, $page->lines[0]['purchase_unit_price']);
            $page->setLineOrderQuantity(0, 'CASE', 12);
            $this->assertSame(7840.0, $page->lines[0]['purchase_unit_price']);
            $this->assertSame('CASE', $page->lines[0]['quantity_type']);
            $this->assertSame(12, $page->lines[0]['total_piece_quantity']);
        }
    }

    public function test_hq_capacity_one_keeps_supplier_and_price_group_precedence(): void
    {
        $row = $this->hqPriceRow(1);
        foreach (['PARTNER', 'PARTNER_PRICE_GROUP', 'PARTNER_PRICE_GROUP2'] as $source) {
            $group1 = $this->fixture('partners');
            $group2 = $this->fixture('partners');
            $partner = $this->fixture('partners', ['partner_price_group_id' => $group1, 'partner_price_group2_id' => $group2]);
            $pricePartner = match ($source) {
                'PARTNER' => $partner,
                'PARTNER_PRICE_GROUP' => $group1,
                default => $group2,
            };
            $this->fixture('item_partner_prices', ['item_id' => $row['item_id'], 'partner_id' => $pricePartner,
                'warehouse_id' => $row['warehouse_id'], 'start_date' => now()->subDay()->toDateString(),
                'unit_price' => 8200, 'case_price' => 9999]);
            $page = new WmsOrderRegistrationHq;
            $page->lines = [array_replace($row, ['supplier_partner_id' => $partner])];
            $page->setLineOrderQuantity(0, 'CASE', 12);
            $this->assertSame(8200.0, $page->lines[0]['purchase_unit_price']);
            $this->assertSame($source, $page->lines[0]['purchase_unit_price_source']);
            $this->assertSame('CASE', $page->lines[0]['quantity_type']);
        }
    }

    public function test_hq_capacity_one_price_applies_to_candidate_and_sales_preview_modals(): void
    {
        $row = $this->hqPriceRow(1);
        $search = app(OrderRegistrationSearchService::class);
        $search->shouldReceive('incomingWarehouseId')->andReturn($row['warehouse_id']);
        $search->shouldReceive('jxContractorIds')->andReturn([]);
        $search->shouldReceive('searchCodeForItem', 'orderingCodeForItem')->andReturn('4900000000011');

        $page = new WmsOrderRegistrationHq;
        $page->warehouseId = $row['warehouse_id'];
        $page->orderCandidateItems = [$row + ['case_qty' => 12]];
        $page->addOrderCandidateItems();
        $this->assertCount(1, $page->lines);
        $this->assertSame(7840.0, $page->lines[0]['purchase_unit_price']);
        $this->assertSame('CASE', $page->lines[0]['quantity_type']);

        $page = new WmsOrderRegistrationHq;
        $page->warehouseId = $row['warehouse_id'];
        $page->salesBasedExternalOrderPreviewRows = [$row];
        $result = $page->addSalesBasedExternalOrderPreviewRowsToRegistration([
            ['index' => 0, 'item_id' => $row['item_id'], 'contractor_id' => $row['contractor_id'], 'case_qty' => 12],
        ], $row['expected_arrival_date']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(7840.0, $page->lines[0]['purchase_unit_price']);
        $this->assertSame('CASE', $page->lines[0]['quantity_type']);
    }

    public function test_hq_capacity_rule_does_not_change_regular_orders_or_other_case_capacities(): void
    {
        foreach ([1, 2, 24, 0] as $capacity) {
            $row = $this->hqPriceRow($capacity);
            foreach ([WmsOrderRegistration::class, WmsOrderRegistrationHq::class] as $pageClass) {
                $page = new $pageClass;
                $page->lines = [$row];
                $page->setLineOrderQuantity(0, 'CASE', 12);
                $expected = $pageClass === WmsOrderRegistrationHq::class && $capacity === 1 ? 7840.0 : 0.0;
                $this->assertSame($expected, $page->lines[0]['purchase_unit_price']);
                $this->assertSame('CASE', $page->lines[0]['quantity_type']);
            }
        }
    }

    public function test_hq_capacity_one_case_price_is_preserved_when_registering_and_creating_incoming(): void
    {
        config(['client.id' => $this->clientId, 'wms.client' => 'hana']);
        $row = $this->hqPriceRow(1);
        $page = new WmsOrderRegistrationHq;
        $page->lines = [$row];
        $page->setLineOrderQuantity(0, 'CASE', 12);
        $search = app(OrderRegistrationSearchService::class);
        $search->shouldReceive('incomingWarehouseId')->andReturn($row['warehouse_id']);
        $search->shouldReceive('availableStock', 'incomingQuantity')->andReturn(0);

        $result = app(OrderRegistrationService::class)->register($row['warehouse_id'], $page->lines, 1,
            generateDataFiles: false, batchCode: 'HQP'.bin2hex(random_bytes(6)));

        $this->assertCount(1, $result['candidate_ids']);
        $candidate = WmsOrderCandidate::findOrFail($result['candidate_ids'][0]);
        $this->assertSame(7840.0, (float) $candidate->purchase_unit_price);
        $this->assertSame(QuantityType::CASE, $candidate->quantity_type);
        $this->assertSame(12, (int) $candidate->order_quantity);
        $this->assertSame(94080.0, (float) $candidate->purchase_unit_price * $candidate->order_quantity);
        $incoming = WmsOrderIncomingSchedule::where('order_candidate_id', $candidate->id)->sole();
        $this->assertSame(QuantityType::CASE, $incoming->quantity_type);
        $this->assertSame(12, (int) $incoming->expected_quantity);
        $this->assertSame(7840.0, (float) $incoming->case_price);
    }

    private function hqPriceRow(int $capacity): array
    {
        $item = $this->fixture('items', ['capacity_case' => $capacity, 'purchase_price_type' => 'COST',
            'end_of_sale_type' => 'NORMAL', 'is_ended' => 0]);
        $this->fixture('item_prices', ['item_id' => $item, 'start_date' => now()->subDay()->toDateString(),
            'cost_unit_price' => 7840, 'cost_case_price' => 0]);
        $warehouse = $this->fixture('warehouses');
        $partner = $this->fixture('partners');
        $supplier = $this->fixture('suppliers', ['partner_id' => $partner]);
        $contractor = $this->fixture('contractors', ['supplier_id' => $supplier]);
        $this->fixture('item_contractors', ['item_id' => $item, 'warehouse_id' => $warehouse,
            'supplier_id' => $supplier, 'contractor_id' => $contractor, 'purchase_unit' => 1]);
        $this->partialMock(OrderRegistrationSearchService::class)->shouldReceive('defaultExpectedArrivalDate')
            ->andReturn(now()->addDays(2)->toDateString());

        return ['key' => 'price-'.$item, 'item_id' => $item, 'item_code' => (string) $item,
            'item_name' => '入数1原価テスト', 'capacity_case' => max(1, (int) $capacity),
            'warehouse_id' => $warehouse, 'contractor_id' => $contractor, 'contractor_name' => 'テスト発注先',
            'supplier_id' => $supplier, 'supplier_partner_id' => $partner, 'is_eos_available' => false,
            'order_channel' => 'FAX', 'quantity_type' => 'CASE', 'order_quantity' => 12,
            'expected_arrival_date' => now()->addDays(2)->toDateString(), 'search_code' => '4900000000011',
            'ordering_code' => '4900000000011'];
    }

    public function test_import_rejects_unmatched_and_duplicate_codes_before_writing(): void
    {
        $this->fixture('contractors', ['code' => 987657]);
        $import = new ContractorOrderConditionImport;
        $plan = $import->plan([
            ['code' => '987657', 'conditions' => '条件A'],
            ['code' => '987657', 'conditions' => '条件B'],
            ['code' => '987658', 'conditions' => '未登録'],
        ], $this->clientId);
        $this->assertSame(['duplicate' => 2, 'unmatched' => 1], $plan['counts']);
        $this->expectException(\RuntimeException::class);
        $import->apply($plan);
    }

    public function test_bulk_date_rejects_six_month_boundary_without_changing_lines(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24'));
        $page = new WmsOrderRegistrationHq;
        $page->lines = [['item_code' => 'A', 'expected_arrival_date' => '2026-11-05']];
        $page->selectedRegistrationLineIndexes = ['0'];
        $page->bulkExpectedArrivalDate = '2027-04-24';
        $before = $page->lines;
        $page->applyBulkExpectedArrivalDate();
        $this->assertSame($before, $page->lines);
        $this->assertStringContainsString('6か月以上', collect(session('filament.notifications'))->last()['title']);
        $this->travelBack();
    }

    public function test_bulk_date_accepts_the_day_before_six_months(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24'));
        $page = new WmsOrderRegistrationHq;
        $page->lines = [['item_code' => 'A', 'expected_arrival_date' => '2026-11-05']];
        $page->selectedRegistrationLineIndexes = ['0'];
        $page->bulkExpectedArrivalDate = '2027-04-23';
        $page->applyBulkExpectedArrivalDate();
        $this->assertSame('2027-04-23', $page->lines[0]['expected_arrival_date']);
        $this->travelBack();
    }

    public function test_confirmation_rejects_six_month_boundary_before_registration(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24'));
        $this->mock(OrderRegistrationService::class)->shouldNotReceive('register');
        $page = new WmsOrderRegistrationHq;
        $page->lines = [['item_code' => 'A', 'expected_arrival_date' => '2027-04-24']];
        $before = $page->lines;
        $page->confirmOrders();
        $this->assertSame($before, $page->lines);
        $this->assertStringContainsString('6か月以上', collect(session('filament.notifications'))->last()['title']);
        $this->travelBack();
    }

    private function sales(int $warehouse, int $item): int
    {
        return (int) DB::connection('sakemaru')->table(OrderReviewSalesSql::VIEW)->where('warehouse_id', $warehouse)->where('item_id', $item)->where('business_date', '2026-10-21')->sum('shipped_piece_qty');
    }

    private function transfer(int $warehouse, int $item, int $quantity, array $tradeValues = []): int
    {
        $trade = $this->fixture('trades', array_merge(['trade_category' => 'STOCK_TRANSFER'], $tradeValues));
        $this->fixture('trade_items', ['trade_id' => $trade, 'item_id' => $item, 'quantity' => $quantity, 'quantity_type' => 'PIECE', 'capacity_case' => 1]);

        return $this->fixture('stock_transfers', ['trade_id' => $trade, 'from_warehouse_id' => $warehouse, 'picking_date' => '2026-10-21', 'delivered_date' => '2026-10-22']);
    }

    /** Fill required fields from the dedicated test schema; every fixture is rolled back. */
    private function fixture(string $table, array $values = []): int
    {
        $db = DB::connection('sakemaru');
        $columns = $db->select('SELECT column_name,column_type,is_nullable,column_default,extra FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?', [$table]);
        $defaults = [];
        foreach ($columns as $column) {
            $c = array_change_key_case((array) $column, CASE_LOWER);
            $name = $c['column_name'];
            if ($name === 'client_id') {
                $defaults[$name] = $this->clientId;
            }
            if ($name === 'is_active') {
                $defaults[$name] = 1;
            }
            if (str_contains($c['extra'], 'auto_increment') || $c['is_nullable'] === 'YES' || $c['column_default'] !== null || str_contains($c['extra'], 'GENERATED')) {
                continue;
            }
            $type = $c['column_type'];
            $defaults[$name] = match (true) {
                $name === 'client_id' => $this->clientId,
                $name === 'code' && str_contains($type, 'int') => random_int(700000000, 799999999),
                str_starts_with($type, 'enum(') => explode("'", $type)[1],
                str_contains($type, 'int'), str_contains($type, 'decimal'), str_contains($type, 'double'), str_contains($type, 'float') => 1,
                str_contains($type, 'date'), str_contains($type, 'timestamp') => '2026-10-21',
                $type === 'json' => '[]',
                default => 'hq-test-'.bin2hex(random_bytes(6)),
            };
        }

        return (int) $db->table($table)->insertGetId(array_merge($defaults, $values));
    }
}
