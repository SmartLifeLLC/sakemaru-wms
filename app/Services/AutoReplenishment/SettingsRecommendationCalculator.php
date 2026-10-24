<?php

namespace App\Services\AutoReplenishment;

/** Pure calculation shared by the item editor and the controlled batch import. */
final class SettingsRecommendationCalculator
{
    public function calculate(array $history, array $options = []): array
    {
        $result = $this->calculateCandidate($history, $options);
        $basis = $result['basis'];
        // A few completed receipts are useful evidence, but do not silently replace
        // the sales default. Only the server constructs this optional reference.
        if (($basis['basis_type'] ?? null) === 'sales'
            && ($basis['own_receipt_days'] ?? 0) >= 1 && $basis['own_receipt_days'] <= 4
            && ($basis['eligible_receipt_days'] ?? 0) > 0) {
            $result['candidates'] = [
                'sales' => $result,
                'history' => $this->calculateCandidate($history, $options, true),
            ];
        }

        return $result;
    }

    private function calculateCandidate(array $history, array $options, bool $historyReference = false): array
    {
        $basis = ['method' => 'receipts-or-sales-adaptive-batch-v8', 'period' => $history['period'],
            'scope' => $history['scope'], 'unit_mode' => $options['unit_mode'] ?? 'AUTO',
            'warnings' => $history['warnings'] ?? [], 'calculated_at' => now()->toIso8601String()];
        $safetyDays = null;
        if (array_key_exists('safety_stock_days', $options)) {
            $safetyDays = filter_var($options['safety_stock_days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 28]]);
            if ($safetyDays === false) {
                return $this->unavailable($basis, '安全在庫日数は1〜28日の整数で入力してください。');
            }
        }
        $registered = isset($options['shipping_quantities']) ? app(ShippingQuantitySet::class)->normalize($options['shipping_quantities']) : null;
        if ($registered !== null && ($history['scope']['channel'] ?? '') !== 'LOGISTICS') {
            return $this->unavailable($basis, '出荷数量候補は物流発注（店間）で利用してください。');
        }
        $basis['shipping_quantities'] = $registered;
        if ($registered !== null) {
            $basis['method'] = 'registered-shipping-quantities-adaptive-v1';
        }
        $pool = $registered === null ? $history['receipts'] : array_values(array_filter($history['receipts'],
            fn ($r) => empty($r['has_shortage']) && in_array($r['pieces'], $registered, true)));
        $own = array_values(array_filter($pool, fn ($r) => $r['warehouse_id'] === $history['scope']['warehouse_id']));
        $demand = array_values(array_filter($history['daily'], fn ($quantity) => $quantity !== null));
        $basis += ['own_receipt_count' => count($own), 'shared_receipt_count' => count($pool),
            'own_receipt_days' => count(array_unique(array_column($own, 'date'))),
            'demand_positive_days' => count(array_filter($demand, fn ($quantity) => $quantity > 0)),
            'demand_total_pieces' => array_sum($demand)];
        if (($history['scope']['channel'] ?? '') === 'LOGISTICS') {
            // Reuse the bounded, unit-validated shipment rows. No additional warehouse scan.
            $basis['shipping_lots'] = (new ShippingLotProfile)->summarize($pool, $history['scope']['warehouse_id'], (int) $history['scope']['capacity_case']);
        }
        $packing = (int) $history['scope']['packing_unit'];
        $orderUnit = $history['scope']['order_unit'] ?? null;
        if ($orderUnit && $registered === null && ($basis['unit_mode'] ?? '') !== 'PALLET') {
            // Browser preferences and receipt multiples cannot override master eligibility.
            $basis['unit_mode'] = $orderUnit['quantity_type'];
        }
        $mode = $basis['unit_mode'];
        if (! in_array($mode, ['AUTO', 'PIECE', 'CASE', 'PALLET'], true)) {
            return $this->unavailable($basis, '発注単位の扱いを選び直してください。');
        }
        $unit = $mode === 'PIECE' ? 1 : array_reduce($pool, fn ($u, $r) => $this->gcd($u, $r['pieces']), 0);
        if ($registered !== null) {
            $unit = app(ShippingQuantitySet::class)->gcd($registered);
        }
        if ($mode === 'PALLET') {
            $unit = $options['pallet_pieces'] ?? 0;
            if (($history['scope']['channel'] ?? '') !== 'LOGISTICS' || ! is_int($unit) || $unit < 1 || $unit > 2147483647) {
                return $this->unavailable($basis, '物流発注のパレット入数を登録してから再計算してください。');
            }
        }
        if ($mode === 'CASE') {
            $unit = $this->lcm(max(1, (int) $history['scope']['capacity_case']), max(1, $unit));
        }
        $unit = $this->lcm(max(1, $unit), $packing);
        if ($orderUnit && $registered === null && $mode !== 'PALLET') {
            $unit = $packing;
        }
        $eligible = array_values(array_filter($own, fn ($r) => $r['pieces'] % $unit === 0));
        $salesBased = count(array_unique(array_column($eligible, 'date'))) < 5;
        $reviewDays = filter_var($options['review_days'] ?? 7, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 28]]);
        $unitBasis = $mode === 'PALLET' ? "登録済みパレット入数 {$unit}バラ" : '発注連携実績の共通単位（パレット数を意味しません）';
        if ($salesBased) {
            if ($reviewDays === false) {
                return $this->unavailable($basis, '実績不足時の発注日数は1〜28日の整数で入力してください。');
            }
            // A different store's batch does not establish this store's order size or a physical pallet.
            $case = (int) $history['scope']['capacity_case'];
            if ($mode === 'CASE' && $case < 1) {
                return $this->unavailable($basis, 'ケース入数が未設定です。商品情報を確認するか「バラ主体」を選択してください。');
            }
            if ($mode !== 'PALLET' && $registered === null) {
                $hasLooseQuantity = $case > 0 && count(array_filter($own, fn ($r) => $r['pieces'] % $case !== 0)) > 0;
                // A PIECE label alone can be a converted full case. Only a non-case-multiple
                // actual quantity establishes loose ordering; sparse history never establishes a pallet.
                $useCase = $mode === 'CASE' || ($mode === 'AUTO' && $own && $case > 0 && ! $hasLooseQuantity);
                $unit = $this->lcm($useCase ? $case : 1, $packing);
                $unitBasis = match (true) {
                    $mode === 'PIECE' => 'バラ主体（出力単位を優先）',
                    $mode === 'CASE' => 'ケース主体（ケース入数と出力単位を優先）',
                    $own === [] => '発注単位は実績で未確認。出力単位を暫定の最小単位にしています。',
                    $hasLooseQuantity => 'この倉庫にケース未満・ケース非倍数の実績があります。少数実績のため出力単位を暫定の最小単位にしています。',
                    $useCase => 'この倉庫の少数実績はケース入数の倍数です。ケース単位を維持し、パレットとは推定していません。',
                    default => 'ケース入数と発注単位は未確認。出力単位を暫定の最小単位にしています。',
                };
                $eligible = array_values(array_filter($own, fn ($r) => $r['pieces'] % $unit === 0));
            }
        }
        if ($registered !== null) {
            $registered = array_values(array_filter($registered, fn ($q) => $q % $unit === 0));
            if (! $registered) {
                return $this->unavailable($basis, '出力単位に合う出荷数量候補がありません。数量候補を確認してください。');
            }
            $unitBasis = '登録済みの出荷数量候補から選択（倍数や合算は自動生成しません）';
        }
        if ($orderUnit) {
            $unitBasis = $orderUnit['reason'].($registered !== null ? ' 登録済みの出荷数量候補から選択します。' : ($mode === 'PALLET' ? ' 登録済みパレット入数に揃えます。' : ''));
        }
        $useSales = $salesBased && ! $historyReference;
        $counts = array_count_values(array_column($eligible, 'pieces'));
        ksort($counts, SORT_NUMERIC);
        $basis += ['basis_type' => $useSales ? 'sales' : 'linked_receipts',
            'candidate_key' => $useSales ? 'sales' : 'history', 'reference_only' => $historyReference,
            'eligible_receipt_days' => count(array_unique(array_column($eligible, 'date'))), 'unit_basis' => $unitBasis,
            'observed_quantity_counts' => $this->quantityCounts($counts),
            'observed_minimum_quantity_pieces' => $counts ? (int) array_key_first($counts) : null,
            'observed_maximum_quantity_pieces' => $counts ? (int) array_key_last($counts) : null,
            'observed_maximum_frequency' => $counts ? $counts[array_key_last($counts)] : null,
            'own_quantity_types' => array_values(array_unique(array_merge(...array_map(fn ($r) => $r['quantity_types'] ?? [], $own))))];
        if ($useSales) {
            $basis['review_days'] = $reviewDays;
            $basis['fallback_reason'] = $basis['own_receipt_days'] < 5
                ? 'この倉庫の発注から出庫・入荷まで確認できる実績が5日分未満のため、売上・出荷から算定します。'
                : '選択した発注単位に合うこの倉庫の実績が5日分未満のため、売上・出荷から算定します。';
        }
        if ($historyReference) {
            $basis['warnings'][] = 'この倉庫の発注連携実績は1〜4日分の参考値です。通常の発注条件や配送可能最小数を確定するものではありません。';
        }
        $leads = array_values(array_filter(array_column($own, 'lead_days'), fn ($v) => $v !== null && $v >= 0 && $v <= 14));
        $observedLead = count($leads) >= 5 ? $this->percentile($leads, .95) : null;
        $lead = max(1, $history['nominal_lead_days'], $observedLead ?? 1);
        if ($lead > 14) {
            return $this->unavailable($basis, '入荷までの日数が14日を超えています。配送設定を確認してください。');
        }
        $reviewInterval = filter_var($history['review_interval_days'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
        if ($reviewInterval === false) {
            return $this->unavailable($basis, '倉庫の実行間隔を確認できません。倉庫の自動発注設定を保存し直してください。');
        }
        $arrivalGap = filter_var(array_key_exists('maximum_arrival_gap_days', $history) ? $history['maximum_arrival_gap_days'] : $reviewInterval,
            FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 56]]);
        if ($arrivalGap === false) {
            return $this->unavailable($basis, '入荷日の間隔を確認できません。発注先・倉庫・配送コースの配送設定を確認して再計算してください。');
        }
        $weekdays = (new GenerationSchedule)->weekdays(['weekdays' => $history['review_weekdays'] ?? null]);
        $basis += ['review_interval_days' => $reviewInterval, 'review_weekdays' => $weekdays,
            'maximum_arrival_gap_days' => $arrivalGap];
        if (isset($history['arrival_schedule']) && is_array($history['arrival_schedule'])) {
            $basis['arrival_schedule'] = array_intersect_key($history['arrival_schedule'], array_flip([
                'start', 'end', 'generation_count', 'distinct_arrival_count', 'maximum_gap_days', 'schedule_fingerprint',
            ]));
        }
        if (isset($history['review_schedule_fingerprint'])) {
            $basis['review_schedule_fingerprint'] = $history['review_schedule_fingerprint'];
        }
        $protection = $lead + $reviewInterval;
        $demand = app(DemandProfile::class);
        $selection = $demand->select($history['daily'], $history['period']);
        $daily = $selection['daily'];
        if ($selection['fallback']) {
            $basis['warnings'][] = '直近28日の観測日が14日未満、または出荷のある日が3日未満のため、対象期間全体の観測値で算定しました。欠損日は0で補完していません。';
        }
        $values = array_values(array_filter($daily, fn ($v) => $v !== null));
        $basis['history_demand_total_pieces'] = $basis['demand_total_pieces'];
        $basis['history_demand_positive_days'] = $basis['demand_positive_days'];
        $basis['demand_total_pieces'] = array_sum($values);
        $basis['demand_positive_days'] = count(array_filter($values, fn ($v) => $v > 0));
        $basis['demand_period'] = ['start' => array_key_first($daily), 'end' => $history['period']['end']];
        $windows = [];
        $days = array_values($daily);
        for ($i = 0; $i <= count($days) - $protection; $i++) {
            $window = array_slice($days, $i, $protection);
            if (! in_array(null, $window, true)) {
                $windows[] = array_sum($window);
            }
        }
        $basis += ['demand_start' => array_key_first($daily), 'observed_days' => count($values), 'positive_demand_days' => count(array_filter($values, fn ($v) => $v > 0)),
            'unknown_days' => count($daily) - count($values), 'window_count' => count($windows)];
        if (count($values) < 14 || count($windows) < 3 || count(array_filter($values, fn ($v) => $v > 0)) < 3) {
            return $this->unavailable($basis, '需要の観測日数または連続実績が不足しています。欠損日を0として算定せず、数量条件は手入力してください。');
        }
        $profile = $demand->create($daily, $selection['fallback']);
        $profile['warnings'] = array_values(array_unique(array_merge($profile['warnings'], $history['warnings'] ?? [])));
        $basis['demand_profile'] = $profile;
        $basis['demand_period'] = $profile['period'];
        $mean = $profile['mean'];
        // Pick the most frequent observed quantity; tied modes use the smaller quantity.
        // Minimum and maximum are separate observed values, never both the typical batch.
        $normal = $useSales ? $this->roundUp((int) ceil($mean * $reviewDays), $unit)
            : (int) array_search(max($counts), $counts, true);
        $min = $useSales ? $normal : (int) array_key_first($counts);
        $cap = $useSales ? $normal : (int) array_key_last($counts);
        if ($registered !== null) {
            $set = app(ShippingQuantitySet::class);
            if ($useSales) {
                $normal = $set->floor($registered, (int) ceil($mean * $reviewDays));
                if ($normal === 0) {
                    return $this->unavailable($basis, '売上から求めた通常量に収まる出荷数量候補がありません。補充目標を増やさず、数量候補を見直してください。');
                }
                $cap = $normal;
            }
            $min = $registered[0];
        }
        $p95 = $this->percentile($windows, .95);
        // All lot sizes constrain order quantities, not inventory thresholds.
        $point = (int) ceil(max($p95, $mean * $protection));
        $receiptP95 = $eligible ? $this->percentile(array_column($eligible, 'pieces'), .95) : null;
        $dailyP95 = $this->percentile($values, .95);
        $buffer = (int) ceil(max($mean, $dailyP95));
        $intervalDemand = (int) ceil($mean * $reviewInterval);
        // Leave room for one minimum lot before the next generation date; otherwise a
        // valid case/pallet can be blocked by the target until stock is already exhausted.
        $originalTarget = max($normal + $buffer, $intervalDemand + $min);
        try {
            $arrivalGapDemand = $demand->maximum($profile, $arrivalGap);
        } catch (\RuntimeException) {
            return $this->unavailable($basis, '算定数量が保存できる上限を超えています。管理者に確認してください。');
        }
        $arrivalGapFloor = $arrivalGapDemand + $this->roundUp($min, $unit);
        $target = max($originalTarget, $arrivalGapFloor);
        if ($safetyDays !== null) {
            $existingPoint = filter_var($options['existing_reorder_point'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) ?: null;
            try {
                $settings = (new SafetyStockSettings)->calculate($mean, $safetyDays, $normal, $this->roundUp($min, $unit),
                    $packing, $lead, $arrivalGap, $reviewInterval, $existingPoint,
                    $demand->maximum($profile, $lead), $arrivalGapDemand);
            } catch (\RuntimeException $e) {
                return $this->unavailable($basis, $e->getMessage());
            }
            $point = $settings['point'];
            $target = $settings['target'];
            $buffer = $settings['basis']['safety_stock_pieces'];
            $originalTarget = $settings['basis']['safety_requested_target_pieces'];
            $basis += $settings['basis'];
            if ($target > $originalTarget) {
                $basis['warnings'][] = '入荷間隔を考慮して補充目標を'.($target - $originalTarget).'バラ増やしました。';
            }
            if ($point > $target) {
                $basis['warnings'][] = '入荷までの出荷見込が通常量より多いため、現在庫で判定する発注点は入荷時の補充目標より大きくなります。';
            }
        }
        if (max($point, $target, $cap, $min, $unit) > 2147483647) {
            return $this->unavailable($basis, '算定数量が保存できる上限を超えています。管理者に確認してください。');
        }
        $basis += ['nominal_lead_days' => $history['nominal_lead_days'], 'observed_lead_p95' => $observedLead,
            'protection_days' => $protection, 'demand_start' => array_key_first($daily), 'observed_days' => count($values),
            'unknown_days' => count($daily) - count($values), 'mean_daily' => round($mean, 4),
            'window_count' => count($windows), 'demand_p95' => $p95, 'receipt_p95' => $receiptP95,
            'batch_quantity_pieces' => $normal, 'batch_percentile' => null,
            'batch_coverage_days' => round($normal / $mean, 1),
            'batch_frequency' => $useSales ? null : $counts[$normal],
            'minimum_quantity_pieces' => $min, 'maximum_quantity_pieces' => $cap,
            'maximum_frequency' => $useSales ? null : $counts[$cap],
            'batch_quantity_counts' => $this->quantityCounts($counts),
            'daily_demand_p95' => $dailyP95, 'arrival_buffer_pieces' => $buffer,
            'review_interval_demand_pieces' => $intervalDemand,
            'original_target_stock_pieces' => $originalTarget,
            'arrival_gap_demand_pieces' => $arrivalGapDemand,
            'arrival_gap_target_floor_pieces' => $arrivalGapFloor,
            'weekly_order_capacity_pieces' => $cap * count($weekdays), 'weekly_demand_pieces' => round($mean * 7, 4),
            'batch_rule' => $useSales ? 'mean-demand-times-review-days' : 'observed-mode-with-historical-maximum',
            'receipt_basis' => $useSales ? 'この倉庫の売上・出荷実績' : ($historyReference ? 'この倉庫の少数発注連携実績（参考）' : 'この倉庫の発注連携実績'),
            'samples' => $useSales ? [] : array_slice($eligible, 0, 3)];
        if ($cap * count($weekdays) * count($values) < array_sum($values) * 7) {
            return $this->unavailable($basis, "最大発注数 {$cap}バラ × 週".count($weekdays).'回では、週平均 '.round($mean * 7, 1).'バラの需要を補えません。倉庫の実行曜日を増やして再計算するか、商品の数量条件を手入力で見直してください。');
        }
        $basis['steps'] = [
            '発注連携実績：この倉庫 '.count($own).'回 / 全倉庫 '.count($pool).'回',
            $useSales && $registered !== null ? '売上からの通常量：1日平均 '.round($mean, 2)." × {$reviewDays}日分の範囲で登録候補 {$normal}バラを選択"
                : ($useSales ? '売上ベースの通常量：1日平均 '.round($mean, 2)." × {$reviewDays}日分を{$unit}単位に切上げ ＝ {$normal}バラ"
                : "通常の1回量：この倉庫で最も多い発注数量 {$normal}バラ（{$counts[$normal]}回。同率の場合は小さい数量）"),
            $registered !== null ? "最小発注数：登録候補の最小 {$min}バラ / 最大発注数：この倉庫の通常量・実績から {$cap}バラ"
                : ($useSales ? "最小発注数・最大発注数：実績不足のため算定した通常量 {$normal}バラを初期値にします。"
                    : "最小発注数：実績の最小 {$min}バラ / 最大発注数：実績の最大 {$cap}バラ（{$counts[$cap]}回）"),
            "発注単位：{$unit}バラ（出力単位 {$packing} を含む）",
            "需要保護期間：入荷まで {$lead}日 ＋ 次の実行まで最大 {$reviewInterval}日 ＝ {$protection}日",
            "発注点：大きい方（連続{$protection}日需要P95 {$p95}、日平均 ".round($mean, 2)." × {$protection}）を総バラで切上げ ＝ {$point}",
            "補充目標：大きい方（通常量・実行間隔からの目標 {$originalTarget}、入荷間隔 {$arrivalGap}日分の曜日別需要の最大 {$arrivalGapDemand} ＋ 最小発注数 {$min}） ＝ {$target}バラ",
            '入荷までの出荷見込・既発注を考慮し、補充目標までの不足を最大発注数内で補います。目標内で最小数・単位を満たせない場合は見送ります。',
        ];
        if ($safetyDays !== null) {
            array_splice($basis['steps'], 4, 3, [
                '安全在庫：1日平均 '.round($mean, 2)." × {$safetyDays}日を出力単位 {$packing} で切上げ ＝ {$buffer}バラ",
                "補充目標：安全在庫 {$buffer} ＋ 通常量 {$normal}（入荷間隔の補正を含む）＝ {$target}バラ",
                "発注点：入荷まで{$lead}日分の曜日別消費の最大値を考慮して {$point}バラ。通常量を発注するかは実際の入荷日で再判定",
            ]);
        }
        $values = ['reorder_point_pieces' => $point, 'target_stock_pieces' => $target, 'min_order_pieces' => $min,
            'order_multiple_pieces' => $unit, 'max_order_pieces' => $cap,
            'strategy_key' => 'arrival_adaptive_v1'];
        $basis['suggested_values'] = $values;

        return ['available' => true, 'values' => $values, 'basis' => $basis];
    }

    private function unavailable(array $basis, string $reason): array
    {
        return ['available' => false, 'values' => [], 'basis' => $basis + ['reason' => $reason]];
    }

    private function percentile(array $values, float $percentile): int
    {
        sort($values, SORT_NUMERIC);

        return (int) $values[max(0, (int) ceil(count($values) * $percentile) - 1)];
    }

    private function quantityCounts(array $counts): array
    {
        $rows = [];
        foreach ($counts as $pieces => $count) {
            $rows[] = ['pieces' => $pieces, 'count' => $count];
        }
        usort($rows, fn ($a, $b) => ($b['count'] <=> $a['count']) ?: ($a['pieces'] <=> $b['pieces']));

        return array_slice($rows, 0, 5);
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }

    private function lcm(int $a, int $b): int
    {
        throw_unless($a > 0 && $b > 0 && intdiv($a, max(1, $this->gcd($a, $b))) <= intdiv(PHP_INT_MAX, $b), \RuntimeException::class, '単位換算の上限を超えています。');

        return intdiv($a, $this->gcd($a, $b)) * $b;
    }

    private function roundUp(int $value, int $unit): int
    {
        return max(1, intdiv($value, $unit) + ($value % $unit > 0 ? 1 : 0)) * $unit;
    }
}
