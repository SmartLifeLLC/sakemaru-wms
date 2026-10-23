<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Filament\Pages\AutoReplenishmentPage;
use App\Models\User;
use App\Services\AutoReplenishment\InventoryProjectionInputs;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Support\Facades\DB;
use Mockery;
use Sakemaru\Auth\Services\PermissionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InventoryProjectionPageTest extends TestCase
{
    private bool $allowEdit = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        DB::connection('sakemaru')->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, is_active INTEGER, is_virtual INTEGER)');
        DB::connection('sakemaru')->table('warehouses')->insert(['id' => 1, 'client_id' => 6, 'is_active' => 1, 'is_virtual' => 0]);
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['id' => 1, 'client_id' => 6]);
        $user->shouldReceive('fresh')->andReturnSelf();
        $user->shouldReceive('getSelectedWarehouseId')->andReturn(1);
        $this->be($user);
        $guard = Mockery::mock(ReplenishmentGuard::class);
        $guard->shouldReceive('clientId')->andReturn(6);
        $this->app->instance(ReplenishmentGuard::class, $guard);
        $permissions = Mockery::mock(PermissionService::class);
        $permissions->shouldReceive('check')->andReturnUsing(fn ($user, $p) => ! str_ends_with($p, '.edit') || $this->allowEdit);
        $this->app->instance(PermissionService::class, $permissions);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function page(): AutoReplenishmentPage
    {
        $page = new InventoryProjectionPageFixture;
        $page->showEditor = true;
        $page->warehouseId = 1;
        $page->form = InventoryProjectionTest::policy() + ['item_id' => 123, 'contractor_id' => 10, 'supplier_id' => 10,
            'source_warehouse_id' => 91, 'delivery_course_id' => 101, 'order_unit_mode' => 'AUTO', 'shipping_unit_mode' => 'STANDARD'];
        $page->editorRoute = array_intersect_key($page->form, array_flip(['item_id', 'channel', 'contractor_id', 'supplier_id', 'source_warehouse_id', 'delivery_course_id']));
        $page->recommendation = ['basis' => $page->form['calculation_basis']];

        return $page;
    }

    public function test_edits_reuse_one_read_and_refresh_is_explicit_with_no_writes(): void
    {
        $reader = Mockery::mock(InventoryProjectionInputs::class);
        $reader->shouldReceive('read')->twice()->andReturn(InventoryProjectionTest::inputs());
        $this->app->instance(InventoryProjectionInputs::class, $reader);
        $page = $this->page();
        DB::connection('sakemaru')->enableQueryLog();
        $page->refreshInventoryProjection();
        $before = $page->inventoryProjection;
        $page->form['max_order_pieces'] = 20;
        $page->form['target_stock_pieces'] = 70;
        $page->refreshInventoryProjection();
        self::assertNotEquals($before, $page->inventoryProjection);
        self::assertFalse($page->form['is_enabled']);
        $page->form['max_order_pieces'] = '';
        $page->refreshInventoryProjection();
        self::assertNull($page->inventoryProjection);
        self::assertNotNull($page->inventoryProjectionError);
        $page->form['max_order_pieces'] = 20;
        $page->refreshInventoryProjection(true);
        self::assertNotNull($page->inventoryProjection);
        foreach (DB::connection('sakemaru')->getQueryLog() as $q) {
            self::assertStringStartsWith('select', strtolower($q['query']));
        }
    }

    public function test_permission_is_checked_even_when_using_cached_inputs(): void
    {
        $page = $this->page();
        $page->inventoryProjectionInputs = InventoryProjectionTest::inputs();
        $this->allowEdit = false;
        $this->expectException(HttpException::class);
        $page->refreshInventoryProjection();
    }

    public function test_closed_editor_cannot_be_used_to_load_stock(): void
    {
        $page = $this->page();
        $page->showEditor = false;
        $this->expectException(HttpException::class);
        $page->refreshInventoryProjection();
    }
}

class InventoryProjectionPageFixture extends AutoReplenishmentPage {}
