<?php

namespace Tests\Feature\AutoOrder;

use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\TransferCandidateExecutionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransferCandidateDateIntegrationTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection('sakemaru');
        if (! app()->environment('testing') || ! str_contains($db->getDatabaseName(), 'test')) {
            $this->markTestSkipped('専用テストDBでのみ実行します。');
        }
        $db->beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::connection('sakemaru')->rollBack();
        }
        parent::tearDown();
    }

    private function candidate(?string $arrival = '2026-10-28'): WmsStockTransferCandidate
    {
        $db = DB::connection('sakemaru');
        $mapping = $db->table('warehouse_stock_transfer_delivery_courses')->whereNotNull('delivery_course_id')->first();
        $item = $db->table('items')->where('client_id', config('app.client_id'))->where('capacity_case', 12)->first();
        $contractor = $db->table('wms_contractor_settings')->where('transmission_type', 'INTERNAL')->value('contractor_id');
        self::assertNotNull($mapping, '配送設定の合成マスターが必要です。');
        self::assertNotNull($item, 'ケース入数12の合成商品が必要です。');
        self::assertNotNull($contractor, '物流発注先の合成マスターが必要です。');

        return WmsStockTransferCandidate::create([
            'batch_code' => 'tl-'.bin2hex(random_bytes(6)),
            'hub_warehouse_id' => $mapping->from_warehouse_id,
            'satellite_warehouse_id' => $mapping->to_warehouse_id,
            'delivery_course_id' => $mapping->delivery_course_id,
            'item_id' => $item->id, 'item_code' => $item->code,
            'contractor_id' => $contractor,
            'suggested_quantity' => 2, 'transfer_quantity' => 2,
            'quantity_type' => 'CASE', 'status' => 'APPROVED',
            'shipment_date' => '2026-10-26', 'expected_arrival_date' => $arrival,
        ]);
    }

    public function test_single_and_grouped_execution_use_the_same_arrival_date_and_quantity(): void
    {
        $db = DB::connection('sakemaru');
        $service = app(TransferCandidateExecutionService::class);
        $single = $this->candidate();
        $result = $service->executeCandidate($single, 1);
        $queue = $db->table('stock_transfer_queue')->find($result['queue_id']);
        $schedule = $db->table('wms_order_incoming_schedules')->find($result['incoming_schedule_id']);
        self::assertSame('2026-10-28', $queue->process_date);
        self::assertSame($schedule->expected_arrival_date, $queue->delivered_date);
        self::assertNull($queue->picking_date);
        $payload = json_decode($queue->items, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $payload[0]['quantity']);
        self::assertSame('CASE', $payload[0]['quantity_type']);
        self::assertSame(2, (int) $schedule->expected_quantity);
        self::assertSame('CASE', $schedule->quantity_type);

        $grouped = $this->candidate();
        $groupResult = $service->executeSelected([$grouped->id], 1);
        self::assertSame([], $groupResult['errors']);
        self::assertSame(1, $groupResult['queue_count']);
        $groupQueue = $db->table('stock_transfer_queue')->where('request_id', 'transfer-create-group-'.$grouped->id)->sole();
        self::assertSame($queue->delivered_date, $groupQueue->delivered_date);
        self::assertSame($queue->process_date, $groupQueue->process_date);
        self::assertNull($groupQueue->picking_date);
    }

    public function test_single_execution_falls_back_to_shipment_when_arrival_is_absent(): void
    {
        $result = app(TransferCandidateExecutionService::class)->executeCandidate($this->candidate(null), 1);
        $db = DB::connection('sakemaru');
        self::assertSame('2026-10-26', $db->table('stock_transfer_queue')->find($result['queue_id'])->delivered_date);
        self::assertSame('2026-10-26', $db->table('wms_order_incoming_schedules')->find($result['incoming_schedule_id'])->expected_arrival_date);
    }

    public function test_repeated_execution_does_not_duplicate_queue_or_schedule(): void
    {
        $candidate = $this->candidate();
        $service = app(TransferCandidateExecutionService::class);
        $service->executeCandidate($candidate, 1);
        $db = DB::connection('sakemaru');
        $counts = [$db->table('stock_transfer_queue')->count(), $db->table('wms_order_incoming_schedules')->count()];
        try {
            $service->executeCandidate($candidate, 1);
            self::fail('再実行は拒否される必要があります。');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('既に確定', $e->getMessage());
        }
        self::assertSame($counts, [$db->table('stock_transfer_queue')->count(), $db->table('wms_order_incoming_schedules')->count()]);
    }
}
