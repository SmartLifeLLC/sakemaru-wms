<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsAutoOrderRunLine;
use App\Services\AutoReplenishment\BudgetService;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class BudgetServiceTest extends TestCase
{
    private function entries(): Builder
    {
        $connection = Mockery::mock(Connection::class);
        DB::shouldReceive('connection')->with('sakemaru')->andReturn($connection);
        $entries = Mockery::mock(Builder::class);
        // Any query of warehouse/client limits or aggregate totals would fail this expectation.
        $connection->shouldReceive('table')->with('wms_auto_order_budget_entries')->andReturn($entries);
        $entries->shouldReceive('where')->with('effect_key', 'test-order')->andReturnSelf();

        return $entries;
    }

    public function test_accounting_does_not_require_or_apply_warehouse_and_client_limits(): void
    {
        $entries = $this->entries();
        $entries->shouldReceive('first')->once()->andReturnNull();
        $entries->shouldReceive('insert')->once()->withArgs(fn ($row) => $row['quantity_pieces'] === 240 && $row['amount'] === '0' && $row['state'] === 'RESERVED')->andReturnTrue();
        $line = Mockery::mock(WmsAutoOrderRunLine::class)->makePartial();
        $line->forceFill(['effect_key' => 'test-order', 'client_id' => 6, 'warehouse_id' => 3,
            'item_id' => 1, 'quantity_pieces' => 240]);
        $line->shouldReceive('update')->once()->with(['amount' => '0'])->andReturnTrue();
        (new BudgetService)->reserve($line, '0');
        $this->addToAssertionCount(1);
    }

    public function test_confirmation_does_not_depend_on_legacy_limit_settings(): void
    {
        $entries = $this->entries();
        $entries->shouldReceive('where')->with('state', 'RESERVED')->once()->andReturnSelf();
        $entries->shouldReceive('update')->once()->withArgs(fn ($row) => $row['state'] === 'COMMITTED')->andReturn(1);
        (new BudgetService)->commit(new WmsAutoOrderRunLine(['effect_key' => 'test-order']));
        $this->addToAssertionCount(1);
    }

    public function test_removing_aggregate_caps_does_not_allow_cancelled_orders_to_be_recreated(): void
    {
        $entries = $this->entries();
        $entries->shouldReceive('first')->once()->andReturn((object) ['state' => 'RELEASED']);
        $this->expectExceptionMessage('中止済みの発注は再実行できません。');
        (new BudgetService)->reserve(new WmsAutoOrderRunLine(['effect_key' => 'test-order']), '0');
    }
}
