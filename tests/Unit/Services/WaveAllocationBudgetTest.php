<?php

namespace Tests\Unit\Services;

use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Wave;
use App\Models\WaveSetting;
use App\Services\StockAllocationService;
use App\Services\StockTransferLotAllocationService;
use App\Services\WaveAllocationBudget;
use App\Services\WaveGroupGenerationService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WaveAllocationBudgetTest extends TestCase
{
    use \Tests\Support\WaveAllocationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAllocationDatabase();
    }

    #[Test]
    public function six_pieces_are_shared_across_two_orders_and_different_courses(): void
    {
        [$item, $stock, $location] = $this->stock(6, 12);
        $physicalLot = $this->lot($stock, $location, 6);
        $firstEarning = random_int(8000000, 8999999);
        $secondEarning = $firstEarning + 1;
        $firstLine = $this->line($item, 6);
        $secondLine = $this->line($item, 6);
        $this->rsle($this->lot($stock, $location, 0, 6), $firstEarning, $firstLine->id, 6);
        $this->rsle($this->lot($stock, $location, 0, 6), $secondEarning, $secondLine->id, 6);
        $budget = new WaveAllocationBudget([$firstEarning, $secondEarning]);

        $first = (new StockAllocationService($budget))->allocateForItem(800001, 91, $item, 6, 'PIECE', $firstEarning, $firstLine->id);
        $second = (new StockAllocationService($budget))->allocateForItem(800002, 91, $item, 6, 'PIECE', $secondEarning, $secondLine->id);
        $retry = (new StockAllocationService($budget))->allocateForItem(800001, 91, $item, 6, 'PIECE', $firstEarning, $firstLine->id);

        $this->assertSame([6, 0], [$first['allocated'], $first['shortage']]);
        $this->assertSame([0, 6], [$second['allocated'], $second['shortage']]);
        $this->assertSame(6, $retry['allocated']);
        $this->assertSame(0, $budget->remainingPieces($stock));
        $this->assertSame(6, (int) DB::connection('sakemaru')->table('wms_reservations')->where('item_id', $item)->sum('qty_each'));
        $this->assertSame(6, (int) DB::connection('sakemaru')->table('real_stock_lots')->where('id', $physicalLot)->value('current_quantity'));
        $this->assertSame(12, (int) DB::connection('sakemaru')->table('real_stocks')->where('id', $stock)->value('reserved_quantity'));
    }

    #[Test]
    public function two_fully_backed_core_reservations_are_not_double_deducted(): void
    {
        [$item, $stock, $location] = $this->stock(12, 12);
        $lot = $this->lot($stock, $location, 12, 12);
        $earnings = [8100001, 8100002];
        $lines = [$this->line($item, 6), $this->line($item, 6)];
        foreach ($earnings as $key => $earning) {
            $this->rsle($lot, $earning, $lines[$key]->id, 6);
        }
        $budget = new WaveAllocationBudget($earnings);
        foreach ($earnings as $key => $earning) {
            $result = (new StockAllocationService($budget))->allocateForItem(810001, 91, $item, 6, 'PIECE', $earning, $lines[$key]->id);
            $this->assertSame(6, $result['allocated']);
            $this->assertSame(0, $result['shortage']);
        }
        $this->assertSame(0, $budget->remainingPieces($stock));
    }

    #[Test]
    public function thirty_seven_pieces_cover_two_and_thirty_five_with_one_shortage(): void
    {
        [$item, $stock, $location] = $this->stock(-1, 0, 12);
        $source = $this->lot($stock, $location, 0, 0, 'DEPLETED');
        $this->lot($stock, $location, -1);
        $firstLine = $this->line($item, 2);
        $secondLine = $this->line($item, 3, 'CASE', 12);
        $this->stla(8200001, $firstLine->id, $source, 2);
        $this->stla(8200002, $secondLine->id, $source, 36);
        $budget = new WaveAllocationBudget([], [8200001, 8200002]);
        $service = new StockTransferLotAllocationService($budget);

        $first = $service->allocateForTradeItem(820001, 91, 8200001, $firstLine);
        $second = $service->allocateForTradeItem(820002, 91, 8200002, $secondLine);
        $retry = $service->allocateForTradeItem(820002, 91, 8200002, $secondLine);

        $this->assertSame(2, $first['allocated']);
        $this->assertSame(35, $second['allocated']);
        $this->assertSame([35, 1, 'PIECE', 36], [$second['allocated'], $second['shortage'], $second['qty_type'], $second['ordered_qty']]);
        $this->assertSame([35, 1, 'PIECE', 36], [$retry['allocated'], $retry['shortage'], $retry['qty_type'], $retry['ordered_qty']]);
        $this->assertSame(0, $budget->remainingPieces($stock));
        $this->assertSame(37, (int) DB::connection('sakemaru')->table('wms_reservations')->where('item_id', $item)->sum('qty_each'));
        $this->assertSame(-1, (int) DB::connection('sakemaru')->table('real_stocks')->where('id', $stock)->value('current_quantity'));
    }

    #[Test]
    public function repointed_positive_lot_cannot_hide_one_hundred_seventy_two_missing_pieces(): void
    {
        [$item, $stock, $location] = $this->stock(-32, 140, 20);
        $positive = $this->lot($stock, $location, 134, 134);
        $negative = $this->lot($stock, $location, -166, 6);
        $this->rsle($positive, 8300099, 8300099, 134);
        $this->rsle($negative, 8300099, 8300099, 6);
        $line = $this->line($item, 9, 'CASE', 20);
        $this->stla(8300001, $line->id, $positive, 8);
        $this->stla(8300001, $line->id, $positive, 172);

        $result = (new StockTransferLotAllocationService(new WaveAllocationBudget([], [8300001])))
            ->allocateForTradeItem(830001, 91, 8300001, $line);

        $this->assertSame(8, $result['allocated']);
        $this->assertSame([8, 172, 'PIECE', 180], [$result['allocated'], $result['shortage'], $result['qty_type'], $result['ordered_qty']]);
    }

    #[Test]
    public function positive_lot_and_negative_parent_do_not_cover_two_hundred_pieces(): void
    {
        [$item, $stock, $location] = $this->stock(-190, 160, 20);
        $positive = $this->lot($stock, $location, 10, 10);
        $this->lot($stock, $location, -200);
        $empty = $this->lot($stock, $location, 0, 150);
        $this->rsle($positive, 8400099, 8400099, 10);
        $this->rsle($empty, 8400099, 8400099, 150);
        $line = $this->line($item, 10, 'CASE', 20);
        $this->stla(8400001, $line->id, $positive, 200);

        $result = (new StockTransferLotAllocationService(new WaveAllocationBudget([], [8400001])))
            ->allocateForTradeItem(840001, 91, 8400001, $line);

        $this->assertSame(0, $result['allocated']);
        $this->assertSame([0, 10, 'CASE'], [$result['allocated'], $result['shortage'], $result['qty_type']]);
    }

    #[Test]
    public function foreign_reservations_and_parent_lot_mismatches_limit_stock(): void
    {
        [$item, $stock, $location] = $this->stock(10, 6);
        $lot = $this->lot($stock, $location, 12, 6);
        $this->rsle($lot, 8500099, 8500099, 6);
        $line = $this->line($item, 12);
        $result = (new StockAllocationService(new WaveAllocationBudget([8500001])))
            ->allocateForItem(850001, 91, $item, 12, 'PIECE', 8500001, $line->id);

        $this->assertSame([4, 8], [$result['allocated'], $result['shortage']]);
    }

    #[Test]
    public function unmanaged_transfer_remains_allocatable_from_a_negative_lot(): void
    {
        [$item, $stock, $location] = $this->stock(-10, 0, 12, false);
        $lot = $this->lot($stock, $location, -10);
        $line = $this->line($item, 10);
        $this->stla(8600001, $line->id, $lot, 10);
        $result = (new StockTransferLotAllocationService(new WaveAllocationBudget([], [8600001])))
            ->allocateForTradeItem(860001, 91, 8600001, $line);
        $this->assertSame([10, 0], [$result['allocated'], $result['shortage']]);
    }

    #[Test]
    public function separate_transfer_service_instances_share_saved_reservation_claims(): void
    {
        [$item, $stock, $location] = $this->stock(-1, 0);
        $source = $this->lot($stock, $location, -1);
        $firstLine = $this->line($item, 2);
        $secondLine = $this->line($item, 3, 'CASE', 12);
        $this->stla(8700001, $firstLine->id, $source, 2);
        $this->stla(8700001, $secondLine->id, $source, 36);
        $first = (new StockTransferLotAllocationService)->allocateForTradeItem(870001, 91, 8700001, $firstLine);
        $second = (new StockTransferLotAllocationService)->allocateForTradeItem(870001, 91, 8700001, $secondLine);
        $this->assertSame(2, $first['allocated']);
        $this->assertSame([35, 1, 'PIECE'], [$second['allocated'], $second['shortage'], $second['qty_type']]);
    }

    #[Test]
    public function malformed_other_transfer_allocations_cannot_inflate_the_budget(): void
    {
        [$item, $stock, $location] = $this->stock(-16, 0);
        $source = $this->lot($stock, $location, -16);
        $validLine = $this->line($item, 6);
        $invalidLine = $this->line($item, 10);
        $this->stla(8800001, $validLine->id, $source, 6);
        $this->stla(8800002, $invalidLine->id, $source, 100);
        $budget = new WaveAllocationBudget([], [8800001, 8800002]);
        $result = (new StockTransferLotAllocationService($budget))->allocateForTradeItem(880001, 91, 8800001, $validLine);
        $this->assertSame([0, 6], [$result['allocated'], $result['shortage']]);
    }

    #[Test]
    public function prepare_rejects_sources_that_another_job_has_started(): void
    {
        DB::connection('sakemaru')->table('earnings')->insert([
            'id' => 8900001, 'trade_id' => 8900001, 'is_active' => 1, 'is_delivered' => 0, 'picking_status' => 'BEFORE_PICKING',
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('生成対象の伝票が変更された');
        (new WaveAllocationBudget([8900001]))->prepare(91);
    }

    #[Test]
    public function prepare_rejects_duplicate_reservations_before_creating_waves(): void
    {
        $db = DB::connection('sakemaru');
        $db->table('earnings')->insert([
            'id' => 8900002, 'trade_id' => 8900002, 'is_active' => 1, 'is_delivered' => 0, 'picking_status' => 'BEFORE',
        ]);
        $db->table('wms_reservations')->insert([
            'source_type' => 'EARNING', 'source_id' => 8900002, 'status' => 'RESERVED',
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('既に在庫予約があります');
        (new WaveAllocationBudget([8900002]))->prepare(91);
    }

    #[Test]
    public function empty_generation_scope_is_valid_and_cannot_allocate_missing_stock(): void
    {
        $budget = new WaveAllocationBudget;
        $budget->prepare(91);
        $this->assertSame(0, $budget->remainingPieces(9999999));
    }

    #[Test]
    public function carton_partial_shortage_uses_pieces_consistently(): void
    {
        [$item, $stock, $location] = $this->stock(-4, 0, 6);
        $source = $this->lot($stock, $location, -4);
        $line = $this->line($item, 2, 'CARTON', 6);
        $this->stla(8900003, $line->id, $source, 12);
        $result = (new StockTransferLotAllocationService(new WaveAllocationBudget([], [8900003])))
            ->allocateForTradeItem(890003, 91, 8900003, $line);
        $this->assertSame([8, 4, 'PIECE', 12], [$result['allocated'], $result['shortage'], $result['qty_type'], $result['ordered_qty']]);
    }

    #[Test]
    public function generation_persists_partial_case_order_and_shortage_in_the_same_unit(): void
    {
        [$item, $stock, $location] = $this->stock(-1, 0, 12);
        $source = $this->lot($stock, $location, -1);
        $line = $this->line($item, 3, 'CASE', 12);
        $this->stla(8900004, $line->id, $source, 36);
        $db = DB::connection('sakemaru');
        $db->table('locations')->where('id', $location)->update(['floor_id' => 1, 'wms_picking_area_id' => 1]);
        $db->table('trades')->insert(['id' => $line->trade_id, 'serial_id' => 1]);
        $db->table('stock_transfers')->insert(['id' => 8900004, 'trade_id' => $line->trade_id, 'picking_status' => 'BEFORE']);
        $wave = new Wave;
        $wave->id = 890004;
        $course = new DeliveryCourse(['warehouse_id' => 91, 'code' => 1]);
        $course->id = 1;
        $setting = new WaveSetting(['delivery_course_id' => 1]);
        $setting->setRelation('deliveryCourse', $course);
        $service = new class extends WaveGroupGenerationService
        {
            public function process(Wave $wave, WaveSetting $setting, $transfers, $course): void
            {
                $this->processStockTransfersForWave($wave, $setting, $transfers, (object) ['code' => 91], $course, '2026-10-12');
            }
        };
        $service->process($wave, $setting, collect([(object) ['id' => 8900004, 'trade_id' => $line->trade_id]]), $course);
        $row = $db->table('wms_picking_item_results')->where('trade_item_id', $line->id)->first();
        $this->assertSame([36, 35, 1], [(int) $row->ordered_qty, (int) $row->planned_qty, (int) $row->shortage_qty]);
        $this->assertSame(['PIECE', 'PIECE', 'PIECE'], [$row->ordered_qty_type, $row->planned_qty_type, $row->picked_qty_type]);
    }

    #[Test]
    public function more_than_one_hundred_eligible_lots_do_not_create_a_false_shortage(): void
    {
        [$item, $stock, $location] = $this->stock(101, 0);
        for ($index = 0; $index < 101; $index++) {
            $this->lot($stock, $location, 1);
        }
        $line = $this->line($item, 101);
        $result = (new StockAllocationService)->allocateForItem(890020, 91, $item, 101, 'PIECE', 8900020, $line->id);
        $this->assertSame([101, 0], [$result['allocated'], $result['shortage']]);
    }

    #[Test]
    public function recalculation_reloads_only_the_target_once_and_preserves_consumed_stock(): void
    {
        [$item, $stock, $location] = $this->stock(12, 0);
        $lot = $this->lot($stock, $location, 12);
        [$otherItem, $otherStock, $otherLocation] = $this->stock(20, 0);
        $otherLot = $this->lot($otherStock, $otherLocation, 20);
        $budget = new WaveAllocationBudget;
        $budget->prepare(91);
        $budget->consume($stock, 4, $lot);
        $budget->consume($otherStock, 3, $otherLot);
        $db = DB::connection('sakemaru');
        $db->table('real_stocks')->where('id', $stock)->update(['current_quantity' => 10]);
        $db->table('real_stock_lots')->where('id', $lot)->update(['current_quantity' => 10]);
        $db->table('real_stocks')->where('id', $otherStock)->update(['current_quantity' => 25]);
        $db->table('real_stock_lots')->where('id', $otherLot)->update(['current_quantity' => 25]);
        $this->assertTrue($budget->recalculateItem(91, $item));
        $this->assertSame(6, $budget->remainingPieces($stock));
        $this->assertSame(17, $budget->remainingPieces($otherStock));
        $this->assertSame(6, $budget->lotAvailablePieces($lot, 10));
        $db->table('real_stocks')->where('id', $stock)->update(['current_quantity' => 100]);
        $db->table('real_stock_lots')->where('id', $lot)->update(['current_quantity' => 100]);
        $this->assertFalse($budget->recalculateItem(91, $item));
        $this->assertSame(6, $budget->remainingPieces($stock));
        $this->assertCount(1, $budget->recalculations());
    }

    #[Test]
    public function failed_recalculation_keeps_its_once_only_marker_and_restores_the_previous_budget(): void
    {
        [$item, $stock, $location] = $this->stock(12, 0);
        $lot = $this->lot($stock, $location, 12);
        $budget = new WaveAllocationBudget;
        $budget->prepare(91);
        $budget->consume($stock, 4, $lot);
        $pdo = DB::connection('sakemaru')->getPdo();
        $pdo->exec('ALTER TABLE real_stock_lots RENAME TO test_missing_real_stock_lots');
        try {
            $budget->recalculateItem(91, $item);
            $this->fail('The injected read error should fail the recalculation');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(8, $budget->remainingPieces($stock));
        } finally {
            $pdo->exec('ALTER TABLE test_missing_real_stock_lots RENAME TO real_stock_lots');
        }
        $this->assertFalse($budget->recalculateItem(91, $item));
        $this->assertSame(8, $budget->remainingPieces($stock));
        $this->assertCount(1, $budget->recalculations());
        $this->assertFalse($budget->recalculations()[0]['completed']);
    }
}
