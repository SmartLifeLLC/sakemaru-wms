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
        $asOf = $options['as_of'] ?? null; // reconciliation of a saved value: the history window that ended before this date
        unset($options['as_of']);
        $constraints = $this->unitConstraints($clientId, $route);
        $history = $this->history->read($clientId, $warehouseId, $route, $asOf);
        $options = $this->constrainedOptions($constraints, $history['scope']['capacity_case'], $options);
        $result = $this->calculator->calculate($history, $options);
        if ($constraints['basis']) {
            $result['basis'] += $constraints['basis'];
            foreach ($result['candidates'] ?? [] as $key => $candidate) {
                $result['candidates'][$key]['basis'] += $constraints['basis'];
            }
        }

        return $result;
    }

    /**
     * The quantity constraint registered for this warehouse × product route: the pallet master (PALLET) or the registered
     * shipping quantities (SHIPMENT) the editor bound to the policy, or none (STANDARD). Shared by the editor, the weekly
     * refresh and the past replay so no path reinterprets a mode on its own.
     *
     * @return array{mode: string, master: ?object, shipping_unit: array, basis: array}
     */
    public function unitConstraints(int $clientId, array $route): array
    {
        $mode = $route['shipping_unit_mode'] ?? 'STANDARD';
        if ($mode === 'PALLET') {
            $master = app(PalletUnitService::class)->forRoute($clientId, $route);

            return ['mode' => 'PALLET', 'master' => $master, 'basis' => ['pallet_unit' => (array) $master],
                'shipping_unit' => ['mode' => 'PALLET', 'id' => (int) $master->id, 'version' => (int) $master->version, 'pieces_per_pallet' => (int) $master->pieces_per_pallet]];
        }
        if ($mode === 'SHIPMENT') {
            $shipping = app(ShippingQuantityService::class);
            $profile = $shipping->find($clientId, (int) ($route['source_warehouse_id'] ?? 0), (int) ($route['item_id'] ?? 0));
            throw_unless(($route['channel'] ?? '') === 'LOGISTICS' && $profile, \RuntimeException::class, '物流の出荷数量候補を先に登録してください。');

            return ['mode' => 'SHIPMENT', 'master' => $profile, 'basis' => ['shipping_profile' => (array) $profile],
                'shipping_unit' => ['mode' => 'SHIPMENT', 'id' => (int) $profile->id, 'version' => (int) $profile->version, 'quantities' => $shipping->quantities($profile)]];
        }
        throw_unless($mode === 'STANDARD', \RuntimeException::class, '発注単位の設定を選び直してください。');

        return ['mode' => 'STANDARD', 'master' => null, 'basis' => [], 'shipping_unit' => ['mode' => 'STANDARD']];
    }

    /** The calculator options a constraint implies (PALLET → unit_mode PALLET + pallet_pieces; SHIPMENT → AUTO + shipping_quantities), after the capacity check. */
    public function constrainedOptions(array $constraints, int $capacityCase, array $options): array
    {
        if ($constraints['mode'] === 'PALLET') {
            app(PalletUnitService::class)->assertCapacity($constraints['master'], $capacityCase);
            $options['unit_mode'] = 'PALLET';
            $options['pallet_pieces'] = (int) $constraints['master']->pieces_per_pallet;
        } elseif ($constraints['mode'] === 'SHIPMENT') {
            app(ShippingQuantityService::class)->assertCapacity($constraints['master'], $capacityCase);
            $options['unit_mode'] = 'AUTO';
            $options['shipping_quantities'] = $constraints['shipping_unit']['quantities'];
        }

        return $options;
    }
}
