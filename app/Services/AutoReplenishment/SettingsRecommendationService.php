<?php

namespace App\Services\AutoReplenishment;

class SettingsRecommendationService
{
    public function __construct(private SettingsRecommendationHistory $history, private SettingsRecommendationCalculator $calculator) {}

    public function recommend(int $clientId, int $warehouseId, array $route, array $options = []): array
    {
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
