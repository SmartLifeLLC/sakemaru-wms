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
    // Inventory projection chart span on the settings page (10 weeks); event forecasts cover 91 days.
    'projection_days' => 70,
    // Add a σ√H term to the safety stock for high-variability products (CV > 1) only.
    // Validated in the 2025 purchasing simulation (採用案); off until enabled so a deploy alone changes no quantity.
    'variability_buffer' => env('WMS_AUTO_REPLENISHMENT_VARIABILITY_BUFFER', false),
    // Weekly automatic recalculation of saved recommendations (approved events only). Off until enabled.
    'weekly_refresh' => ['enabled' => env('WMS_AUTO_REPLENISHMENT_WEEKLY_REFRESH', false), 'max_change_ratio' => 2.0],
    'demand_events' => ['horizon_days' => 91, 'training_years' => 3, 'learning_from' => '2021-05-01'],
    'strategies' => ['threshold_v1' => App\Services\AutoReplenishment\ThresholdStrategy::class,
        'threshold_capped_v1' => App\Services\AutoReplenishment\CappedThresholdStrategy::class,
        'arrival_batch_v1' => App\Services\AutoReplenishment\ArrivalBatchStrategy::class,
        'arrival_adaptive_v1' => App\Services\AutoReplenishment\ArrivalBatchStrategy::class],
];
