<?php

namespace Tests\Unit;

use App\Services\Distribution\DistributionOrderCandidateService;
use App\Services\Distribution\DistributionStockTransferSlipService;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class DistributionLegacyDedupeCompatibilityTest extends TestCase
{
    public function test_order_candidate_dedupe_uses_business_key_and_legacy_row_id(): void
    {
        $businessKey = sha1('stable-business-key');
        $rowId = 'legacy-row-id';
        $method = new ReflectionMethod(DistributionOrderCandidateService::class, 'distributionDedupeRowIds');

        $this->assertSame(
            [$businessKey, $rowId],
            $method->invoke(new DistributionOrderCandidateService, [
                'distribution_business_key' => strtoupper($businessKey),
            ], $rowId)
        );
    }

    public function test_stock_transfer_dedupe_uses_business_key_and_legacy_row_id(): void
    {
        $businessKey = sha1('stable-transfer-business-key');
        $rowId = 'legacy-transfer-row-id';
        $method = new ReflectionMethod(DistributionStockTransferSlipService::class, 'distributionDedupeRowIds');

        $this->assertSame(
            [$businessKey, $rowId],
            $method->invoke(new DistributionStockTransferSlipService, [
                'distribution_business_key' => strtoupper($businessKey),
            ], $rowId)
        );
    }

    public function test_order_candidate_lookup_falls_back_when_new_key_is_missing(): void
    {
        $legacyCandidate = ['candidate_id' => 123, 'row_id' => 'legacy-row-id'];
        $method = new ReflectionMethod(DistributionOrderCandidateService::class, 'firstExistingGeneratedCandidate');

        $this->assertSame(
            $legacyCandidate,
            $method->invoke(
                new DistributionOrderCandidateService,
                new Collection(['legacy-key' => $legacyCandidate]),
                ['missing-new-key', 'legacy-key']
            )
        );
    }

    public function test_transfer_candidate_lookup_falls_back_when_business_key_is_blank(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/AutoOrder/OrderCandidateToTransferCandidateService.php'
        );

        $this->assertStringContainsString("trim((string) (\$details['distribution_business_key'] ?? ''))", $source);
        $this->assertStringContainsString("\$rowId = trim((string) (\$details['row_id'] ?? ''));", $source);
    }
}
