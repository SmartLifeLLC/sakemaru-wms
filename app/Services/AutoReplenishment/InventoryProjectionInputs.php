<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use Illuminate\Support\Facades\DB;

/** One consistent live read; subsequent quantity edits use this small, locked display snapshot. */
class InventoryProjectionInputs
{
    public function read(int $client, int $warehouse, array $form, ?array $basis): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($client, $warehouse, $form, $basis) {
            // No saved ID: preview uses the editor's selected shipping profile, not a stale saved binding.
            $policy = new WmsItemReorderPolicy(array_replace($form, ['id' => null, 'client_id' => $client, 'warehouse_id' => $warehouse,
                'calculation_basis' => $basis, 'is_enabled' => false]));
            $snapshot = app(SnapshotReader::class)->readForSimulation($policy, includeSupplyDates: true);
            $shipping = ['mode' => 'STANDARD'];
            if (($form['shipping_unit_mode'] ?? '') === 'PALLET') {
                $service = app(PalletUnitService::class);
                $master = $service->forRoute($client, $form);
                $service->assertCapacity($master, $snapshot['capacity_case']);
                $shipping = ['mode' => 'PALLET', 'pieces_per_pallet' => (int) $master->pieces_per_pallet];
            } elseif (($form['shipping_unit_mode'] ?? '') === 'SHIPMENT') {
                $service = app(ShippingQuantityService::class);
                $profile = $service->find($client, (int) ($form['source_warehouse_id'] ?? 0), (int) $form['item_id']);
                throw_unless($form['channel'] === 'LOGISTICS' && $profile, \RuntimeException::class, '出荷数量候補を登録してから在庫推移を確認してください。');
                $service->assertCapacity($profile, $snapshot['capacity_case']);
                $shipping = ['mode' => 'SHIPMENT', 'quantities' => $service->quantities($profile)];
            }
            $snapshot['shipping_unit'] = $shipping;
            $horizon = InventoryProjection::horizonDays();
            $calendar = app(InventoryProjectionCalendar::class)->read($warehouse, (int) $form['contractor_id'], $form['channel'], $snapshot['business_date'], $snapshot['warehouse_config'],
                max(RecommendationArrivalSchedule::LOOKAHEAD_DAYS, $horizon));
            $usesForecast = in_array($form['strategy_key'] ?? '', ['arrival_batch_v1', 'arrival_adaptive_v1'], true);
            $demand = $usesForecast || array_key_exists('demand_profile', $basis ?? []) || isset($basis['mean_daily'])
                ? app(DemandProfile::class)->fromBasis($basis ?? [])
                : app(WeekdayDemand::class)->read($client, $warehouse, (int) $form['item_id'], $snapshot['business_date']);

            return ['snapshot' => array_intersect_key($snapshot, array_flip(['stock', 'incoming', 'incoming_today', 'claims', 'supply_events', 'packing_unit',
                'quantity_type', 'capacity_case', 'business_date', 'shipping_unit', 'simulation_at'])),
                'demand' => $demand, 'calendar' => $calendar, 'horizon_days' => $horizon, 'picking_lead_days' => (int) ($snapshot['route_snapshot']['effective_picking_lead_days'] ?? 0),
                'observed_at' => now('Asia/Tokyo')->format('m/d H:i')];
        });
    }
}
