<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoOrder\OrderTransmissionService;
use App\Services\AutoReplenishment\OrderRouteEligibility;
use Mockery;
use Tests\TestCase;

class OrderRouteEligibilityTest extends TestCase
{
    public function test_reference_routes_exclude_eos_aggregation_logistics_and_other_clients(): void
    {
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        \Illuminate\Support\Facades\DB::purge('sakemaru');
        try {
            $db = \Illuminate\Support\Facades\DB::connection('sakemaru');
            $db->statement('CREATE TABLE contractors (id INTEGER, client_id INTEGER, is_active INTEGER)');
            $db->statement('CREATE TABLE wms_contractor_settings (contractor_id INTEGER, transmission_type TEXT)');
            foreach ([1021, 1022, 9012, 9999] as $id) {
                $db->table('contractors')->insert(['id' => $id, 'client_id' => 6, 'is_active' => 1]);
            }
            $db->table('contractors')->insert([['id' => 8888, 'client_id' => 7, 'is_active' => 1], ['id' => 7777, 'client_id' => 6, 'is_active' => 0]]);
            $db->table('wms_contractor_settings')->insert(['contractor_id' => 9012, 'transmission_type' => 'INTERNAL']);
            $transmission = Mockery::mock(OrderTransmissionService::class);
            $transmission->shouldReceive('jxOrderTargetContractorIds')->andReturn([1021, 1022]);
            app()->instance(OrderTransmissionService::class, $transmission);
            $routes = new OrderRouteEligibility;
            self::assertSame([9999], $routes->referenceContractorIds(6));
            $routes->assertReferenceEligible(6, 9999);
            self::assertSame([], $routes->contractorIds('FAX'));
            $this->expectExceptionMessage('参考計算の発注先が変更されています');
            $routes->assertReferenceEligible(6, 1022);
        } finally {
            \Illuminate\Support\Facades\DB::disconnect('sakemaru');
        }
    }

    public function test_eos_uses_the_existing_jx_generation_targets_including_aggregated_routes(): void
    {
        $transmission = Mockery::mock(OrderTransmissionService::class);
        $transmission->shouldReceive('jxOrderTargetContractorIds')->once()->andReturn([1021, 1022]);
        app()->instance(OrderTransmissionService::class, $transmission);
        $routes = new OrderRouteEligibility;

        $routes->assertEligible('EOS', 1022);
        self::assertSame([], $routes->contractorIds('FAX'));
    }

    public function test_fax_only_contractor_cannot_be_mislabeled_as_eos(): void
    {
        $this->expectExceptionMessage('FAX発注は自動発注の対象外です。');
        (new OrderRouteEligibility)->assertEligible('EOS', 3000, [1021, 1022]);
    }

    public function test_external_contractor_cannot_be_mislabeled_as_logistics(): void
    {
        $this->expectExceptionMessage('物流発注に設定された発注先を選択してください。');
        (new OrderRouteEligibility)->assertEligible('LOGISTICS', 1021, [9012]);
    }

    public function test_fax_channel_itself_is_rejected_even_with_a_valid_contractor(): void
    {
        $this->expectException(\RuntimeException::class);
        (new OrderRouteEligibility)->assertEligible('FAX', 1021, [1021]);
    }
}
