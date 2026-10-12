<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Services\AutoOrder\IncomingReceiveService;
use App\Services\AutoOrder\PurchasePriceService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncomingCostPriceTest extends TestCase
{
    private int $itemId;
    private array $priceTemplate;

    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('sakemaru')->beginTransaction();
        $item = DB::connection('sakemaru')->table('items')->where('is_active', true)->first();
        $this->assertNotNull($item, 'A test item is required.');
        $this->itemId = (int) $item->id;
        $this->priceTemplate = ['producer_crate_price' => 0, 'type' => 'POST_TAX_PERCENT_10',
            'client_id' => $item->client_id, 'creator_id' => 0];
        config(['wms.client' => 'hana', 'client.id' => (int) $item->client_id]);
        DB::connection('sakemaru')->table('items')->where('id', $this->itemId)
            ->update(['capacity_case' => 6, 'purchase_price_type' => 'PRODUCER']);
        $this->price('2099-08-01', 510, 3060);
        $this->price('2099-09-01', 560, 3360);
        $this->price('2099-09-02', 999, 5994, false);
    }

    protected function tearDown(): void
    {
        DB::connection('sakemaru')->rollBack();
        parent::tearDown();
    }

    private function price(string $date, float $unit, ?float $case, bool $active = true): void
    {
        DB::connection('sakemaru')->table('item_prices')->insert(array_replace($this->priceTemplate, [
            'item_id' => $this->itemId, 'start_date' => $date, 'is_active' => $active,
            'cost_unit_price' => $unit, 'cost_case_price' => $case,
            'producer_unit_price' => 525, 'producer_case_price' => 3150,
        ]));
    }

    public function test_incoming_prices_use_dated_costs_while_order_prices_retain_the_existing_policy(): void
    {
        $service = new PurchasePriceService;
        $this->assertSame(510.0, $service->getIncomingPrice($this->itemId, null, 91, '2099-08-31')['unit_price']);
        $this->assertSame(560.0, $service->getIncomingPrice($this->itemId, null, 91, '2099-09-01')['unit_price']);
        $this->assertSame(3360.0, $service->getIncomingPrice($this->itemId, null, 91, '2099-09-03')['case_price']);
        $this->assertSame(525.0, $service->getPrice($this->itemId, null, 91, '2099-09-03')['unit_price']);
        config(['wms.client' => 'default']);
        $this->assertSame(525.0, $service->getIncomingPrice($this->itemId, null, 91, '2099-09-03')['unit_price']);
    }

    public function test_capacity_one_uses_piece_cost_for_case_incomings(): void
    {
        DB::connection('sakemaru')->table('items')->where('id', $this->itemId)->update(['capacity_case' => 1]);
        $this->price('2099-10-01', 560, 0);
        $this->assertSame(560.0, (new PurchasePriceService)->getIncomingPrice($this->itemId, null, null, '2099-10-01')['case_price']);
    }

    public function test_existing_wms_config_cache_without_the_new_client_key_retains_its_client_boundary(): void
    {
        config(['app.client_id' => config('client.id')]);
        app('config')->offsetUnset('client');
        $this->assertSame(560.0, (new PurchasePriceService)->getIncomingPrice($this->itemId, null, null, '2099-09-01')['unit_price']);
    }

    public function test_zero_cost_is_preserved_and_missing_cost_is_not_replaced_by_a_supplier_price(): void
    {
        $this->price('2099-10-01', 0, 0);
        $service = new PurchasePriceService;
        $this->assertSame(0.0, $service->getIncomingPrice($this->itemId, null, null, '2099-10-01')['unit_price']);
        $this->assertNull($service->getIncomingPrice($this->itemId, null, null, '1900-01-01')['unit_price']);
    }

    public function test_new_unordered_received_schedules_use_arrival_date_costs(): void
    {
        $method = new \ReflectionMethod(IncomingReceiveService::class, 'newScheduleMasterPrices');
        $this->assertSame(['unit_price' => 560.0, 'case_price' => 3360.0],
            $method->invoke(new IncomingReceiveService, $this->itemId, '2099-09-03'));
        config(['wms.client' => 'default']);
        $this->assertSame([], $method->invoke(new IncomingReceiveService, $this->itemId, '2099-09-03'));
    }

    public function test_hana_never_infers_received_price_units_from_prices_or_case_counts(): void
    {
        $method = new \ReflectionMethod(IncomingReceiveService::class, 'receivedPriceWritebackData');
        $service = new IncomingReceiveService;
        $detail = new \App\Models\WmsIncomingReceivedDetail([
            'd_pack_quantity' => 6, 'd_case_quantity' => 1, 'd_piece_quantity' => 0,
            'd_unit_price' => 190000, 'total_quantity' => 6,
        ]);
        foreach ([['PIECE', 525, 3150], ['PIECE', 560, 3360], ['CASE', 469, 9380]] as [$type, $unit, $case]) {
            $schedule = new \App\Models\WmsOrderIncomingSchedule([
                'quantity_type' => $type, 'unit_price' => $unit, 'case_price' => $case,
            ]);
            $this->assertSame(['partner_unit_price' => null, 'partner_case_price' => null, 'price_type' => null],
                $method->invoke($service, $schedule, $detail));
        }
        $detail->d_case_quantity = 0;
        $detail->d_piece_quantity = 6;
        $this->assertNull($method->invoke($service, null, $detail)['price_type']);
        config(['wms.client' => 'default']);
        $this->assertSame('PIECE', $method->invoke($service, null, $detail)['price_type']);
        $detail->d_case_quantity = 1;
        $this->assertSame('CASE', $method->invoke($service, null, $detail)['price_type']);
    }

    private function confirmedComparisonFixture(): array
    {
        $db = DB::connection('sakemaru');
        $copy = function (string $table, array $changes) use ($db): int {
            $row = $db->table($table)->first();
            $row ??= match ($table) {
                'purchases' => (object) ['supplier_id' => 1, 'delivered_type_id' => 1, 'billing_type' => 'DEFERRED',
                    'delivered_date' => '2099-09-01', 'account_date' => '2099-09-01', 'is_direct_delivery' => false],
                'trade_items' => (object) ['trade_type_id' => 1, 'stock_allocation_id' => 1, 'order_of_items_in_slip' => 1,
                    'order_quantity' => 2, 'order_quantity_type' => 'PIECE', 'price_category' => 'OTHER',
                    'tax_type' => 'POST_TAX', 'tax_rate' => 'PERCENT_10'],
                default => null,
            };
            $this->assertNotNull($row, "A test {$table} template is required.");
            $generated = collect($db->select('SHOW COLUMNS FROM '.$table))
                ->filter(fn ($column) => str_contains($column->Extra, 'GENERATED'))->pluck('Field')->all();
            return $db->table($table)->insertGetId(array_replace(
                array_diff_key((array) $row, array_flip(['id', ...$generated])), $changes));
        };
        $serial = (int) $db->table('trades')->max('serial_id') + 1;
        $tradeId = $copy('trades', ['client_id' => config('client.id'), 'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'serial_id' => $serial, 'trade_category' => 'PURCHASE', 'is_latest' => true, 'is_active' => true, 'is_offset' => false]);
        $copy('purchases', ['client_id' => config('client.id'), 'trade_id' => $tradeId, 'warehouse_id' => 999999, 'is_active' => true]);
        $lineId = $copy('trade_items', ['client_id' => config('client.id'), 'trade_id' => $tradeId, 'item_id' => $this->itemId,
            'quantity_type' => 'PIECE', 'quantity' => 2, 'capacity_case' => 6, 'capacity_carton' => 1,
            'price' => 130, 'amount' => 260, 'is_active' => true]);
        $queueId = $db->table('purchase_create_queue')->insertGetId(['request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'slip_number' => $serial, 'status' => 'FINISHED', 'is_success' => true, 'items' => '{}',
            'delivered_date' => '2099-09-01']);
        $schedule = \App\Models\WmsOrderIncomingSchedule::create(['warehouse_id' => 999999, 'item_id' => $this->itemId,
            'purchase_queue_id' => $queueId, 'quantity_type' => 'CASE', 'expected_quantity' => 1,
            'order_source' => 'AUTO', 'status' => 'TRANSMITTED']);
        \App\Models\WmsIncomingPriceCheckSource::create([
            'source_key' => hash('sha256', 'PURCHASE_SLIP_REGISTERED|source|trade_item:'.$lineId),
            'source_type' => 'PURCHASE_SLIP_REGISTERED', 'source_schema_version' => '2026-10-12',
            'item_id' => $this->itemId, 'expected_quantity' => 2, 'received_total_quantity' => 2, 'received_amount' => 260,
            'comparison_price_type' => 'PIECE', 'comparison_master_price' => 100, 'comparison_received_price' => 130,
            'current_price_mismatch' => true, 'calculation_payload' => ['trade_item_id' => $lineId],
        ]);
        return compact('schedule', 'queueId', 'lineId');
    }

    public function test_warnings_can_use_a_purchase_already_created_before_the_eos_is_recorded(): void
    {
        $f = $this->confirmedComparisonFixture();
        $detail = new \App\Models\WmsIncomingReceivedDetail(['id' => 1000000 + $f['schedule']->id]);
        $warning = \App\Models\WmsIncomingImportError::create(['received_file_id' => 1000000,
            'received_detail_id' => $detail->id, 'error_type' => 'WARNING', 'error_code' => 'PRICE_UNIT_UNCONFIRMED',
            'error_message' => '単位要確認', 'raw_data' => ['comparison_policy' => 'CONFIRMED_PURCHASE_V1']]);
        $method = new \ReflectionMethod(IncomingReceiveService::class, 'confirmPurchasePriceWarning');
        $method->invoke(new IncomingReceiveService, $f['schedule'], $detail);
        $warning->refresh();
        $this->assertSame('PRICE_MISMATCH', $warning->error_code);
        $this->assertEquals('100.00', $warning->expected_price);
        $this->assertEquals('130.00', $warning->actual_price);
        $this->assertSame('PIECE', $warning->raw_data['comparison_price_type']);
        $this->assertSame(2, (int) $warning->raw_data['comparison_quantity']);
        $this->assertSame(260, (int) $warning->raw_data['comparison_amount']);
        $this->assertSame(1, $f['schedule']->refresh()->expected_quantity);
    }

    public function test_purchase_reader_does_not_select_a_failed_queue_or_an_ambiguous_product_line(): void
    {
        $f = $this->confirmedComparisonFixture();
        $reader = new \App\Services\AutoOrder\IncomingPurchaseComparisonReader;
        $this->assertSame('PIECE', $reader->read($f['schedule'])->comparison_price_type);
        DB::connection('sakemaru')->table('purchase_create_queue')->where('id', $f['queueId'])->update(['is_success' => false]);
        $this->assertNull($reader->read($f['schedule']));
        DB::connection('sakemaru')->table('purchase_create_queue')->where('id', $f['queueId'])->update(['is_success' => true]);
        $row = (array) DB::connection('sakemaru')->table('trade_items')->find($f['lineId']);
        $generated = collect(DB::connection('sakemaru')->select('SHOW COLUMNS FROM trade_items'))
            ->filter(fn ($column) => str_contains($column->Extra, 'GENERATED'))->pluck('Field')->all();
        DB::connection('sakemaru')->table('trade_items')->insert(array_diff_key($row, array_flip(['id', ...$generated])));
        $this->assertNull($reader->read($f['schedule']));
    }

    public function test_eos_original_waits_for_purchase_comparison_and_is_not_repriced_on_replay(): void
    {
        $file = \App\Models\WmsIncomingReceivedFile::create([
            'filename' => 'test-incoming-cost.dat', 'format_type' => 'JX', 'status' => 'MATCHED',
        ]);
        $slip = \App\Models\WmsIncomingReceivedSlip::create([
            'received_file_id' => $file->id, 'slip_number' => 'COST-EOS-TEST',
            'b_order_date' => '260831', 'b_delivery_date' => '260901', 'match_status' => 'MATCHED',
        ]);
        $detail = \App\Models\WmsIncomingReceivedDetail::create([
            'received_file_id' => $file->id, 'received_slip_id' => $slip->id, 'd_line_number' => 1,
            'd_pack_quantity' => 6, 'd_case_quantity' => 1, 'd_piece_quantity' => 2,
            'd_unit_price' => 56000, 'total_quantity' => 8, 'match_status' => 'MATCHED',
        ]);
        $schedule = \App\Models\WmsOrderIncomingSchedule::create([
            'warehouse_id' => 999999, 'item_id' => $this->itemId, 'contractor_id' => 999999,
            'quantity_type' => 'CASE', 'expected_quantity' => 1, 'received_quantity' => 1,
            'order_source' => 'AUTO', 'order_date' => '2099-08-31', 'expected_arrival_date' => '2099-09-01',
            'status' => 'PENDING', 'unit_price' => 560, 'case_price' => 3360,
        ]);
        $recorder = new \App\Services\AutoOrder\IncomingPriceCheckSourceRecorder;
        $recordMethod = new \ReflectionMethod(IncomingReceiveService::class, 'recordPriceCheckSource');
        $recordMethod->invoke(new IncomingReceiveService, $file, $detail, $schedule);
        $source = $recorder->record($file, $detail, $schedule)->refresh();
        $this->assertSame('2026-10-12', $source->source_schema_version);
        $this->assertSame('AWAITING_PURCHASE', $source->calculation_payload['comparison_status']);
        $this->assertNull($source->comparison_price_type);
        $this->assertFalse($source->current_price_mismatch);
        $this->assertEquals(1, $source->received_case_quantity);
        $this->assertEquals(2, $source->received_piece_quantity);
        $warning = \App\Models\WmsIncomingImportError::where('received_detail_id', $detail->id)->firstOrFail();
        $this->assertSame('PRICE_UNIT_UNCONFIRMED', $warning->error_code);
        $this->assertStringContainsString('単位要確認', $warning->error_message);
        $this->assertNull($warning->expected_price);
        $this->assertNull($warning->actual_price);
        $this->assertSame('CONFIRMED_PURCHASE_V1', $warning->raw_data['comparison_policy']);
        $before = $source->getRawOriginal();
        $schedule->unit_price = 999;
        $replay = $recorder->record($file, $detail, $schedule)->refresh();
        $this->assertSame($source->id, $replay->id);
        $this->assertSame($before, $replay->getRawOriginal());
        $warning->update(['error_code' => 'PRICE_MISMATCH', 'error_message' => '単価不一致（バラ）',
            'expected_price' => 560, 'actual_price' => 600]);
        $recordMethod->invoke(new IncomingReceiveService, $file, $detail, $schedule);
        $this->assertSame(1, \App\Models\WmsIncomingImportError::where('received_detail_id', $detail->id)->count());
        $this->assertSame('PRICE_MISMATCH', $warning->refresh()->error_code);
    }
}
