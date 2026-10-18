<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Filament\Pages\WmsOrderRegistration;
use App\Filament\Pages\WmsOrderRegistrationHq;
use App\Filament\Resources\WmsAutoOrderJobControls\Pages\ListWmsAutoOrderJobControls;
use App\Filament\Resources\WmsOrderCandidates\Pages\ListWmsOrderCandidates;
use App\Filament\Resources\WmsStockTransferCandidates\Pages\ListWmsStockTransferCandidates;
use App\Models\WmsWarehouseAutoOrderSetting;
use App\Services\AutoOrder\ManualOrderWarehouseResolver;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use App\Services\AutoOrder\SalesBasedOrderCandidateService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class ManualOrderWarehouseResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.client_id' => 6, 'database.connections.sakemaru' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE warehouses (id INTEGER PRIMARY KEY, client_id INTEGER, is_active INTEGER DEFAULT 1, is_virtual INTEGER DEFAULT 0)');
        $db->statement('CREATE TABLE wms_warehouse_auto_order_settings (warehouse_id INTEGER PRIMARY KEY, is_auto_order_enabled INTEGER DEFAULT 0)');
        $db->statement('CREATE TABLE item_contractors (id INTEGER PRIMARY KEY, client_id INTEGER, warehouse_id INTEGER, contractor_id INTEGER)');
        $db->statement('CREATE TABLE wms_contractor_settings (contractor_id INTEGER, transmission_type TEXT, supply_warehouse_id INTEGER)');
        $db->statement('CREATE TABLE wms_auto_order_controls (client_id INTEGER, enabled INTEGER, reset_state TEXT, generation INTEGER)');
        $db->table('warehouses')->insert([
            ['id' => 8, 'client_id' => 6, 'is_active' => 1, 'is_virtual' => 0],
            ['id' => 9, 'client_id' => 6, 'is_active' => 1, 'is_virtual' => 1],
            ['id' => 10, 'client_id' => 6, 'is_active' => 0, 'is_virtual' => 0],
            ['id' => 11, 'client_id' => 6, 'is_active' => 1, 'is_virtual' => 0],
            ['id' => 91, 'client_id' => 6, 'is_active' => 1, 'is_virtual' => 0],
            ['id' => 99, 'client_id' => 7, 'is_active' => 1, 'is_virtual' => 0],
        ]);
        foreach ([8, 9, 10, 11, 91, 99] as $id) {
            $db->table('wms_warehouse_auto_order_settings')->insert(['warehouse_id' => $id, 'is_auto_order_enabled' => 0]);
        }
        $db->table('wms_contractor_settings')->insert([
            ['contractor_id' => 9012, 'transmission_type' => 'INTERNAL', 'supply_warehouse_id' => 91],
            ['contractor_id' => 9013, 'transmission_type' => 'INTERNAL', 'supply_warehouse_id' => 11],
        ]);
        $db->table('item_contractors')->insert([
            ['id' => 1, 'client_id' => 6, 'warehouse_id' => 8, 'contractor_id' => 9012],
            ['id' => 2, 'client_id' => 6, 'warehouse_id' => 8, 'contractor_id' => 9012],
            ['id' => 3, 'client_id' => 6, 'warehouse_id' => 10, 'contractor_id' => 9012],
            ['id' => 4, 'client_id' => 7, 'warehouse_id' => 99, 'contractor_id' => 9012],
            ['id' => 5, 'client_id' => 6, 'warehouse_id' => 11, 'contractor_id' => 9013],
        ]);
    }

    #[DataProvider('manualEntryPoints')]
    public function test_manual_entrypoints_include_selected_warehouse_while_auto_order_is_off(string $class, string $declaringClass, string $method): void
    {
        self::assertSame([], WmsWarehouseAutoOrderSetting::enabled()->pluck('warehouse_id')->all());
        $page = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertSame([8], (new ReflectionMethod($declaringClass, $method))->invoke($page, 8));
        self::assertSame([], (new ReflectionMethod($declaringClass, $method))->invoke($page, 99));
    }

    public static function manualEntryPoints(): array
    {
        return [
            'external order' => [WmsOrderRegistration::class, WmsOrderRegistration::class, 'getSalesBasedExternalOrderGenerationWarehouseIds'],
            'HQ external order' => [WmsOrderRegistrationHq::class, WmsOrderRegistration::class, 'getSalesBasedExternalOrderGenerationWarehouseIds'],
            'legacy external order' => [ListWmsOrderCandidates::class, ListWmsOrderCandidates::class, 'getSalesBasedExternalOrderGenerationWarehouseIds'],
            'sales history search' => [OrderRegistrationSearchService::class, OrderRegistrationSearchService::class, 'salesHistoryWarehouseIds'],
            'sales calculation' => [ListWmsAutoOrderJobControls::class, ListWmsAutoOrderJobControls::class, 'getSalesBasedGenerationWarehouseIds'],
        ];
    }

    public function test_manual_transfer_includes_only_the_hub_and_related_active_satellites(): void
    {
        $page = (new ReflectionClass(ListWmsStockTransferCandidates::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ListWmsStockTransferCandidates::class, 'getSalesBasedTransferGenerationWarehouseIds');
        self::assertSame([8, 91], $method->invoke($page, 91));
        self::assertSame([], $method->invoke($page, 99));
        self::assertSame([], WmsWarehouseAutoOrderSetting::enabled()->pluck('warehouse_id')->all());
    }

    public function test_manual_selection_requires_an_active_warehouse_in_the_configured_client(): void
    {
        $resolver = new ManualOrderWarehouseResolver;
        foreach ([0, -1, 10, 99, 999] as $invalid) {
            self::assertSame([], $resolver->selected($invalid));
        }
        // A missing automatic setting is not a manual-order prerequisite either.
        DB::connection('sakemaru')->table('wms_warehouse_auto_order_settings')->where('warehouse_id', 8)->delete();
        self::assertSame([8], $resolver->selected(8));
        self::assertSame([9], $resolver->selected(9));
    }

    public function test_calculation_scope_does_not_expand_into_unselected_or_foreign_warehouses(): void
    {
        $resolver = new ManualOrderWarehouseResolver;
        self::assertSame([8], $resolver->forCalculation(8, false));
        self::assertSame([8, 91], $resolver->forCalculation(91, true));
        self::assertSame([8, 11, 91], $resolver->forCalculation(null, false));
        self::assertSame([], $resolver->forCalculation(9, false));
        self::assertSame([], $resolver->forCalculation(99, true));
    }

    public function test_sales_calculation_uses_the_manual_scope_resolver(): void
    {
        $resolver = Mockery::mock(ManualOrderWarehouseResolver::class);
        $resolver->shouldReceive('forCalculation')->once()->with(8, false)->andReturn([]);
        app()->instance(ManualOrderWarehouseResolver::class, $resolver);
        $service = new SalesBasedOrderCandidateService;
        (new ReflectionProperty($service, 'targetWarehouseId'))->setValue($service, 8);
        (new ReflectionMethod($service, 'loadAllDataToMemory'))->invoke($service);
        self::assertSame([], (new ReflectionProperty($service, 'realWarehouseIds'))->getValue($service));
    }

    public function test_automatic_execution_remains_blocked_when_manual_ordering_is_available(): void
    {
        config(['replenishment.enabled' => true]);
        DB::connection('sakemaru')->table('wms_auto_order_controls')->insert([
            'client_id' => 6, 'enabled' => 1, 'reset_state' => 'COMPLETE', 'generation' => 1,
        ]);
        self::assertSame([8], (new ManualOrderWarehouseResolver)->selected(8));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('倉庫の自動発注はOFFです。');
        (new ReplenishmentGuard)->assertExecutable(6, 8);
    }

    public function test_missing_client_configuration_does_not_open_all_warehouses(): void
    {
        config(['app.client_id' => null]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('対象顧客が未設定です。');
        (new ManualOrderWarehouseResolver)->selected(8);
    }
}
