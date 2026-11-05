<?php

namespace App\Services\AutoReplenishment;

/** One order within the item maximum; outstanding orders still count on the next evaluation. */
final class CappedThresholdStrategy
{
    public function calculate(array $policy, int $stock, int $incoming, int $claims, int $packingUnit): array
    {
        return app(ThresholdStrategy::class)->calculate($policy, $stock, $incoming, $claims, $packingUnit, true);
    }
}
