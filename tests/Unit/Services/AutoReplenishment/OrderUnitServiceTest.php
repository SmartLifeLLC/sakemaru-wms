<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\OrderUnitService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderUnitServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE items (id INTEGER,client_id INTEGER,is_active INTEGER)');
        $db->statement('CREATE TABLE item_quantity_information (item_id INTEGER,quantity INTEGER,can_order INTEGER)');
        $db->table('items')->insert([['id' => 1, 'client_id' => 6, 'is_active' => 1], ['id' => 2, 'client_id' => 7, 'is_active' => 1]]);
        $db->table('item_quantity_information')->insert([
            ['item_id' => 1, 'quantity' => 1, 'can_order' => 0], ['item_id' => 1, 'quantity' => 6, 'can_order' => 1],
            ['item_id' => 2, 'quantity' => 1, 'can_order' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    public function test_disabled_piece_master_uses_full_case_not_six_pack_or_historical_gcd(): void
    {
        $r = (new OrderUnitService)->resolve(6, 1, 24);
        self::assertFalse($r['piece_orderable']);
        self::assertSame('CASE', $r['quantity_type']);
        self::assertSame(24, $r['packing_unit']);
    }

    public function test_piece_permission_is_refreshed_and_existing_output_restriction_still_wins(): void
    {
        $s = new OrderUnitService;
        self::assertSame('CASE', $s->resolve(6, 1, 24)['quantity_type']);
        DB::connection('sakemaru')->table('item_quantity_information')->where('item_id', 1)->where('quantity', 1)->update(['can_order' => 1]);
        self::assertSame('PIECE', $s->resolve(6, 1, 24)['quantity_type']);
        self::assertSame(1, $s->resolve(6, 1, 24)['packing_unit']);
        self::assertSame('CASE', $s->resolve(6, 1, 24, true)['quantity_type']);
        self::assertSame('CASE', $s->resolve(6, 1, 1, true)['quantity_type']);
    }

    public function test_batch_read_is_client_scoped_and_reused_without_per_item_queries(): void
    {
        $s = new OrderUnitService;
        $db = DB::connection('sakemaru');
        $db->enableQueryLog();
        $rows = $s->rows(6, [1, 2]);
        self::assertSame([1 => [6]], $rows);
        $s->resolve(6, 1, 24, false, $rows);
        $s->resolve(6, 1, 24, false, $rows);
        self::assertCount(1, $db->getQueryLog());
        $this->expectException(\RuntimeException::class);
        $s->resolve(6, 2, 24);
    }

    public function test_unavailable_or_invalid_master_is_held_instead_of_guessing_a_unit(): void
    {
        DB::connection('sakemaru')->table('item_quantity_information')->where('item_id', 1)->update(['can_order' => 0]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('入数情報・発注区分');
        (new OrderUnitService)->resolve(6, 1, 24);
    }
}
