<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\QuantityType;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingTransmissionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncomingTransmissionServiceTest extends TestCase
{
    private const TEST_SLIP_PREFIX = 'UTP';

    protected function tearDown(): void
    {
        $queueIds = WmsOrderIncomingSchedule::query()
            ->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'%')
            ->pluck('purchase_queue_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        WmsOrderIncomingSchedule::query()
            ->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'%')
            ->delete();

        $queueQuery = DB::connection('sakemaru')
            ->table('purchase_create_queue')
            ->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'%');

        if ($queueIds !== []) {
            $queueQuery->orWhereIn('id', $queueIds);
        }

        $queueQuery->delete();

        parent::tearDown();
    }

    public function test_transmit_groups_by_slip_number_and_splits_by_arrival_date(): void
    {
        $master = $this->purchaseMasterData();
        $slipA = $this->newSlipNumber('A');
        $slipB = $this->newSlipNumber('B');

        $sameSlipFirst = $this->createConfirmedSchedule($master, $slipA, '2026-07-20', 5);
        $sameSlipSecond = $this->createConfirmedSchedule($master, $slipA, '2026-07-20', 7);
        $sameSlipLaterDelivery = $this->createConfirmedSchedule($master, $slipA, '2026-07-21', 3);
        $differentSlip = $this->createConfirmedSchedule($master, $slipB, '2026-07-20', 2);

        $result = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            scheduleIds: [
                $sameSlipFirst->id,
                $sameSlipSecond->id,
                $sameSlipLaterDelivery->id,
                $differentSlip->id,
            ],
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['queue_count']);
        $this->assertSame(4, $result['schedule_count']);

        $sameSlipFirst->refresh();
        $sameSlipSecond->refresh();
        $sameSlipLaterDelivery->refresh();
        $differentSlip->refresh();

        $this->assertSame($sameSlipFirst->purchase_queue_id, $sameSlipSecond->purchase_queue_id);
        $this->assertNotSame($sameSlipFirst->purchase_queue_id, $sameSlipLaterDelivery->purchase_queue_id);
        $this->assertNotSame($sameSlipFirst->purchase_queue_id, $differentSlip->purchase_queue_id);

        $queue = DB::connection('sakemaru')
            ->table('purchase_create_queue')
            ->where('id', $sameSlipFirst->purchase_queue_id)
            ->first();
        $payload = json_decode($queue->items, true);

        $this->assertSame($slipA, $queue->slip_number);
        $this->assertSame($slipA, $payload['slip_number']);
        $this->assertSame('2026-07-20', $payload['delivered_date']);
        $this->assertCount(2, $payload['details']);
    }

    public function test_transmit_skips_received_schedule_without_confirmed_supplier(): void
    {
        $master = $this->purchaseMasterData(requireContractorSupplier: true);
        $slip = $this->newSlipNumber('U');
        $schedule = $this->createConfirmedSchedule(
            $master,
            $slip,
            '2026-07-20',
            5,
            supplierId: null,
            useDefaultSupplier: false,
            orderSource: OrderSource::RECEIVED,
            isReceiveMatched: true,
        );

        $result = app(IncomingTransmissionService::class)->transmitConfirmedIncomings(
            scheduleIds: [$schedule->id],
        );

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['queue_count']);
        $this->assertSame(0, $result['schedule_count']);
        $this->assertNotEmpty($result['errors']);

        $schedule->refresh();

        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $schedule->status);
        $this->assertNull($schedule->purchase_queue_id);
        $this->assertDatabaseMissing('purchase_create_queue', [
            'slip_number' => $slip,
        ], 'sakemaru');
    }

    private function purchaseMasterData(bool $requireContractorSupplier = false): array
    {
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('id')
            ->first(['id', 'code']);
        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('id')
            ->first(['id', 'code']);
        $supplier = DB::connection('sakemaru')
            ->table('suppliers as s')
            ->join('partners as p', 'p.id', '=', 's.partner_id')
            ->where('p.is_active', true)
            ->where('p.is_supplier', true)
            ->whereNotNull('p.code')
            ->orderBy('s.id')
            ->first(['s.id', 'p.code']);
        $contractorQuery = DB::connection('sakemaru')
            ->table('contractors as c')
            ->leftJoin('wms_contractor_settings as wcs', 'wcs.contractor_id', '=', 'c.id')
            ->where('c.is_active', true)
            ->where(function ($query) {
                $query
                    ->whereNull('wcs.transmission_type')
                    ->orWhere('wcs.transmission_type', '!=', TransmissionType::INTERNAL->value);
            })
            ->orderBy('c.id');

        if ($requireContractorSupplier) {
            $contractorQuery->whereNotNull('c.supplier_id');
        }

        $contractor = $contractorQuery->first(['c.id']);

        if (! $warehouse || ! $item || ! $supplier || ! $contractor) {
            $this->markTestSkipped('purchase transmission master data is not available in test DB');
        }

        return [
            'warehouse_id' => (int) $warehouse->id,
            'item_id' => (int) $item->id,
            'item_code' => (string) $item->code,
            'supplier_id' => (int) $supplier->id,
            'contractor_id' => (int) $contractor->id,
        ];
    }

    private function createConfirmedSchedule(
        array $master,
        string $slipNumber,
        string $actualDate,
        int $quantity,
        ?int $supplierId = null,
        bool $useDefaultSupplier = true,
        OrderSource $orderSource = OrderSource::AUTO,
        bool $isReceiveMatched = false,
    ): WmsOrderIncomingSchedule {
        return WmsOrderIncomingSchedule::query()->create([
            'warehouse_id' => $master['warehouse_id'],
            'item_id' => $master['item_id'],
            'item_code' => $master['item_code'],
            'contractor_id' => $master['contractor_id'],
            'supplier_id' => $useDefaultSupplier ? ($supplierId ?? $master['supplier_id']) : null,
            'order_source' => $orderSource,
            'slip_number' => $slipNumber,
            'expected_quantity' => $quantity,
            'received_quantity' => $quantity,
            'quantity_type' => QuantityType::PIECE,
            'order_date' => '2026-07-19',
            'expected_arrival_date' => $actualDate,
            'actual_arrival_date' => $actualDate,
            'status' => IncomingScheduleStatus::CONFIRMED,
            'confirmed_at' => "{$actualDate} 09:00:00",
            'is_receive_matched' => $isReceiveMatched,
        ]);
    }

    private function newSlipNumber(string $suffix): string
    {
        return self::TEST_SLIP_PREFIX.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT).$suffix;
    }
}
