<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\PolicyService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WarehouseEnablementTest extends TestCase
{
    #[DataProvider('switchStates')]
    public function test_warehouse_switch_can_be_saved_without_querying_product_settings(bool $enabled): void
    {
        $connection = Mockery::mock(Connection::class);
        DB::shouldReceive('connection')->with('sakemaru')->andReturn($connection);
        $connection->shouldReceive('transaction')->once()->withArgs(fn ($callback, $attempts) => $attempts === 3)
            ->andReturnUsing(fn ($callback) => $callback());

        $warehouses = Mockery::mock(Builder::class);
        $connection->shouldReceive('table')->with('warehouses')->once()->andReturn($warehouses);
        $warehouses->shouldReceive('where')->with(['id' => 3, 'client_id' => 6, 'is_active' => 1, 'is_virtual' => 0])->once()->andReturnSelf();
        $warehouses->shouldReceive('exists')->once()->andReturnTrue();

        $settings = Mockery::mock(Builder::class);
        $connection->shouldReceive('table')->with('wms_warehouse_auto_order_settings')->twice()->andReturn($settings);
        $settings->shouldReceive('where')->with('warehouse_id', 3)->once()->andReturnSelf();
        $settings->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $settings->shouldReceive('first')->once()->andReturnNull();
        $settings->shouldReceive('updateOrInsert')->once()->withArgs(fn ($key, $values) => $key === ['warehouse_id' => 3] && $values['is_auto_order_enabled'] === $enabled && $values['replenishment_version'] === 1)
            ->andReturnTrue();

        $controls = Mockery::mock(Builder::class);
        $connection->shouldReceive('table')->with('wms_auto_order_controls')->once()->andReturn($controls);
        $controls->shouldReceive('where')->with('client_id', 6)->once()->andReturnSelf();
        $controls->shouldReceive('update')->once()->withArgs(fn ($values) => $values['generation'] === 9)->andReturn(1);

        $guard = Mockery::mock(ReplenishmentGuard::class);
        $guard->shouldReceive('control')->with(6)->once()->andReturn((object) ['reset_state' => 'COMPLETE', 'generation' => 8]);
        $guard->shouldReceive('event')->once()->withArgs(fn ($client, $event, $payload, $lineId, $actorId) => $client === 6 && $event === 'WAREHOUSE_SETTINGS_CHANGED' && $payload['enabled'] === $enabled && $actorId === 10);

        // No product table/model is stubbed: adding a product prerequisite again fails this test.
        (new PolicyService($guard))->saveWarehouse(6, 3, [
            'version' => 0, 'generation' => 8, 'execution_time' => '10:00',
            'sunday_execution_time' => '22:00', 'weekdays' => [0, 1, 2, 3, 4, 5, 6],
        ], $enabled, 10);
        $this->addToAssertionCount(1);
    }

    public static function switchStates(): array
    {
        return ['warehouse ON without products' => [true], 'warehouse OFF without products' => [false]];
    }
}
