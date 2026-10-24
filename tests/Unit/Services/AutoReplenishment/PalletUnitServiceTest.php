<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\PalletUnitService;
use App\Services\AutoReplenishment\PolicyService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use App\Services\AutoReplenishment\SnapshotReader;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/** Fresh in-memory tables only; no local/business DB reset or migrations. */
class PalletUnitServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('sakemaru');
        (new \ReflectionProperty(ReplenishmentGuard::class, 'schemaInstalled'))->setValue(null, null);
        $tables = [
            'wms_auto_order_controls' => "id INTEGER PRIMARY KEY,client_id INTEGER,reset_state TEXT DEFAULT 'COMPLETE'",
            'wms_replenishment_scopes' => 'id INTEGER PRIMARY KEY,client_id INTEGER,warehouse_id INTEGER,item_id INTEGER,created_at TEXT,updated_at TEXT,UNIQUE(client_id,warehouse_id,item_id)',
            'wms_auto_order_events' => 'id INTEGER PRIMARY KEY,client_id INTEGER,line_id INTEGER,actor_id INTEGER,event TEXT,payload TEXT,created_at TEXT',
            'warehouses' => 'id INTEGER PRIMARY KEY,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1,is_virtual INTEGER DEFAULT 0',
            'items' => 'id INTEGER PRIMARY KEY,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1,is_ended INTEGER DEFAULT 0,capacity_case INTEGER DEFAULT 24',
            'wms_item_reorder_policies' => 'id INTEGER PRIMARY KEY,client_id INTEGER,warehouse_id INTEGER,item_id INTEGER,is_configured INTEGER,is_enabled INTEGER,reorder_point_pieces INTEGER,max_order_pieces INTEGER,min_order_pieces INTEGER,order_multiple_pieces INTEGER,target_stock_pieces INTEGER,channel TEXT,contractor_id INTEGER,supplier_id INTEGER,source_warehouse_id INTEGER,delivery_course_id INTEGER,strategy_key TEXT,arrival_rule TEXT,version INTEGER,updated_by INTEGER,calculation_basis TEXT,created_at TEXT,updated_at TEXT,UNIQUE(client_id,warehouse_id,item_id)',
        ];
        foreach ($tables as $name => $columns) {
            DB::connection('sakemaru')->statement("CREATE TABLE {$name} ({$columns})");
        }
        $migration = require base_path('database/migrations/2026_10_22_000000_create_auto_order_pallet_units.php');
        $migration->up();
        $db = DB::connection('sakemaru');
        $db->table('wms_auto_order_controls')->insert(['id' => 1, 'client_id' => 6]);
        $db->table('warehouses')->insert([['id' => 1], ['id' => 2], ['id' => 91]]);
        $db->table('items')->insert(['id' => 143024]);
        $routes = Mockery::mock(\App\Services\AutoReplenishment\OrderRouteEligibility::class);
        $routes->shouldReceive('assertEligible');
        $this->app->instance(\App\Services\AutoReplenishment\OrderRouteEligibility::class, $routes);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        (new \ReflectionProperty(ReplenishmentGuard::class, 'schemaInstalled'))->setValue(null, null);
        parent::tearDown();
    }

    private function policy(array $changes = []): array
    {
        return $changes + ['item_id' => 143024, 'channel' => 'LOGISTICS', 'contractor_id' => 9012, 'supplier_id' => 8007,
            'source_warehouse_id' => 91, 'delivery_course_id' => 1, 'strategy_key' => 'threshold_capped_v1',
            'reorder_point_pieces' => 2000, 'target_stock_pieces' => 4000, 'min_order_pieces' => 1728,
            'max_order_pieces' => 3456, 'order_multiple_pieces' => 192, 'shipping_unit_mode' => 'PALLET', 'pallet_unit_version' => 1];
    }

    private function master(): object
    {
        return app(PalletUnitService::class)->save(6, 91, 143024, 1728, 0, 1);
    }

    public function test_registration_is_shared_but_only_explicitly_selected_policies_use_it(): void
    {
        $master = $this->master();
        $service = app(PolicyService::class);
        $pallet = $service->save(6, 1, $this->policy(), 1);
        $normal = $service->save(6, 2, $this->policy(['shipping_unit_mode' => 'STANDARD']), 1);
        self::assertSame(1728, $pallet->order_multiple_pieces);
        self::assertSame(192, $normal->order_multiple_pieces);
        self::assertFalse($pallet->is_enabled);
        $units = app(PalletUnitService::class);
        $bindings = $units->bindings(6, 1, [143024], false);
        self::assertSame($master->id, $bindings[$pallet->id]->id);
        self::assertSame('PALLET', $units->assertBinding($bindings[$pallet->id], $pallet->toArray(), 24)['mode']);
        self::assertNull($units->binding((int) $normal->id));
        self::assertCount(0, $units->bindings(7, 1, [143024], false));
        self::assertSame(1, DB::connection('sakemaru')->table('wms_pallet_units')->count());
    }

    public function test_master_update_holds_existing_binding_until_policy_is_explicitly_resaved(): void
    {
        $this->master();
        $policy = app(PolicyService::class)->save(6, 1, $this->policy(), 1);
        $units = app(PalletUnitService::class);
        $master = $units->save(6, 91, 143024, 864, 1, 1);
        self::assertSame(2, $master->version);
        $row = $units->bindings(6, 1, [143024], false)[$policy->id];
        try {
            $units->assertBinding($row, $policy->toArray(), 24);
            self::fail('Changed master must be held.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('再保存', $e->getMessage());
        }
        $saved = app(PolicyService::class)->save(6, 1, $this->policy(['version' => $policy->version, 'pallet_unit_version' => 2]), 1);
        self::assertSame(864, $saved->order_multiple_pieces);
        self::assertSame('PALLET', $units->assertBinding($units->bindings(6, 1, [143024], false)[$saved->id], $saved->toArray(), 24)['mode']);
    }

    public function test_stale_master_editor_cannot_overwrite_another_edit_and_same_value_does_not_invalidate_bindings(): void
    {
        $this->master();
        $units = app(PalletUnitService::class);
        self::assertSame(1, $units->save(6, 91, 143024, 1728, 1, 1)->version);
        $this->expectExceptionMessage('他の操作で変更');
        $units->save(6, 91, 143024, 864, 0, 1);
    }

    public function test_csv_without_new_fields_preserves_binding_instead_of_silently_disabling_pallets(): void
    {
        $this->master();
        $service = app(PolicyService::class);
        $p = $service->save(6, 1, $this->policy(), 1);
        $data = $this->policy(['version' => $p->version]);
        unset($data['shipping_unit_mode'], $data['pallet_unit_version']);
        $saved = $service->save(6, 1, $data, 1);
        self::assertSame(1728, $saved->order_multiple_pieces);
        self::assertNotNull(app(PalletUnitService::class)->binding((int) $saved->id));
        app(PalletUnitService::class)->save(6, 91, 143024, 864, 1, 1);
        $this->expectExceptionMessage('パレット入数が更新');
        $service->save(6, 1, array_replace($data, ['version' => $saved->version]), 1);
    }

    public function test_switching_back_to_standard_removes_only_that_policy_binding_and_keeps_master(): void
    {
        $this->master();
        $service = app(PolicyService::class);
        $p = $service->save(6, 1, $this->policy(), 1);
        $saved = $service->save(6, 1, $this->policy(['version' => $p->version, 'shipping_unit_mode' => 'STANDARD', 'order_multiple_pieces' => 24]), 1);
        self::assertSame(24, $saved->order_multiple_pieces);
        self::assertNull(app(PalletUnitService::class)->binding((int) $saved->id));
        self::assertSame(1, DB::connection('sakemaru')->table('wms_pallet_units')->count());
    }

    public function test_csv_cannot_silently_switch_to_a_different_source_pallet_with_the_same_version(): void
    {
        $this->master();
        $p = app(PolicyService::class)->save(6, 1, $this->policy(), 1);
        DB::connection('sakemaru')->table('warehouses')->insert(['id' => 92]);
        app(PalletUnitService::class)->save(6, 92, 143024, 864, 0, 1);
        $data = $this->policy(['version' => $p->version, 'source_warehouse_id' => 92]);
        unset($data['shipping_unit_mode'], $data['pallet_unit_version']);
        $this->expectExceptionMessage('移動元倉庫が変わる');
        app(PolicyService::class)->save(6, 1, $data, 1);
    }

    public function test_bad_minimum_and_eos_selection_do_not_partially_save_policy(): void
    {
        $this->master();
        foreach ([['min_order_pieces' => 192], ['channel' => 'EOS']] as $changes) {
            try {
                app(PolicyService::class)->save(6, 1, $this->policy($changes), 1);
                self::fail('Invalid pallet selection must fail.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('パレット', $e->getMessage());
            }
            self::assertSame(0, DB::connection('sakemaru')->table('wms_item_reorder_policies')->count());
            self::assertSame(0, DB::connection('sakemaru')->table('wms_policy_pallet_units')->count());
        }
    }

    public function test_failed_enable_rolls_back_policy_and_binding_together(): void
    {
        $this->master();
        $reader = Mockery::mock(SnapshotReader::class);
        $reader->shouldReceive('read')->once()->andThrow(new \RuntimeException('移動元在庫の確認が必要です。'));
        $this->app->instance(SnapshotReader::class, $reader);
        try {
            app(PolicyService::class)->saveWithState(6, 1, $this->policy(), true, 1);
            self::fail('Enable must fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('移動元在庫', $e->getMessage());
        }
        self::assertSame(0, DB::connection('sakemaru')->table('wms_item_reorder_policies')->count());
        self::assertSame(0, DB::connection('sakemaru')->table('wms_policy_pallet_units')->count());
        self::assertSame(1, DB::connection('sakemaru')->table('wms_auto_order_events')->count()); // Only the separate master save.
    }

    public function test_deleted_master_and_case_capacity_changes_fail_closed(): void
    {
        $master = $this->master();
        $p = app(PolicyService::class)->save(6, 1, $this->policy(), 1);
        $units = app(PalletUnitService::class);
        try {
            $units->assertBinding($units->bindings(6, 1, [143024], false)[$p->id], $p->toArray(), 12);
            self::fail('Changed case capacity must fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('ケース入数', $e->getMessage());
        }
        DB::connection('sakemaru')->table('wms_pallet_units')->where('id', $master->id)->delete();
        $this->expectExceptionMessage('変更・削除');
        $units->assertBinding($units->bindings(6, 1, [143024], false)[$p->id], $p->toArray(), 24);
    }

    public function test_adaptive_enable_validation_failure_rolls_back_policy_and_pallet_binding(): void
    {
        $this->master();
        $reader = Mockery::mock(SnapshotReader::class);
        $reader->shouldReceive('read')->once()->andReturn(['stock' => 0, 'incoming' => 0, 'claims' => 0, 'packing_unit' => 1]);
        $this->app->instance(SnapshotReader::class, $reader);
        try {
            app(PolicyService::class)->saveWithState(6, 1, $this->policy(['strategy_key' => 'arrival_adaptive_v1']), true, 1);
            self::fail('Missing forecast must prevent enabling the new strategy.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('出荷見込が未設定', $exception->getMessage());
        }
        self::assertSame(0, DB::connection('sakemaru')->table('wms_item_reorder_policies')->count());
        self::assertSame(0, DB::connection('sakemaru')->table('wms_policy_pallet_units')->count());
        self::assertSame(1, DB::connection('sakemaru')->table('wms_auto_order_events')->count());
    }
}
