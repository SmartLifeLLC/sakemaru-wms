<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\QuantityType;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\EosIncomingAutoReceiveService;
use App\Services\AutoOrder\IncomingTransmissionService;
use App\Services\JX\Eos\JxEosIncomingWorkflowService;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class EosIncomingAutoReceiveServiceTest extends TestCase
{
    private const TEST_SLIP_PREFIX = 'EOSPERF';

    protected function tearDown(): void
    {
        WmsOrderIncomingSchedule::query()
            ->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'%')
            ->delete();

        parent::tearDown();
    }

    public function test_complete_schedules_as_shortage_deletes_old_purchase_schedules(): void
    {
        $master = $this->incomingMasterData();

        $pending = $this->createSchedule($master, 'A', IncomingScheduleStatus::PENDING, expectedQuantity: 5, receivedQuantity: 2);
        $partial = $this->createSchedule($master, 'B', IncomingScheduleStatus::PARTIAL, expectedQuantity: 7, receivedQuantity: 0);
        $confirmed = $this->createSchedule($master, 'C', IncomingScheduleStatus::CONFIRMED, expectedQuantity: 9, receivedQuantity: 9);

        $service = new EosIncomingAutoReceiveService(
            $this->createMock(JxEosIncomingWorkflowService::class),
            $this->createMock(IncomingTransmissionService::class),
        );
        $method = new ReflectionMethod($service, 'completeSchedulesAsShortage');
        $method->setAccessible(true);

        $completed = $method->invoke(
            $service,
            WmsOrderIncomingSchedule::query()->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'%')
        );

        $this->assertSame(2, $completed);

        $pending->refresh();
        $partial->refresh();
        $confirmed->refresh();

        $this->assertSame(IncomingScheduleStatus::DELETED, $pending->status);
        $this->assertSame(0, $pending->received_quantity);
        $this->assertSame(0, $pending->shipped_quantity);
        $this->assertSame(5, $pending->shortage_quantity);
        $this->assertSame('2026-07-01', $pending->actual_arrival_date?->format('Y-m-d'));
        $this->assertSame(0, $pending->confirmed_by);
        $this->assertNotNull($pending->confirmed_at);

        $this->assertSame(IncomingScheduleStatus::DELETED, $partial->status);
        $this->assertSame(0, $partial->received_quantity);
        $this->assertSame(0, $partial->shipped_quantity);
        $this->assertSame(7, $partial->shortage_quantity);
        $this->assertSame('2026-07-01', $partial->actual_arrival_date?->format('Y-m-d'));
        $this->assertSame(0, $partial->confirmed_by);
        $this->assertNotNull($partial->confirmed_at);

        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $confirmed->status);
        $this->assertSame(9, $confirmed->received_quantity);
        $this->assertSame(0, $confirmed->shortage_quantity);
        $this->assertNull($confirmed->confirmed_by);
    }

    public function test_complete_schedules_as_received_marks_transfer_schedules_completed_without_shortage(): void
    {
        $master = $this->incomingMasterData();

        $pending = $this->createSchedule($master, 'T1', IncomingScheduleStatus::PENDING, expectedQuantity: 5, receivedQuantity: 0, orderSource: OrderSource::TRANSFER);
        $partial = $this->createSchedule($master, 'T2', IncomingScheduleStatus::PARTIAL, expectedQuantity: 7, receivedQuantity: 3, orderSource: OrderSource::TRANSFER);
        $confirmed = $this->createSchedule($master, 'T3', IncomingScheduleStatus::CONFIRMED, expectedQuantity: 9, receivedQuantity: 9, orderSource: OrderSource::TRANSFER);

        $service = new EosIncomingAutoReceiveService(
            $this->createMock(JxEosIncomingWorkflowService::class),
            $this->createMock(IncomingTransmissionService::class),
        );
        $method = new ReflectionMethod($service, 'completeSchedulesAsReceived');
        $method->setAccessible(true);

        $completed = $method->invoke(
            $service,
            WmsOrderIncomingSchedule::query()->where('slip_number', 'like', self::TEST_SLIP_PREFIX.'T%')
        );

        $this->assertSame(2, $completed);

        $pending->refresh();
        $partial->refresh();
        $confirmed->refresh();

        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $pending->status);
        $this->assertSame(5, $pending->received_quantity);
        $this->assertSame(5, $pending->shipped_quantity);
        $this->assertSame(0, $pending->shortage_quantity);
        $this->assertNull($pending->purchase_queue_id);
        $this->assertSame('2026-07-01', $pending->actual_arrival_date?->format('Y-m-d'));
        $this->assertSame(0, $pending->confirmed_by);
        $this->assertNotNull($pending->confirmed_at);

        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $partial->status);
        $this->assertSame(7, $partial->received_quantity);
        $this->assertSame(7, $partial->shipped_quantity);
        $this->assertSame(0, $partial->shortage_quantity);
        $this->assertNull($partial->purchase_queue_id);
        $this->assertSame('2026-07-01', $partial->actual_arrival_date?->format('Y-m-d'));

        $this->assertSame(IncomingScheduleStatus::CONFIRMED, $confirmed->status);
        $this->assertSame(9, $confirmed->received_quantity);
        $this->assertSame(0, $confirmed->shortage_quantity);
        $this->assertNull($confirmed->confirmed_by);
    }

    private function incomingMasterData(): array
    {
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('id')
            ->first(['id']);
        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('is_active', true)
            ->whereNotNull('code')
            ->orderBy('id')
            ->first(['id', 'code']);
        $contractor = DB::connection('sakemaru')
            ->table('contractors')
            ->where('is_active', true)
            ->orderBy('id')
            ->first(['id']);

        if (! $warehouse || ! $item || ! $contractor) {
            $this->markTestSkipped('incoming schedule master data is not available in test DB');
        }

        return [
            'warehouse_id' => (int) $warehouse->id,
            'item_id' => (int) $item->id,
            'item_code' => (string) $item->code,
            'contractor_id' => (int) $contractor->id,
        ];
    }

    private function createSchedule(
        array $master,
        string $suffix,
        IncomingScheduleStatus $status,
        int $expectedQuantity,
        int $receivedQuantity,
        OrderSource $orderSource = OrderSource::AUTO,
    ): WmsOrderIncomingSchedule {
        return WmsOrderIncomingSchedule::query()->create([
            'warehouse_id' => $master['warehouse_id'],
            'item_id' => $master['item_id'],
            'item_code' => $master['item_code'],
            'contractor_id' => $master['contractor_id'],
            'order_source' => $orderSource,
            'slip_number' => self::TEST_SLIP_PREFIX.$suffix.random_int(100000, 999999),
            'expected_quantity' => $expectedQuantity,
            'received_quantity' => $receivedQuantity,
            'shortage_quantity' => max(0, $expectedQuantity - $receivedQuantity),
            'quantity_type' => QuantityType::PIECE,
            'order_date' => '2026-06-30',
            'expected_arrival_date' => '2026-07-01',
            'actual_arrival_date' => $status === IncomingScheduleStatus::CONFIRMED ? '2026-07-01' : null,
            'status' => $status,
            'confirmed_at' => $status === IncomingScheduleStatus::CONFIRMED ? '2026-07-01 09:00:00' : null,
        ]);
    }
}
