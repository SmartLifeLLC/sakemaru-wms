<?php

namespace App\Services\AutoReplenishment;

class SettingsRecommendationService
{
    public function __construct(private SettingsRecommendationHistory $history, private SettingsRecommendationCalculator $calculator) {}

    public function recommend(int $clientId, int $warehouseId, array $route, array $options = []): array
    {
        $result = $this->calculate($clientId, $warehouseId, $route, $options);
        $warnings = app(MasterRouteWarnings::class)->forRoute($clientId, $warehouseId, $route);
        $result['basis']['master_warnings'] = $warnings;
        foreach ($result['candidates'] ?? [] as $key => $candidate) {
            $result['candidates'][$key]['basis']['master_warnings'] = $warnings;
        }

        return $result;
    }

    private function calculate(int $clientId, int $warehouseId, array $route, array $options): array
    {
        // Approved demand events and learned factors (two-tier setting); absent → weekday-only as before.
        if (! array_key_exists('demand_events', $options)) {
            $options['demand_events'] = app(DemandEventRepository::class)->recommendationOptions($clientId, (int) ($route['item_id'] ?? 0));
        }
        $options += ['variability_buffer' => (bool) config('replenishment.variability_buffer', false)];
        $master = null;
        if (($route['shipping_unit_mode'] ?? 'STANDARD') === 'PALLET') {
            $units = app(PalletUnitService::class);
            $master = $units->forRoute($clientId, $route);
        }
        $history = $this->history->read($clientId, $warehouseId, $route);
        if ($master) {
            $units->assertCapacity($master, $history['scope']['capacity_case']);
            $options['unit_mode'] = 'PALLET';
            $options['pallet_pieces'] = (int) $master->pieces_per_pallet;
            $result = $this->calculator->calculate($history, $options);
            $result['basis']['pallet_unit'] = (array) $master;
            foreach ($result['candidates'] ?? [] as $key => $candidate) {
                $result['candidates'][$key]['basis']['pallet_unit'] = (array) $master;
            }

            return $result;
        }

        if (($route['shipping_unit_mode'] ?? '') === 'SHIPMENT') {
            $shipping = app(ShippingQuantityService::class);
            $profile = $shipping->find($clientId, (int) ($route['source_warehouse_id'] ?? 0), (int) $route['item_id']);
            throw_unless($route['channel'] === 'LOGISTICS' && $profile, \RuntimeException::class, '物流の出荷数量候補を先に登録してください。');
            $shipping->assertCapacity($profile, $history['scope']['capacity_case']);
            $options['unit_mode'] = 'AUTO';
            $options['shipping_quantities'] = $shipping->quantities($profile);
            $result = $this->calculator->calculate($history, $options);
            $result['basis']['shipping_profile'] = (array) $profile;
            foreach ($result['candidates'] ?? [] as $key => $candidate) {
                $result['candidates'][$key]['basis']['shipping_profile'] = (array) $profile;
            }

            return $result;
        }

        return $this->calculator->calculate($history, $options);
    }
}
