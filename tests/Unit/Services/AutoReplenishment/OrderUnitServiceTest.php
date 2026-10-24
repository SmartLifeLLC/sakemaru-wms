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

    public function test_explicit_case_selection_keeps_piece_permission_but_uses_case_packing(): void
    {
        $s = new OrderUnitService;
        $rows = [1 => [1]];
        $automatic = $s->resolve(6, 1, 15, rows: $rows);
        $case = $s->resolve(6, 1, 15, rows: $rows, mode: 'CASE');
        self::assertSame('PIECE', $automatic['quantity_type']);
        self::assertTrue($case['piece_orderable']);
        self::assertSame('バラ発注', $case['automatic_label']);
        self::assertSame('CASE', $case['quantity_type']);
        self::assertSame(15, $case['packing_unit']);
        self::assertSame(1, $s->resolve(6, 1, 15, rows: $rows, mode: 'PIECE')['packing_unit']);
    }

    public function test_piece_override_cannot_bypass_master_or_eos_output_restrictions(): void
    {
        $s = new OrderUnitService;
        foreach ([[1 => [15]], [1 => [1]]] as $i => $rows) {
            try {
                $s->resolve(6, 1, 15, caseOutput: $i === 1, rows: $rows, mode: 'PIECE');
                self::fail('Forbidden piece selection was accepted.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('バラ発注できません', $e->getMessage());
            }
        }
    }

    public function test_old_basis_remains_automatic_but_changed_selection_needs_new_calculation(): void
    {
        $s = new OrderUnitService;
        $s->assertRecommendationMode(['scope' => []], 'AUTO');
        $s->assertRecommendationMode(null, 'CASE');
        $s->assertRecommendationMode(['scope' => ['order_unit_mode' => 'CASE']], 'CASE');
        $this->expectExceptionMessage('発注単位が算定時から変わっています');
        $s->assertRecommendationMode(['scope' => []], 'CASE');
    }

    public function test_invalid_unit_cannot_be_silently_treated_as_automatic(): void
    {
        $this->expectExceptionMessage('自動判定・バラ発注・ケース発注');
        (new OrderUnitService)->resolve(6, 1, 24, mode: 'CARTON');
    }

    public function test_logistics_case_majority_is_default_and_an_explicit_piece_selection_is_preserved(): void
    {
        $s = new OrderUnitService;
        $history = ['case_count' => 227, 'piece_count' => 0];
        $auto = $s->resolve(6, 1, 15, rows: [1 => [1]], history: $history);
        self::assertTrue($auto['piece_orderable']);
        self::assertSame('CASE', $auto['quantity_type']);
        self::assertSame(15, $auto['packing_unit']);
        self::assertSame('ケース発注', $auto['automatic_label']);
        self::assertStringContainsString('ケース単位 227回', $auto['reason']);
        $manual = $s->resolve(6, 1, 15, rows: [1 => [1]], mode: 'PIECE', history: $history);
        self::assertSame('PIECE', $manual['quantity_type']);
        self::assertSame(1, $manual['packing_unit']);
        self::assertSame('ケース発注', $manual['automatic_label']);
    }

    public function test_tied_or_missing_history_retains_piece_default_and_history_never_overrides_output_restrictions(): void
    {
        $s = new OrderUnitService;
        foreach ([[0, 0], [2, 2], [1, 3]] as [$cases, $pieces]) {
            $history = ['case_count' => $cases, 'piece_count' => $pieces];
            self::assertSame('PIECE', $s->resolve(6, 1, 15, rows: [1 => [1]], history: $history)['quantity_type']);
            self::assertSame('CASE', $s->resolve(6, 1, 15, rows: [1 => [15]], history: $history)['quantity_type']);
            self::assertSame('CASE', $s->resolve(6, 1, 15, caseOutput: true, rows: [1 => [1]], history: $history)['quantity_type']);
        }
    }
}
