<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;

/** Opt-in inventory-position gate; recover through the next run without filling to the ceiling. */
final class PositionNormalBatchStrategy
{
    public const KEY = 'position_normal_batch_v1';

    public function calculate(array $policy, array $snapshot): array
    {
        try {
            return $this->decide($policy, $snapshot);
        } catch (\RuntimeException $e) {
            return ['phase' => 'HOLD', 'quantity' => 0, 'reason' => $e->getMessage(), 'reason_code' => 'INVALID_INPUT', 'formula' => []];
        }
    }

    private function decide(array $policy, array $snapshot): array
    {
        foreach (['reorder_point_pieces', 'target_stock_pieces', 'min_order_pieces', 'max_order_pieces', 'order_multiple_pieces'] as $key) {
            throw_unless(is_int($policy[$key] ?? null) && $policy[$key] > 0 && $policy[$key] <= 2147483647,
                \RuntimeException::class, '発注点・過剰在庫線・最小数・最大数・発注単位を正の整数で設定してください。');
        }
        $r = $policy['reorder_point_pieces'];
        $target = $policy['target_stock_pieces'];
        $min = $policy['min_order_pieces'];
        $max = $policy['max_order_pieces'];
        $packing = $snapshot['packing_unit'] ?? null;
        $stock = $snapshot['stock'] ?? null;
        $incoming = $snapshot['incoming'] ?? null;
        $window = $snapshot['supply_window'] ?? null;
        throw_unless($min <= $max && is_int($packing) && $packing > 0 && $packing <= 2147483647
            && is_int($stock) && $stock >= -2147483648 && $stock <= 2147483647
            && is_int($incoming) && $incoming >= 0 && $incoming <= 2147483647 && is_array($window),
            \RuntimeException::class, '在庫・既発注・数量条件を確認してください。');
        $dateCheck = app(SupplyWindow::class);
        throw_unless($dateCheck->validDate($snapshot['business_date'] ?? null) && $dateCheck->validDate($snapshot['arrival_date'] ?? null),
            \RuntimeException::class, '入荷予定日を確認できません。配送設定を確認してください。');
        $days = (int) CarbonImmutable::parse($snapshot['business_date'])->diffInDays(CarbonImmutable::parse($snapshot['arrival_date']), false);
        throw_if($days < 0 || $days > 14, \RuntimeException::class, '入荷予定日は業務日から14日以内で設定してください。');
        $a = $policy['order_multiple_pieces'];
        $b = $packing;
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        $unit = intdiv($policy['order_multiple_pieces'], $a) * $packing;
        throw_if($unit > $max, \RuntimeException::class, '発注単位に合う数量が最大発注数の範囲にありません。');
        $minimum = (intdiv($min - 1, $unit) + 1) * $unit;
        $maximum = intdiv($max, $unit) * $unit;
        $registered = null;
        $shipping = $snapshot['shipping_unit'] ?? ['mode' => 'STANDARD'];
        throw_unless(is_array($shipping) && isset($shipping['mode']), \RuntimeException::class, '発注単位の設定を確認してください。');
        if ($shipping['mode'] === 'PALLET') {
            throw_unless(($policy['channel'] ?? '') === 'LOGISTICS', \RuntimeException::class, 'パレット単位は物流発注で選択してください。');
            app(PalletUnitService::class)->assertPolicyMatches($policy, (int) ($shipping['pieces_per_pallet'] ?? 0));
        } elseif ($shipping['mode'] === 'SHIPMENT') {
            throw_unless(($policy['channel'] ?? '') === 'LOGISTICS', \RuntimeException::class, '出荷数量候補は物流発注で選択してください。');
            $registered = array_values(array_filter(app(ShippingQuantitySet::class)->normalize($shipping['quantities'] ?? []),
                fn ($q) => $q >= $min && $q <= $max && $q % $unit === 0));
            throw_unless($registered, \RuntimeException::class, '最小数・最大数・単位に合う出荷数量候補がありません。');
            $minimum = $registered[0];
            $maximum = max($registered);
        } else {
            throw_unless($shipping['mode'] === 'STANDARD', \RuntimeException::class, '発注単位の設定を確認してください。');
        }
        throw_if($minimum > $maximum, \RuntimeException::class, '最小数・単位に合う数量が最大発注数の範囲にありません。');
        $warnings = $window['warnings'];
        throw_unless(is_array($policy['calculation_basis'] ?? []), \RuntimeException::class, '数量の算定根拠を確認してください。');
        $normal = $policy['calculation_basis']['batch_quantity_pieces'] ?? null;
        if ($normal === null) {
            $normal = $minimum;
            $warnings[] = ['code' => 'NORMAL_BATCH_MISSING', 'message' => '通常量の根拠がないため実効最小数で参考計算します。「おすすめ設定」で再計算してください。'];
        }
        throw_unless(is_int($normal) && $normal > 0 && $normal <= 2147483647, \RuntimeException::class, '通常の1回量が不正です。「おすすめ設定」で再計算してください。');
        $usual = max($minimum, min($maximum, (intdiv($normal - 1, $unit) + 1) * $unit));
        if ($registered !== null) {
            $above = array_values(array_filter($registered, fn ($q) => $q >= $normal));
            $usual = $above[0] ?? $maximum;
        }
        $forecast = app(DemandProfile::class);
        $profile = $forecast->fromBasis($policy['calculation_basis'] ?? []);
        $requiredGap = max((int) ceil(round($profile['mean'], 4) * 2), $minimum);
        $requiredGap = (intdiv($requiredGap - 1, $packing) + 1) * $packing;
        if ($usual < $normal) {
            $warnings[] = ['code' => 'NORMAL_BATCH_CAPPED', 'message' => '通常量 '.$normal.'バラを1回上限・合法数量に合わせて '.$usual.'バラに制限しています。'];
        }
        if ($target - $r < $requiredGap) {
            $warnings[] = ['code' => 'MINIMUM_GAP_SHORT', 'message' => '発注点と過剰在庫線の差が最低ギャップ '.$requiredGap.'バラを下回ります。過剰在庫線を維持して発注点を見直してください。'];
        }
        $out = $forecast->quantity($profile, $snapshot['business_date'], $days);
        $remaining = $stock < 0 ? $stock : max(0, $stock - $out);
        $capPosition = $remaining + $incoming;
        $room = max(0, $target - $capPosition);
        $position = $stock + $incoming;
        throw_if($position > 2147483647, \RuntimeException::class, '発注判定在庫が扱える数量を超えています。');
        // Use the same dated demand as the graph. Receipts move between stock and
        // outstanding supply, so do not add them again to the next inventory position.
        $nextDate = $snapshot['next_generation_date'] ?? null;
        $nextConsumption = null;
        $recovery = 0;
        if ($dateCheck->validDate($nextDate) && $nextDate > $snapshot['business_date']
            && $nextDate <= CarbonImmutable::parse($snapshot['business_date'])->addDays(7)->toDateString()) {
            $nextDays = (int) CarbonImmutable::parse($snapshot['business_date'])->diffInDays(CarbonImmutable::parse($nextDate));
            $nextConsumption = $forecast->quantity($profile, $snapshot['business_date'], $nextDays);
            $recovery = max(0, $r + $nextConsumption - $position);
        } else {
            $warnings[] = ['code' => 'NEXT_GENERATION_UNKNOWN', 'message' => '次回の実行日を確認できないため、通常量で計算します。倉庫の実行曜日・配送設定を確認してください。'];
        }
        $requested = max($normal, $recovery);
        $adjusted = max($minimum, min($maximum, (intdiv($requested - 1, $unit) + 1) * $unit));
        if ($registered !== null) {
            $above = array_values(array_filter($registered, fn ($q) => $q >= $requested));
            $adjusted = $above[0] ?? $maximum;
        }
        $inventory = ['stock' => $snapshot['raw_stock'], 'today' => $window['today'], 'available_today' => $stock,
            'future' => $window['future'], 'undated' => $window['undated'], 'excluded' => $window['excluded'],
            'position' => $position, 'point' => $r, 'cutoff_date' => $window['cutoff_date']];
        $quantityTrace = ['normal' => $normal, 'usual' => $usual, 'minimum' => $minimum, 'maximum' => $max, 'unit' => $unit, 'required_gap' => $requiredGap,
            'consumption' => $out, 'remaining' => $remaining, 'cap_position' => $capPosition, 'headroom' => $room, 'target' => $target,
            'recovery_required' => $recovery, 'requested' => $requested, 'adjusted_batch' => $adjusted];
        $formula = ["発注判定在庫：現在庫 {$inventory['stock']} ＋ 当日入荷 {$window['today']} ＋ 60日以内 {$window['future']} ＋ 日付未定・期限超過 {$window['undated']} ＝ {$position}バラ（60日外 {$window['excluded']}バラは除外）"];
        $quantity = 0;
        if ($position >= $r) {
            $phase = 'NO_ORDER';
            $code = 'POSITION_SUFFICIENT';
            $reason = '既発注込みの発注判定在庫が発注点以上です。';
            $formula[] = "発注判定在庫 {$position} ≧ 発注点 {$r} → 追加発注なし";
        } else {
            $limit = min($adjusted, $room);
            $quantity = $registered === null ? intdiv($limit, $unit) * $unit
                : max(array_filter($registered, fn ($q) => $q <= $limit) ?: [0]);
            if ($quantity < $minimum) {
                $quantity = 0;
            }
            $phase = $quantity > 0 ? 'READY' : 'NO_ORDER';
            $code = $quantity === 0 ? 'NO_LEGAL_ROOM' : ($quantity > $usual ? 'RECOVERY_BATCH'
                : ($quantity < $usual ? 'NORMAL_BATCH_REDUCED' : 'NORMAL_BATCH'));
            $reason = $quantity === 0 ? '過剰在庫線の余地に最小数・発注単位が収まらないため、今回は発注しません。'
                : ($quantity > $usual ? '次回実行までの消費を見込んで通常量を増やし、1回上限・過剰在庫線の範囲で発注します。'
                    : '通常の1回量を基本に、数量条件と過剰在庫線の範囲で発注します。');
            $formula[] = "発注判定在庫 {$position} ＜ 発注点 {$r}";
            $formula[] = $forecast->formula($profile, $snapshot['business_date'], $days);
            $formula[] = "上限照合：入荷までの在庫残 {$remaining} ＋ 既発注 {$incoming} ＝ {$capPosition}バラ、過剰在庫線 {$target} − {$capPosition} ＝ 余地 {$room}バラ";
            if ($nextConsumption !== null) {
                $formula[] = "次回 {$nextDate} までの消費 {$nextConsumption}バラ、発注点 {$r} ＋ 消費 {$nextConsumption} − 判定在庫 {$position} → 必要量 {$recovery}バラ";
            }
            $formula[] = "通常量 {$normal} と必要量 {$recovery} の大きい方 {$requested} → 単位・1回上限調整 {$adjusted}、実効最小 {$minimum}／1回最大 {$max}／単位 {$unit} → 余地内で今回 {$quantity}バラ";
            if ($quantity === 0) {
                $warnings[] = ['code' => 'NO_LEGAL_ROOM', 'message' => '最小数・単位と過剰在庫線の余地を見直してください。'];
            }
        }

        if ($nextConsumption !== null) {
            $quantityTrace += ['next_generation_date' => $nextDate, 'next_consumption' => $nextConsumption,
                'next_position_estimate' => $position + $quantity - $nextConsumption];
            if ($quantity > 0 && $quantityTrace['next_position_estimate'] < $r) {
                $warnings[] = ['code' => 'NEXT_POSITION_LOW', 'message' => '次回 '.$nextDate.' の判定在庫の簡易見込は '.$quantityTrace['next_position_estimate'].'バラです。再発注が見込まれるため、1回上限・過剰在庫線・発注間隔を確認してください（納期変更・60日窓の変動前の目安）。'];
            }
        }

        return ['phase' => $phase, 'quantity' => $quantity, 'reason' => $reason, 'reason_code' => $code,
            'formula' => $formula, 'warnings' => $warnings, 'decision_inventory' => $inventory,
            'quantity_calculation' => $quantityTrace + ['quantity' => $quantity], 'strategy_version' => self::KEY,
            'dates' => ['business_date' => $snapshot['business_date'], 'arrival_date' => $snapshot['arrival_date'],
                'cutoff_date' => $window['cutoff_date'], 'evaluated_at' => $snapshot['simulation_at'] ?? $snapshot['observed_at'] ?? null,
                'observed_at' => $snapshot['observed_at'] ?? null],
            'provenance' => ['policy_version' => $policy['version'] ?? null, 'strategy_version' => self::KEY,
                'input_digest' => hash('sha256', json_encode(DemandEventForecast::canonical([$policy, $snapshot]), JSON_THROW_ON_ERROR))]];
    }
}
