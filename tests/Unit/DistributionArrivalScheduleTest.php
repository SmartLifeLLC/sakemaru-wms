<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\DistributionProductController;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DistributionArrivalScheduleTest extends TestCase
{
    private Manager $db;

    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        $this->db = new Manager($app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sakemaru');
        $this->db->getDatabaseManager()->setDefaultConnection('sakemaru');
        $app->instance('db', $this->db->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $connection = $this->db->getConnection('sakemaru');
        $connection->statement('CREATE TABLE wms_order_incoming_schedules (
            id INTEGER PRIMARY KEY, item_id INTEGER, item_code TEXT, warehouse_id INTEGER,
            contractor_id INTEGER, supplier_id INTEGER, status TEXT, purchase_queue_id INTEGER,
            expected_quantity INTEGER, received_quantity INTEGER, quantity_type TEXT,
            order_date TEXT, expected_arrival_date TEXT, note TEXT, slip_number TEXT, purchase_slip_number TEXT
        )');
        $connection->statement('CREATE TABLE items (id INTEGER, capacity_case INTEGER, capacity_carton INTEGER)');
        $connection->statement('CREATE TABLE warehouses (id INTEGER, code TEXT, name TEXT)');
        $connection->statement('CREATE TABLE contractors (id INTEGER, code TEXT, name TEXT)');
        $connection->statement('CREATE TABLE suppliers (id INTEGER, partner_id INTEGER)');
        $connection->statement('CREATE TABLE partners (id INTEGER, code TEXT, name TEXT)');
        $connection->table('items')->insert(['id' => 184586, 'capacity_case' => 24, 'capacity_carton' => 6]);
    }

    protected function tearDown(): void
    {
        $this->db->getDatabaseManager()->purge('sakemaru');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function schedule(int $id, array $values = []): void
    {
        $this->db->getConnection('sakemaru')->table('wms_order_incoming_schedules')->insert(array_replace([
            'id' => $id, 'item_id' => 184586, 'item_code' => '184586',
            'warehouse_id' => 1, 'contractor_id' => 1330, 'supplier_id' => 1330,
            'status' => 'PENDING', 'purchase_queue_id' => null,
            'expected_quantity' => 24, 'received_quantity' => 0, 'quantity_type' => 'PIECE',
            'expected_arrival_date' => '2026-10-26',
        ], $values));
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(DistributionProductController::class, $method))
            ->invoke(new DistributionProductController, ...$arguments);
    }

    public function test_transmitted_rows_are_excluded_from_modal_and_both_summaries(): void
    {
        foreach ([24, 72, 48, 72, 36, 72, 72, 72, 36, 48, 48] as $index => $quantity) {
            $this->schedule($index + 1, ['expected_quantity' => $quantity]);
        }
        $this->schedule(12, ['status' => 'TRANSMITTED', 'purchase_queue_id' => 12202,
            'expected_quantity' => 4, 'quantity_type' => 'CASE', 'expected_arrival_date' => '2026-08-22']);
        $this->schedule(13, ['status' => 'TRANSMITTED', 'purchase_queue_id' => 12044,
            'expected_quantity' => 2, 'received_quantity' => 1, 'quantity_type' => 'CASE',
            'expected_arrival_date' => '2026-08-22']);

        foreach ([[184586, ''], [0, '184586']] as [$itemId, $code]) {
            $rows = $this->call('searchArrivalSchedules', $itemId, $code, 50);
            self::assertSame(range(1, 11), array_column($rows, 'id'));
            self::assertSame(600, array_sum(array_column($rows, 'totalPieces')));
        }
        $item = $this->call('getIncomingSummariesByItem', [1], [184586])->get(184586);
        self::assertSame(['incoming_quantity' => 600, 'incoming_count' => 11], $item);
        $contractor = $this->call('getIncomingSummariesByContractor', [1], [184586])->first();
        self::assertSame($item, $contractor);
    }

    public function test_only_outstanding_unlinked_pending_and_partial_rows_remain_before_limit(): void
    {
        $this->schedule(1, ['status' => 'TRANSMITTED']);
        $this->schedule(2, ['purchase_queue_id' => 10]);
        $this->schedule(3, ['status' => 'PARTIAL', 'received_quantity' => 1, 'purchase_queue_id' => 11]);
        foreach (['CONFIRMED', 'CANCELLED', 'PARTIAL_CANCELLED', 'DELETED'] as $index => $status) {
            $this->schedule($index + 4, ['status' => $status]);
        }
        $this->schedule(8, ['received_quantity' => 24]);
        $this->schedule(9, ['received_quantity' => 25]);
        $this->schedule(10, ['item_id' => 999, 'item_code' => '999']);
        $this->schedule(11, ['status' => 'PARTIAL', 'expected_quantity' => 3,
            'received_quantity' => 1, 'quantity_type' => 'CASE']);
        $this->schedule(12, ['expected_quantity' => 2, 'quantity_type' => 'CARTON']);
        $this->schedule(13, ['expected_quantity' => 5]);

        $rows = $this->call('searchArrivalSchedules', 184586, '', 50);
        self::assertSame([11, 12, 13], array_column($rows, 'id'));
        self::assertSame([48, 12, 5], array_column($rows, 'totalPieces'));
        self::assertSame([11], array_column($this->call('searchArrivalSchedules', 184586, '', 1), 'id'));
        foreach (['getIncomingSummariesByItem', 'getIncomingSummariesByContractor'] as $method) {
            $summary = $this->call($method, [1], [184586])->first();
            self::assertSame(3, $summary['incoming_count']);
            self::assertCount(0, $this->call($method, [2], [184586]));
        }
        self::assertSame([], $this->call('searchArrivalSchedules', 0, 'unknown', 50));
    }

    public function test_transmitted_only_item_has_no_modal_rows_or_incoming_icon_summary(): void
    {
        $this->schedule(1, ['status' => 'TRANSMITTED', 'purchase_queue_id' => 10]);
        self::assertSame([], $this->call('searchArrivalSchedules', 184586, '', 50));
        self::assertCount(0, $this->call('getIncomingSummariesByItem', [1], [184586]));
        self::assertCount(0, $this->call('getIncomingSummariesByContractor', [1], [184586]));
    }
}
