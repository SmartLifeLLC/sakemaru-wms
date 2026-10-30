<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;

/** Pure, bounded simulation; no writer, queue, cache or database access. */
class InventoryProjection
{
    public const DEFAULT_DAYS = 14;

    public const MAX_DAYS = 98;

    /** Configured chart span (10 weeks by default), bounded to what the dated forecast and arrival calendar cover. */
    public static function horizonDays(): int
    {
        $days = filter_var(config('replenishment.projection_days', 70), FILTER_VALIDATE_INT, ['options' => ['min_range' => self::DEFAULT_DAYS, 'max_range' => self::MAX_DAYS]]);

        return $days === false ? self::DEFAULT_DAYS : $days;
    }

    public function calculate(array $policy, array $input): array
    {
        $horizon = filter_var($input['horizon_days'] ?? self::DEFAULT_DAYS, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::DEFAULT_DAYS, 'max_range' => self::MAX_DAYS]]);
        throw_if($horizon === false, \RuntimeException::class, '在庫推移の表示日数を確認してください。');
        foreach (['reorder_point_pieces', 'min_order_pieces', 'max_order_pieces', 'order_multiple_pieces', 'target_stock_pieces'] as $key) {
            $value = $policy[$key] ?? null;
            if ($key === 'target_stock_pieces' && ($value === null || $value === '')) {
                $value = $policy['reorder_point_pieces'] ?? null;
            }
            throw_unless(filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) !== false,
                \RuntimeException::class, '数量の入力を完了すると、在庫推移を再計算します。');
            $policy[$key] = (int) $value;
        }
        throw_if($policy['min_order_pieces'] > $policy['max_order_pieces'], \RuntimeException::class, '最小発注数は最大発注数以下で入力してください。');
        $forecast = app(DemandProfile::class);
        $basis = $policy['calculation_basis'] ?? [];
        $usesForecast = in_array($policy['strategy_key'] ?? '', ['arrival_batch_v1', 'arrival_adaptive_v1'], true);
        $profile = $usesForecast || array_key_exists('demand_profile', $basis) || isset($basis['mean_daily']) ? $forecast->fromBasis($basis) : $input['demand'];
        $snapshot = $input['snapshot'];
        if (($snapshot['shipping_unit']['mode'] ?? '') === 'PALLET') {
            $pallet = (int) $snapshot['shipping_unit']['pieces_per_pallet'];
            throw_unless($pallet > 0 && $policy['order_multiple_pieces'] === $pallet && $policy['min_order_pieces'] % $pallet === 0,
                \RuntimeException::class, '数量条件をパレット入数に合わせてください。');
        }
        if (($snapshot['shipping_unit']['mode'] ?? '') === 'SHIPMENT') {
            throw_unless($policy['order_multiple_pieces'] === app(ShippingQuantitySet::class)->gcd($snapshot['shipping_unit']['quantities']),
                \RuntimeException::class, '最小発注単位を登録済みの出荷数量候補に合わせてください。');
        }
        $stock = (int) $snapshot['stock'];
        $start = CarbonImmutable::parse($snapshot['business_date']);
        $events = $snapshot['supply_events'];
        throw_unless(array_sum(array_column($events, 'pieces')) === $snapshot['incoming'], \RuntimeException::class, '入荷予定の合計を照合できません。データを再取得してください。');
        $unknown = 0;
        foreach ($events as &$event) {
            $date = $event['date'];
            if (! $date || $date < $start->toDateString() || CarbonImmutable::parse($date)->isSunday()) {
                $unknown += $event['pieces'];
                $event['date'] = null;
            }
            $event['new'] = false;
        }
        unset($event);
        $rows = $orders = [];
        $shortageDays = 0;
        $minimumStock = $stock;
        $minimumDate = $start->toDateString();
        $minimumTiming = '入荷前';
        $arrivals = $input['calendar']['arrivals'];
        $dates = array_keys($arrivals);
        $eventNames = [];
        foreach ($profile['event_forecast']['events'] ?? [] as $event) {
            for ($day = CarbonImmutable::parse($event['start']); $day->toDateString() <= $event['end']; $day = $day->addDay()) {
                $eventNames[$day->toDateString()] ??= $event['name'];
            }
        }
        for ($offset = 0; $offset < $horizon; $offset++) {
            $date = $start->addDays($offset);
            $key = $date->toDateString();
            $opening = $stock;
            $received = $existing = $new = 0;
            foreach ($events as &$event) {
                if ($event['date'] === $key) {
                    $received += $event['pieces'];
                    if ($event['new']) {
                        $new += $event['pieces'];
                    } else {
                        $existing += $event['pieces'];
                    }
                    $event['pieces'] = 0;
                }
            }
            unset($event);
            $stock += $received;
            throw_if($stock > 2147483647, \RuntimeException::class, '入荷後の在庫が扱える数量を超えています。数量条件を確認してください。');
            $afterReceipt = $stock;
            $pendingAtDecision = array_sum(array_column($events, 'pieces'));
            $proposal = ['phase' => 'SKIP', 'quantity' => 0, 'reason' => '倉庫の自動発注の実行曜日ではありません。'];
            $arrival = $arrivals[$key] ?? null;
            $picking = null;
            if ($arrival) {
                $index = array_search($key, $dates, true);
                // Pass the pre-receipt snapshot through the same adapter as the test and batch.
                // The graph applies the receipt to its day balance exactly once above.
                $dailySnapshot = array_replace($snapshot, ['stock' => $opening, 'incoming' => $pendingAtDecision + $received,
                    'incoming_today' => $received,
                    'business_date' => $key, 'arrival_date' => $arrival, 'next_arrival_date' => $arrivals[$dates[$index + 1] ?? ''] ?? null]);
                $proposal = app(StrategyCalculator::class)->calculate($policy, $dailySnapshot);
                app(ProposalSafety::class)->validate($policy, $proposal, $snapshot['packing_unit'], $snapshot['shipping_unit']);
                if ($proposal['phase'] === 'READY') {
                    $picking = ($policy['channel'] ?? '') === 'LOGISTICS'
                        ? app(LogisticsPickingDate::class)->resolve($arrival, $input['picking_lead_days']) : null;
                    $orders[] = ['date' => $key, 'arrival_date' => $arrival, 'picking_date' => $picking, 'pieces' => $proposal['quantity']];
                    $events[] = ['date' => $arrival, 'pieces' => $proposal['quantity'], 'new' => true];
                }
            }
            $demand = $forecast->quantity($profile, $key, 1);
            $weekdayDemand = isset($profile['event_forecast']) ? (int) round($profile['weekdays'][$date->dayOfWeek]['mean']) : $demand;
            // Missing sales do not create fictitious inventory debt. Actual negatives remain visible.
            $unmet = max(0, $demand - max(0, $stock));
            $stock = $stock < 0 ? $stock : max(0, $stock - $demand);
            if ($stock < $minimumStock) {
                $minimumStock = $stock;
                $minimumDate = $key;
                $minimumTiming = '日末';
            }
            $shortageDays += (int) ($unmet > 0 || $stock < 0);
            $ordered = $proposal['phase'] === 'READY' ? $proposal['quantity'] : 0;
            $rows[] = ['date' => $key, 'weekday' => $date->dayOfWeek, 'label' => $date->format('m/d').'（'.['日', '月', '火', '水', '木', '金', '土'][$date->dayOfWeek].'）',
                'opening' => $opening, 'after_receipt' => $afterReceipt, 'stock' => $stock, 'demand' => $demand, 'unmet' => $unmet,
                'received' => $received, 'existing_receipt' => $existing, 'new_receipt' => $new, 'ordered' => $ordered,
                'arrival_date' => $ordered ? $arrival : null, 'picking_date' => $picking, 'phase' => $proposal['phase'], 'reason' => $proposal['reason'],
                'below_point' => $afterReceipt < $policy['reorder_point_pieces'], 'pending_at_decision' => $pendingAtDecision,
                'event' => $eventNames[$key] ?? null, 'weekday_demand' => $weekdayDemand,
                'formula' => $proposal['formula'] ?? []];
        }

        $safetyStock = max(0, (int) ($policy['calculation_basis']['safety_stock_pieces'] ?? 0));
        $minimumDayDemand = $rows[array_search($minimumDate, array_column($rows, 'date'), true)]['demand'];
        $assessment = (new SafetyStockAssessment)->assess($minimumStock, $shortageDays,
            isset($basis['safety_stock_pieces']) ? $safetyStock : null,
            isset($basis['safety_stock_days']) ? (int) $basis['safety_stock_days'] : null,
            isset($profile['mean']) ? (float) $profile['mean'] : null);

        return ['days' => $rows, 'orders' => $orders, 'start' => $start->toDateString(), 'end' => $start->addDays($horizon - 1)->toDateString(), 'horizon_days' => $horizon,
            'events' => array_values(array_filter($profile['event_forecast']['events'] ?? [], fn ($e) => $e['end'] >= $start->toDateString() && $e['start'] <= $start->addDays($horizon - 1)->toDateString())),
            'initial_stock' => $snapshot['stock'], 'end_stock' => $stock, 'order_count' => count($orders), 'shortage_days' => $shortageDays,
            'minimum_stock' => $minimumStock, 'minimum_stock_date' => $minimumDate, 'minimum_stock_timing' => $minimumTiming,
            'minimum_day_demand' => $minimumDayDemand, 'safety_stock_pieces' => $safetyStock,
            'low_stock_buffer' => $assessment['requires_review'], 'safety_stock_assessment' => $assessment,
            'incoming' => $snapshot['incoming'], 'claims' => $snapshot['claims'], 'undated_incoming' => $unknown,
            'outstanding_after_period' => array_sum(array_column($events, 'pieces')), 'demand' => $profile,
            'calendar' => $input['calendar'], 'capacity_case' => $snapshot['capacity_case'], 'quantity_type' => $snapshot['quantity_type'],
            'point' => $policy['reorder_point_pieces'], 'target' => $policy['target_stock_pieces'], 'observed_at' => $input['observed_at'],
            'supply_warnings' => $input['snapshot']['supply_warnings'] ?? [],
            'simulation_at' => $snapshot['simulation_at'] ?? null];
    }
}
