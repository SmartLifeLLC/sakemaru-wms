<?php

namespace Tests\Feature\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderEntrySource;
use App\Enums\AutoOrder\OriginType;
use App\Enums\QuantityType;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\OrderDataFileService;
use App\Services\AutoOrder\OrderTransmissionService;
use App\Services\AutoOrder\PurchasePriceService;
use App\Services\Distribution\DistributionOrderCandidateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectDistributionOrderIntegrationTest extends TestCase
{
    private bool $transactionStarted = false;

    private int $warehouseId;

    private int $destinationId;

    private int $userId;

    private object $master;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-09 22:00:00');
        Storage::fake('s3');
        DB::connection('sakemaru')->beginTransaction();
        $this->transactionStarted = true;
        $this->warehouseId = (int) DB::connection('sakemaru')->table('warehouses')->where('code', '91')->value('id');
        $this->destinationId = (int) DB::connection('sakemaru')->table('warehouses')->where('code', '11')->value('id');
        $this->userId = (int) DB::connection('sakemaru')->table('users')->value('id');
        $this->master = DB::connection('sakemaru')->table('item_contractors as ic')
            ->join('items as i', 'i.id', '=', 'ic.item_id')
            ->join('contractors as c', 'c.id', '=', 'ic.contractor_id')
            ->join('suppliers as s', 's.id', '=', 'ic.supplier_id')
            ->join('wms_contractor_settings as cs', 'cs.contractor_id', '=', 'ic.contractor_id')
            ->where('ic.client_id', config('app.client_id'))
            ->where('ic.warehouse_id', $this->warehouseId)
            ->where('i.end_of_sale_type', 'NORMAL')->where('i.is_ended', false)
            ->where('c.code', '<>', '9012')->where('cs.transmission_type', 'JX_FINET')
            ->where('i.capacity_case', '>', 1)
            ->select(['ic.id', 'ic.item_id', 'ic.contractor_id', 'ic.supplier_id', 'i.code', 'i.capacity_case', 's.partner_id'])
            ->first();
        $this->assertNotNull($this->master, '専用テストDBに直送分配用の商品発注先マスタが必要です。');
        $this->mock(OrderDataFileService::class)->shouldNotReceive('generateFaxPdfFilesForCandidates');
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::connection('sakemaru')->rollBack();
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(string $id = 'direct-a', int $quantity = 12, string $date = '2026-10-12'): array
    {
        return [
            'row_id' => $id, 'distribution_business_key' => sha1($id),
            'item_id' => (int) $this->master->item_id, 'product_code' => (string) $this->master->code,
            'item_contractor_id' => (int) $this->master->id,
            'contractor_id' => (int) $this->master->contractor_id,
            'supplier_id' => (int) $this->master->supplier_id,
            'order_date' => '2026-10-09', 'delivery_date' => $date,
            'alloc_total' => $quantity, 'po_case' => 0, 'po_each' => $quantity,
            'allocations' => [['destination_id' => $this->destinationId, 'quantity' => $quantity]],
        ];
    }

    private function generate(array $rows): array
    {
        return app(DistributionOrderCandidateService::class)->createDirect($this->warehouseId, $this->userId, $rows);
    }

    public function test_fax_confirmation_and_incoming_use_common_order_data_without_pdf_or_jx(): void
    {
        $row = $this->row(quantity: (int) $this->master->capacity_case);
        $row['po_case'] = 1;
        $row['po_each'] = 0;
        $result = $this->generate([$row]);
        $this->assertSame(1, $result['created_count']);
        $this->assertSame(1, $result['incoming_schedule_count']);
        $this->assertSame($result['candidate_ids'], $result['candidate_ids_by_row']['direct-a']);
        $candidate = WmsOrderCandidate::findOrFail($result['candidate_ids'][0]);
        $schedule = WmsOrderIncomingSchedule::where('order_candidate_id', $candidate->id)->sole();
        $this->assertSame(CandidateStatus::CONFIRMED, $candidate->status);
        $this->assertSame(OriginType::DIST, $candidate->origin_type);
        $this->assertSame(OrderEntrySource::DISTRIBUTION, $candidate->entry_source);
        $this->assertSame(OrderChannel::FAX, $candidate->order_channel);
        $this->assertSame(QuantityType::PIECE, $candidate->quantity_type);
        $this->assertSame((int) $this->master->capacity_case, (int) $candidate->order_quantity);
        foreach (['warehouse_id', 'item_id', 'contractor_id', 'supplier_id', 'quantity_type', 'order_channel'] as $field) {
            $this->assertSame($candidate->$field, $schedule->$field, $field);
        }
        $this->assertSame($candidate->order_quantity, $schedule->expected_quantity);
        $this->assertSame('2026-10-12', $schedule->expected_arrival_date->toDateString());
        $this->assertSame('2026-10-09', $schedule->order_date->toDateString());
        $price = app(PurchasePriceService::class)->getPrice((int) $this->master->item_id, (int) $this->master->partner_id, $this->destinationId);
        $this->assertEquals($price['unit_price'], $candidate->purchase_unit_price);
        $this->assertEquals($candidate->purchase_unit_price, $schedule->unit_price);
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $jx = app(OrderTransmissionService::class)->generateJxFilesForCandidateIds($result['candidate_ids']);
        $this->assertSame(0, $jx['eligible_count']);
        $this->assertSame(1, $jx['excluded_fax_channel']);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_different_dates_and_separate_calls_never_overwrite_existing_orders(): void
    {
        $result = $this->generate([$this->row('direct-a', 12, '2026-10-12'), $this->row('direct-b', 7, '2026-10-13')]);
        $this->assertSame(2, $result['created_count']);
        $a = WmsOrderCandidate::findOrFail($result['candidate_ids_by_row']['direct-a'][0]);
        $b = WmsOrderCandidate::findOrFail($result['candidate_ids_by_row']['direct-b'][0]);
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame('2026-10-12', $a->expected_arrival_date->toDateString());
        $this->assertSame('2026-10-13', $b->expected_arrival_date->toDateString());
        $this->assertSame('direct-a', $a->calculation_log->calculation_details['source_row_id']);
        $this->assertSame('direct-b', $b->calculation_log->calculation_details['source_row_id']);
        $candidates = WmsOrderCandidate::whereIn('id', $result['candidate_ids'])->get();
        WmsOrderCandidate::preloadCalculationLogs($candidates);
        foreach ($candidates as $candidate) {
            $this->assertSame($candidate->id, $candidate->calculation_log->calculation_details['candidate_id']);
        }
        $later = $this->generate([$this->row('direct-c', 3, '2026-10-14')]);
        $this->assertSame(1, $later['created_count']);
        $this->assertSame(12, (int) $a->refresh()->order_quantity);
        $this->assertSame(7, (int) $b->refresh()->order_quantity);
        $retry = $this->generate([$this->row('direct-a', 12, '2026-10-12'), $this->row('direct-b', 7, '2026-10-13')]);
        $this->assertSame(0, $retry['created_count']);
        $this->assertSame(2, $retry['already_generated_count']);
        $this->assertSame($result['candidate_ids_by_row'], $retry['candidate_ids_by_row']);
        $this->assertSame(2, WmsOrderIncomingSchedule::whereIn('order_candidate_id', $result['candidate_ids'])->count());
    }

    public function test_error_after_first_destination_rolls_back_all_orders_and_schedules(): void
    {
        $beforeCandidates = WmsOrderCandidate::count();
        $beforeSchedules = WmsOrderIncomingSchedule::count();
        $row = $this->row(quantity: 13);
        $row['allocations'] = [
            ['destination_id' => $this->destinationId, 'quantity' => 12],
            ['destination_id' => 2_000_000_000, 'quantity' => 1],
        ];
        try {
            $this->generate([$row]);
            $this->fail('無効な納入先は拒否される必要があります。');
        } catch (\RuntimeException) {
            $this->assertSame($beforeCandidates, WmsOrderCandidate::count());
            $this->assertSame($beforeSchedules, WmsOrderIncomingSchedule::count());
        }
    }

    public function test_case_piece_mismatch_is_rejected(): void
    {
        $row = $this->row(quantity: 12);
        $row['po_each'] = 13;
        $this->expectException(\RuntimeException::class);
        $this->generate([$row]);
    }

    public function test_missing_arrival_date_is_rejected_without_partial_orders(): void
    {
        $row = $this->row();
        $row['delivery_date'] = null;
        $this->expectException(\InvalidArgumentException::class);
        $this->generate([$row]);
    }

    public function test_changed_quantity_on_retry_cannot_duplicate_or_modify_confirmed_order(): void
    {
        $result = $this->generate([$this->row()]);
        try {
            $this->generate([$this->row(quantity: 13)]);
            $this->fail('確定済み発注の変更は拒否される必要があります。');
        } catch (\RuntimeException) {
            $this->assertSame(12, (int) WmsOrderCandidate::findOrFail($result['candidate_ids'][0])->order_quantity);
            $this->assertSame(1, WmsOrderIncomingSchedule::whereIn('order_candidate_id', $result['candidate_ids'])->count());
        }
    }

    public function test_retry_after_arrival_date_recovers_confirmed_row_without_creating_schedules(): void
    {
        $result = $this->generate([$this->row()]);
        Carbon::setTestNow('2026-10-13 10:00:00');
        $existing = app(DistributionOrderCandidateService::class)->findExistingGenerated($this->warehouseId, [$this->row()], 'direct');
        $this->assertSame(['direct-a'], $existing['already_generated_row_ids']);
        $retry = $this->generate([$this->row()]);
        $this->assertSame(0, $retry['created_count']);
        $this->assertSame($result['candidate_ids'], $retry['candidate_ids']);
        $this->assertSame(1, WmsOrderIncomingSchedule::whereIn('order_candidate_id', $result['candidate_ids'])->count());
    }

    public function test_pending_existing_candidate_is_not_reported_as_confirmed_or_duplicated(): void
    {
        $result = $this->generate([$this->row()]);
        WmsOrderCandidate::findOrFail($result['candidate_ids'][0])->update(['status' => CandidateStatus::PENDING]);
        $existing = app(DistributionOrderCandidateService::class)->findExistingGenerated($this->warehouseId, [$this->row()], 'direct');
        $this->assertSame([], $existing['already_generated_row_ids']);
        $this->expectException(\RuntimeException::class);
        $this->generate([$this->row()]);
    }

    public function test_all_destinations_of_one_row_are_confirmed_and_mapped_to_that_row(): void
    {
        $otherDestination = (int) DB::connection('sakemaru')->table('warehouses')->where('code', '12')->value('id');
        if (! $otherDestination) {
            $warehouse = (array) DB::connection('sakemaru')->table('warehouses')->where('id', $this->destinationId)->first();
            unset($warehouse['id'], $warehouse['is_virtual']);
            $warehouse['code'] = '12';
            $warehouse['name'] = '直送分配検証用店舗';
            $otherDestination = (int) DB::connection('sakemaru')->table('warehouses')->insertGetId($warehouse);
        }
        $this->assertGreaterThan(0, $otherDestination);
        $row = $this->row();
        $row['allocations'] = [
            ['destination_id' => $this->destinationId, 'quantity' => 8],
            ['destination_id' => $otherDestination, 'quantity' => 4],
        ];
        $result = $this->generate([$row]);
        $this->assertSame(2, $result['created_count']);
        $this->assertSame(2, $result['incoming_schedule_count']);
        $this->assertSame($result['candidate_ids'], $result['candidate_ids_by_row']['direct-a']);
        $schedules = WmsOrderIncomingSchedule::whereIn('order_candidate_id', $result['candidate_ids'])->get();
        $this->assertSame(8, (int) $schedules->firstWhere('warehouse_id', $this->destinationId)->expected_quantity);
        $this->assertSame(4, (int) $schedules->firstWhere('warehouse_id', $otherDestination)->expected_quantity);
    }
}
