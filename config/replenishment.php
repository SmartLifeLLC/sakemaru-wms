<?php

return [
    // Deployment must also complete wms:initialize-auto-replenishment once.
    'enabled' => env('WMS_AUTO_REPLENISHMENT_ENABLED', false),
    'release_key' => 'auto-replenishment-v2-20261016',
    'system_actor_id' => (int) env('WMS_AUTO_REPLENISHMENT_ACTOR_ID', 0),
    'chunk_size' => 200,
    // Keep existing purchasing quantities for Hana's hub; automatic ordering still starts OFF.
    'initial_reset_preserve_warehouse_codes' => ['hana' => [91]],
    'recommendation_not_before' => '2026-08-01',
    'strategies' => ['threshold_v1' => App\Services\AutoReplenishment\ThresholdStrategy::class,
        'threshold_capped_v1' => App\Services\AutoReplenishment\CappedThresholdStrategy::class,
        'arrival_batch_v1' => App\Services\AutoReplenishment\ArrivalBatchStrategy::class,
        'arrival_adaptive_v1' => App\Services\AutoReplenishment\ArrivalBatchStrategy::class],
];
