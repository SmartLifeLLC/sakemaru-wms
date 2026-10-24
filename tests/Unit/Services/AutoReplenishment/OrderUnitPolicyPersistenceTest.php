<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\OrderRouteEligibility;
use App\Services\AutoReplenishment\PalletUnitService;
use App\Services\AutoReplenishment\PolicyService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use App\Services\AutoReplenishment\ShippingQuantityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class OrderUnitPolicyPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, is_active INTEGER, is_virtual INTEGER)');
        $db->statement('CREATE TABLE items (id INTEGER, client_id INTEGER, is_active INTEGER, capacity_case INTEGER, code TEXT)');
        $db->statement('CREATE TABLE item_quantity_information (item_id INTEGER, quantity INTEGER, can_order INTEGER)');
        Schema::connection('sakemaru')->create('wms_item_reorder_policies', function (Blueprint $table) {
            $table->id();
            foreach (['client_id', 'warehouse_id', 'item_id', 'contractor_id', 'supplier_id', 'source_warehouse_id', 'delivery_course_id', 'reorder_point_pieces', 'min_order_pieces', 'max_order_pieces', 'order_multiple_pieces', 'target_stock_pieces', 'version', 'updated_by'] as $field) {
                $table->integer($field)->nullable();
            }
            $table->string('channel');
            $table->string('strategy_key');
            $table->json('calculation_basis')->nullable();
            $table->json('arrival_rule')->nullable();
            $table->boolean('is_enabled');
            $table->boolean('is_configured');
            $table->timestamps();
        });
        // Exercise the additive migration against an isolated in-memory database.
        (require database_path('migrations/2026_10_23_230000_add_order_unit_mode_to_reorder_policies.php'))->up();
        $db->table('warehouses')->insert(['id' => 2, 'client_id' => 6, 'is_active' => 1, 'is_virtual' => 0]);
        $db->table('items')->insert(['id' => 1, 'client_id' => 6, 'is_active' => 1, 'capacity_case' => 15]);
        $db->table('item_quantity_information')->insert(['item_id' => 1, 'quantity' => 1, 'can_order' => 1]);
        $guard = Mockery::mock(ReplenishmentGuard::class);
        $guard->shouldReceive('control')->andReturn((object) ['reset_state' => 'COMPLETE']);
        $guard->shouldReceive('lockScope');
        $guard->shouldReceive('event');
        $this->app->instance(ReplenishmentGuard::class, $guard);
        $eligibility = Mockery::mock(OrderRouteEligibility::class);
        $eligibility->shouldReceive('assertEligible');
        $this->app->instance(OrderRouteEligibility::class, $eligibility);
        foreach ([PalletUnitService::class, ShippingQuantityService::class] as $class) {
            $service = Mockery::mock($class);
            $service->shouldReceive('binding')->andReturnNull();
            $service->shouldReceive('prepare')->andReturnNull();
            $service->shouldReceive('persist');
            $this->app->instance($class, $service);
        }
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function data(): array
    {
        return ['item_id' => 1, 'channel' => 'LOGISTICS', 'contractor_id' => 9012,
            'strategy_key' => 'threshold_capped_v1', 'reorder_point_pieces' => 150,
            'min_order_pieces' => 30, 'max_order_pieces' => 150, 'order_multiple_pieces' => 15];
    }

    public function test_fax_cannot_be_saved_or_enabled_by_individual_or_bulk_requests(): void
    {
        $service = app(PolicyService::class);
        try {
            $service->save(6, 2, array_replace($this->data(), ['channel' => 'FAX']), 1);
            self::fail('FAX is reference-only.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            self::assertArrayHasKey('channel', $e->errors());
        }
        $policy = $service->save(6, 2, $this->data(), 1);
        // Simulate a legacy/externally inserted FAX row; enable endpoints must fail closed.
        $policy->update(['channel' => 'FAX']);
        foreach (['individual', 'bulk'] as $mode) {
            try {
                if ($mode === 'individual') {
                    $service->setEnabled(6, $policy->id, $policy->version, true, 1);
                } else {
                    $service->setSelectedEnabled(6, 2, [$policy->id => $policy->version], true, 1);
                }
                self::fail('FAX must never be enabled.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('FAX発注は参考計算のみ', $e->getMessage());
            }
            self::assertFalse($policy->refresh()->is_enabled);
            self::assertSame(1, $policy->version);
        }
    }

    public function test_case_selection_is_saved_off_and_legacy_update_preserves_it(): void
    {
        $s = app(PolicyService::class);
        $p = $s->save(6, 2, $this->data() + ['order_unit_mode' => 'CASE'], 1);
        self::assertSame('CASE', $p->refresh()->order_unit_mode);
        self::assertFalse($p->is_enabled);
        self::assertSame(1, $p->version);
        $p = $s->save(6, 2, $this->data() + ['version' => $p->version], 1);
        self::assertSame('CASE', $p->refresh()->order_unit_mode);
        self::assertFalse($p->is_enabled);
        $p = $s->save(6, 2, $this->data() + ['version' => $p->version, 'order_unit_mode' => 'AUTO'], 1);
        self::assertSame('AUTO', $p->refresh()->order_unit_mode);
    }

    public function test_legacy_new_settings_default_to_automatic(): void
    {
        $p = app(PolicyService::class)->save(6, 2, $this->data(), 1);
        self::assertSame('AUTO', $p->refresh()->order_unit_mode);
        self::assertFalse($p->is_enabled);
    }

    public function test_impossible_case_range_is_rejected_without_saving(): void
    {
        try {
            app(PolicyService::class)->save(6, 2, array_replace($this->data(), [
                'order_unit_mode' => 'CASE', 'min_order_pieces' => 1, 'max_order_pieces' => 10]), 1);
            self::fail('A case cannot fit within the maximum.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('最大発注数を満たせません', $e->getMessage());
        }
        self::assertSame(0, DB::connection('sakemaru')->table('wms_item_reorder_policies')->count());
    }

    public function test_changed_mode_cannot_save_an_old_recommendation(): void
    {
        $basis = ['scope' => ['client_id' => 6, 'warehouse_id' => 2, 'item_id' => 1, 'channel' => 'LOGISTICS', 'contractor_id' => 9012]];
        $this->expectExceptionMessage('発注単位が算定時から変わっています');
        app(PolicyService::class)->save(6, 2, $this->data() + ['order_unit_mode' => 'CASE'], 1, $basis);
    }

    public function test_history_default_is_also_validated_when_saving_automatic_mode(): void
    {
        $history = Mockery::mock(\App\Services\AutoReplenishment\LogisticsOrderUnitHistory::class);
        $history->shouldReceive('read')->with(6, 91, [1 => 15])->andReturn([1 => ['case_count' => 2, 'piece_count' => 1]]);
        $this->app->instance(\App\Services\AutoReplenishment\LogisticsOrderUnitHistory::class, $history);
        $values = $this->data() + ['source_warehouse_id' => 91, 'order_unit_mode' => 'AUTO'];
        try {
            app(PolicyService::class)->save(6, 2, array_replace($values, ['min_order_pieces' => 1, 'max_order_pieces' => 10, 'order_multiple_pieces' => 1]), 1);
            self::fail('Automatic case order cannot fit below one case.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('最大発注数を満たせません', $e->getMessage());
        }
        self::assertSame(0, DB::connection('sakemaru')->table('wms_item_reorder_policies')->count());
        $saved = app(PolicyService::class)->save(6, 2, $values, 1);
        self::assertSame('AUTO', $saved->refresh()->order_unit_mode);
        self::assertFalse($saved->is_enabled);
    }
}
