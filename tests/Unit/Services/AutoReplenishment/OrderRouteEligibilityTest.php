<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoOrder\OrderTransmissionService;
use App\Services\AutoReplenishment\OrderRouteEligibility;
use Mockery;
use Tests\TestCase;

class OrderRouteEligibilityTest extends TestCase
{
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
