<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Filament\Pages\AutoReplenishmentPage;
use App\Models\User;
use App\Services\AutoReplenishment\OrderRouteEligibility;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use App\Services\AutoReplenishment\SettingsRecommendationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Sakemaru\Auth\Services\PermissionService;
use Tests\TestCase;

class ReadonlyOrderRoutePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, is_active INTEGER, is_virtual INTEGER)');
        $db->statement('CREATE TABLE wms_item_reorder_policies (id INTEGER, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, channel TEXT, contractor_id INTEGER, supplier_id INTEGER, source_warehouse_id INTEGER, delivery_course_id INTEGER, strategy_key TEXT, is_enabled INTEGER, order_multiple_pieces INTEGER, calculation_basis TEXT)');
        $db->statement('CREATE TABLE item_contractors (client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER, supplier_id INTEGER)');
        $db->statement('CREATE TABLE contractors (id INTEGER, client_id INTEGER, is_active INTEGER)');
        $db->statement('CREATE TABLE wms_contractor_settings (contractor_id INTEGER, transmission_type TEXT, supply_warehouse_id INTEGER)');
        $db->statement('CREATE TABLE warehouse_stock_transfer_delivery_courses (from_warehouse_id INTEGER, to_warehouse_id INTEGER, delivery_course_id INTEGER)');
        $db->table('warehouses')->insert(['id' => 10, 'client_id' => 6, 'is_active' => 1, 'is_virtual' => 0]);
        $db->table('contractors')->insert([['id' => 1021, 'client_id' => 6, 'is_active' => 1], ['id' => 9012, 'client_id' => 6, 'is_active' => 1]]);
        $db->table('wms_contractor_settings')->insert([['contractor_id' => 1021, 'transmission_type' => 'JX_FINET', 'supply_warehouse_id' => null], ['contractor_id' => 9012, 'transmission_type' => 'INTERNAL', 'supply_warehouse_id' => 91]]);
        $db->table('item_contractors')->insert([['client_id' => 6, 'warehouse_id' => 10, 'item_id' => 100, 'contractor_id' => 1021, 'supplier_id' => 1021], ['client_id' => 6, 'warehouse_id' => 10, 'item_id' => 200, 'contractor_id' => 9012, 'supplier_id' => 8007]]);
        $db->table('warehouse_stock_transfer_delivery_courses')->insert(['from_warehouse_id' => 91, 'to_warehouse_id' => 10, 'delivery_course_id' => 919210]);
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['id' => 1, 'client_id' => 6]);
        $user->shouldReceive('fresh')->andReturnSelf();
        $user->shouldReceive('getSelectedWarehouseId')->andReturn(10);
        $this->be($user);
        $guard = Mockery::mock(ReplenishmentGuard::class);
        $guard->shouldReceive('clientId')->andReturn(6);
        $this->app->instance(ReplenishmentGuard::class, $guard);
        $permissions = Mockery::mock(PermissionService::class);
        $permissions->shouldReceive('check')->andReturnTrue();
        $this->app->instance(PermissionService::class, $permissions);
        $eligibility = Mockery::mock(OrderRouteEligibility::class);
        $eligibility->shouldReceive('contractorIds')->with('EOS')->andReturn([1021]);
        $eligibility->shouldReceive('contractorIds')->with('LOGISTICS')->andReturn([9012]);
        $this->app->instance(OrderRouteEligibility::class, $eligibility);
        $recommendations = Mockery::mock(SettingsRecommendationService::class);
        $recommendations->shouldReceive('recommend')->andReturn(['available' => false, 'basis' => ['reason' => '実績がありません。']]);
        $this->app->instance(SettingsRecommendationService::class, $recommendations);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function newItem(int $id = 200): ReadonlyOrderRoutePageFixture
    {
        $page = new ReadonlyOrderRoutePageFixture;
        $page->warehouseId = 10;
        $page->editPolicy();
        $page->form['item_id'] = $id;
        $page->updatedForm($id, 'item_id');

        return $page;
    }

    public function test_product_selection_keeps_eos_and_logistics_defaults_and_clears_old_route(): void
    {
        $page = $this->newItem();
        $this->assertSame('LOGISTICS', $page->form['channel']);
        $this->assertSame(9012, $page->form['contractor_id']);
        $this->assertSame(8007, $page->form['supplier_id']);
        $this->assertSame(91, $page->form['source_warehouse_id']);
        $this->assertSame(919210, $page->form['delivery_course_id']);
        $page->form['item_id'] = 100;
        $page->updatedForm(100, 'item_id');
        $this->assertSame('EOS', $page->form['channel']);
        $this->assertSame(1021, $page->form['contractor_id']);
        $this->assertSame(1021, $page->form['supplier_id']);
        $this->assertArrayNotHasKey('source_warehouse_id', $page->form);
        $this->assertArrayNotHasKey('delivery_course_id', $page->form);
        $page->calculateSettings();
    }

    public function test_direct_route_updates_restore_defaults_and_cannot_change_quantities(): void
    {
        $page = $this->newItem();
        $page->form['max_order_pieces'] = 1728;
        $before = $page->form;
        foreach (['channel' => 'EOS', 'contractor_id' => 1021, 'supplier_id' => 9012, 'source_warehouse_id' => 92, 'delivery_course_id' => 123] as $key => $value) {
            $page->form[$key] = $value;
            try {
                $page->updatedForm($value, $key);
                $this->fail('A readonly route update must be rejected.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('この画面では変更できません', $e->errors()['operation'][0]);
            }
            $this->assertEquals($before, $page->form);
        }
    }

    public function test_tampered_payload_cannot_save_recommend_or_save_a_shared_pallet(): void
    {
        $page = $this->newItem();
        $db = DB::connection('sakemaru');
        $db->enableQueryLog();
        foreach (['savePolicy', 'calculateSettings', 'savePalletUnit'] as $method) {
            $page->form['source_warehouse_id'] = 92;
            try {
                $page->$method();
                $this->fail('A tampered route must be rejected before any write.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('商品設定を開き直してください', $e->errors()['operation'][0]);
            }
        }
        foreach ($db->getQueryLog() as $query) {
            $this->assertStringStartsWith('select', strtolower($query['query']));
        }
    }

    public function test_existing_policy_route_is_kept_and_quantity_edits_remain_allowed(): void
    {
        DB::connection('sakemaru')->table('wms_item_reorder_policies')->insert(['id' => 7, 'client_id' => 6, 'warehouse_id' => 10, 'item_id' => 200, 'channel' => 'LOGISTICS', 'contractor_id' => 9012, 'supplier_id' => 8007, 'source_warehouse_id' => 91, 'delivery_course_id' => 919210, 'strategy_key' => 'threshold_capped_v1', 'is_enabled' => 0]);
        $page = new ReadonlyOrderRoutePageFixture;
        $page->warehouseId = 10;
        $page->editPolicy(7);
        $before = $page->editorRoute;
        $page->form['max_order_pieces'] = 1728;
        $page->calculateSettings();
        $this->assertSame($before, $page->editorRoute);
        $this->assertSame(1728, $page->form['max_order_pieces']);
        $this->assertSame(8007, $page->form['supplier_id']);
    }

    public function test_missing_editor_snapshot_cannot_be_used_to_save_a_policy(): void
    {
        $page = $this->newItem();
        $page->editorRoute = [];
        $this->expectException(ValidationException::class);
        $page->savePolicy();
    }
}

class ReadonlyOrderRoutePageFixture extends AutoReplenishmentPage {}
