<?php

namespace App\Services\AutoReplenishment;

class SettingsRecommendationService
{
    public function __construct(private SettingsRecommendationHistory $history, private SettingsRecommendationCalculator $calculator) {}

    public function recommend(int $clientId, int $warehouseId, array $route, array $options = []): array
    {
        return $this->calculator->calculate($this->history->read($clientId, $warehouseId, $route), $options);
    }
}
