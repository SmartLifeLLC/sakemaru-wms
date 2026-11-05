<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\OrderRouteEligibility;
use App\Services\AutoReplenishment\PolicyService;
use App\Services\AutoReplenishment\ShippingQuantityService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ShippingQuantityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('sakemaru');
        $tables = [
            'wms_auto_order_controls' => "id INTEGER PRIMARY KEY,client_id INTEGER,reset_state TEXT DEFAULT 'COMPLETE'",
            'wms_replenishment_scopes' => 'id INTEGER PRIMARY KEY,client_id INTEGER,warehouse_id INTEGER,item_id INTEGER,created_at TEXT,updated_at TEXT,UNIQUE(client_id,warehouse_id,item_id)',
            'wms_auto_order_events' => 'id INTEGER PRIMARY KEY,client_id INTEGER,line_id INTEGER,actor_id INTEGER,event TEXT,payload TEXT,created_at TEXT',
            'warehouses' => 'id INTEGER PRIMARY KEY,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1,is_virtual INTEGER DEFAULT 0',
            'items' => 'id INTEGER PRIMARY KEY,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1,is_ended INTEGER DEFAULT 0,capacity_case INTEGER DEFAULT 24',
            'users' => 'id INTEGER PRIMARY KEY,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1',
            'wms_item_reorder_policies' => 'id INTEGER PRIMARY KEY,client_id INTEGER,warehouse_id INTEGER,item_id INTEGER,is_configured INTEGER,is_enabled INTEGER,reorder_point_pieces INTEGER,max_order_pieces INTEGER,min_order_pieces INTEGER,order_multiple_pieces INTEGER,target_stock_pieces INTEGER,channel TEXT,contractor_id INTEGER,supplier_id INTEGER,source_warehouse_id INTEGER,delivery_course_id INTEGER,strategy_key TEXT,arrival_rule TEXT,version INTEGER,updated_by INTEGER,calculation_basis TEXT,created_at TEXT,updated_at TEXT,UNIQUE(client_id,warehouse_id,item_id)',
        ];
        foreach ($tables as $name => $columns) {
            DB::connection('sakemaru')->statement("CREATE TABLE {$name} ({$columns})");
        }
        (require base_path('database/migrations/2026_10_23_150000_create_shipping_quantity_profiles.php'))->up();
        (require base_path('database/migrations/2026_10_22_000000_create_auto_order_pallet_units.php'))->up();
        $db = DB::connection('sakemaru');
        $db->table('wms_auto_order_controls')->insert(['id' => 1, 'client_id' => 6]);
        $db->table('warehouses')->insert([['id' => 1], ['id' => 2], ['id' => 91], ['id' => 92]]);
        $db->table('items')->insert(['id' => 143024]);
        $db->table('users')->insert(['id' => 1]);
        $routes = Mockery::mock(OrderRouteEligibility::class);
        $routes->shouldReceive('assertEligible');
        $this->app->instance(OrderRouteEligibility::class, $routes);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function policy(array $extra = []): array
    {
        return $extra + ['item_id' => 143024, 'channel' => 'LOGISTICS', 'contractor_id' => 9012, 'supplier_id' => 8007,
            'source_warehouse_id' => 91, 'delivery_course_id' => 1, 'strategy_key' => 'arrival_adaptive_v1',
            'reorder_point_pieces' => 2000, 'target_stock_pieces' => 2000, 'min_order_pieces' => 384,
            'max_order_pieces' => 1728, 'order_multiple_pieces' => 24, 'shipping_unit_mode' => 'SHIPMENT', 'shipping_profile_version' => 1];
    }

    private function register(): object
    {
        return app(ShippingQuantityService::class)->save(6, 91, 143024, [1728, 576, 384, 768], 0, 1);
    }

    public function test_profile_is_json_and_only_opted_in_stores_are_bound_in_one_batch_read(): void
    {
        $this->register();
        $p = app(PolicyService::class)->save(6, 1, $this->policy(), 1);
        app(PolicyService::class)->save(6, 2, $this->policy(['shipping_unit_mode' => 'STANDARD']), 1);
        self::assertFalse($p->is_enabled);
        self::assertSame(192, $p->order_multiple_pieces);
        $service = app(ShippingQuantityService::class);
        $service->installed(); // Schema availability is checked once, separately from the batch read.
        DB::connection('sakemaru')->enableQueryLog();
        $rows = $service->bindings(6, 1, [143024, 99, 100], false);
        self::assertCount(1, DB::connection('sakemaru')->getQueryLog());
        self::assertSame([384, 576, 768, 1728], $service->assertBinding($rows[$p->id], $p->toArray(), 24)['quantities']);
        self::assertCount(0, $service->bindings(7, 1, [143024], false));
        self::assertCount(0, $service->bindings(6, 2, [143024], false));
    }

    public function test_unchanged_save_does_not_invalidate_bindings_but_changed_or_deleted_profile_holds(): void
    {
        $this->register();
        $service = app(ShippingQuantityService::class);
        $p = app(PolicyService::class)->save(6, 1, $this->policy(), 1);
        self::assertSame(1, $service->save(6, 91, 143024, '[384,576,768,1728]', 1, 1)->version);
        $service->save(6, 91, 143024, [384, 768], 1, 1);
        try {
            $service->assertBinding($service->bindings(6, 1, [143024], false)[$p->id], $p->toArray(), 24);
            self::fail('Stale profile accepted.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('変更・削除', $e->getMessage());
        }
        $p = app(PolicyService::class)->save(6, 1, $this->policy(['version' => $p->version, 'shipping_profile_version' => 2]), 1);
        self::assertSame([384, 768], $service->assertBinding($service->bindings(6, 1, [143024], false)[$p->id], $p->toArray(), 24)['quantities']);
    }

    public function test_eos_bad_caps_wrong_strategy_and_stale_version_roll_back_without_partial_policy(): void
    {
        $this->register();
        foreach ([['channel' => 'EOS'], ['max_order_pieces' => 100, 'min_order_pieces' => 1], ['strategy_key' => 'threshold_v1'], ['shipping_profile_version' => 0]] as $extra) {
            try {
                app(PolicyService::class)->save(6, 1, $this->policy($extra), 1);
                self::fail('Invalid setting accepted.');
            } catch (\RuntimeException $e) {
                self::assertNotEmpty($e->getMessage());
            }
            self::assertSame(0, DB::connection('sakemaru')->table('wms_item_reorder_policies')->count());
            self::assertSame(0, DB::connection('sakemaru')->table('wms_policy_shipping_quantities')->count());
        }
    }

    public function test_csv_retains_the_profile_and_never_silently_switches_warehouse(): void
    {
        $this->register();
        $p = app(PolicyService::class)->save(6, 1, $this->policy(), 1);
        $v = $this->policy(['version' => $p->version]);
        unset($v['shipping_unit_mode'], $v['shipping_profile_version']);
        $saved = app(PolicyService::class)->save(6, 1, $v, 1);
        self::assertSame(192, $saved->order_multiple_pieces);
        app(ShippingQuantityService::class)->save(6, 92, 143024, [384, 768], 0, 1);
        $this->expectExceptionMessage('移動元倉庫が変わる');
        app(PolicyService::class)->save(6, 1, array_replace($v, ['version' => $saved->version, 'source_warehouse_id' => 92]), 1);
    }

    public function test_dry_run_does_not_write_and_apply_preserves_store_caps_state_and_is_idempotent(): void
    {
        $policy = app(PolicyService::class)->save(6, 1, $this->policy(['shipping_unit_mode' => 'STANDARD', 'strategy_key' => 'arrival_batch_v1']), 1);
        $policy->update(['calculation_basis' => ['mean_daily' => 100, 'batch_quantity_pieces' => 768]]);
        $enabled = app(PolicyService::class)->save(6, 2, $this->policy(['shipping_unit_mode' => 'STANDARD']), 1);
        $enabled->update(['is_enabled' => true, 'calculation_basis' => ['mean_daily' => 100]]);
        $db = DB::connection('sakemaru');
        $db->table('warehouses')->insert(['id' => 3]);
        $withoutHistory = app(PolicyService::class)->save(6, 3, $this->policy(['shipping_unit_mode' => 'STANDARD']), 1);
        $withoutHistory->update(['calculation_basis' => ['mean_daily' => 100, 'batch_quantity_pieces' => 576]]);
        $file = tempnam(sys_get_temp_dir(), 'shipping-');
        try {
            file_put_contents($file, json_encode(['client_id' => 6, 'source_warehouse_id' => 91, 'profiles' => [
                ['item_id' => 143024, 'capacity_case' => 24, 'quantities' => [384, 576, 768, 1728],
                    'basis' => ['method' => 'wms-linked-actual-shipments-v1', 'warehouse_counts' => ['1' => [384 => 1, 768 => 6, 1728 => 5]]]],
            ]]));
            $args = ['client' => 6, 'source' => 91, 'file' => $file, '--actor' => 1, '--bind-off-policies' => true];
            $this->artisan('wms:import-shipping-quantities', $args)->assertSuccessful();
            self::assertSame(0, DB::connection('sakemaru')->table('wms_shipping_quantity_profiles')->count());
            $this->artisan('wms:import-shipping-quantities', $args + ['--apply' => true])->assertSuccessful();
            $policy->refresh();
            self::assertFalse($policy->is_enabled);
            self::assertTrue($enabled->fresh()->is_enabled);
            self::assertNull(app(ShippingQuantityService::class)->binding((int) $enabled->id));
            self::assertSame(2000, $policy->target_stock_pieces);
            self::assertSame(2000, $policy->reorder_point_pieces);
            self::assertSame(1728, $policy->max_order_pieces);
            self::assertSame(768, $policy->calculation_basis['batch_quantity_pieces']);
            self::assertSame(576, $withoutHistory->fresh()->calculation_basis['batch_quantity_pieces']);
            self::assertSame('preserved-store-normal-with-shipping-quantities', $withoutHistory->fresh()->calculation_basis['batch_rule']);
            self::assertSame('arrival_adaptive_v1', $policy->strategy_key);
            $version = $policy->version;
            $this->artisan('wms:import-shipping-quantities', $args + ['--apply' => true])->assertSuccessful();
            self::assertSame($version, $policy->fresh()->version);
        } finally {
            unlink($file);
        }
    }

    public function test_new_evidence_keeps_the_candidate_version_and_old_period_cannot_replace_it(): void
    {
        $service = app(ShippingQuantityService::class);
        $profile = $service->save(6, 91, 143024, [384, 768], 0, 1, ['period' => ['end' => '2026-10-20']]);
        $updated = $service->save(6, 91, 143024, [384, 768], 1, 1, ['period' => ['end' => '2026-10-22']]);
        self::assertSame($profile->version, $updated->version);
        self::assertSame('2026-10-22', json_decode($updated->basis, true)['period']['end']);
        $this->expectExceptionMessage('古い期間');
        $service->save(6, 91, 143024, [384], 1, 1, ['period' => ['end' => '2026-10-13']]);
    }

    public function test_invalid_stored_candidates_can_be_repaired_with_the_expected_version(): void
    {
        $profile = $this->register();
        DB::connection('sakemaru')->table('wms_shipping_quantity_profiles')->where('id', $profile->id)->update(['quantities' => '[0]']);
        $fixed = app(ShippingQuantityService::class)->save(6, 91, 143024, [384, 768], 1, 1);
        self::assertSame(2, $fixed->version);
        self::assertSame([384, 768], app(ShippingQuantityService::class)->quantities($fixed));
    }
}
