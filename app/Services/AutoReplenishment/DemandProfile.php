<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;

/** Immutable demand evidence, shared by recommendations, previews and execution. */
final class DemandProfile
{
    public function historyPeriod(string $businessDate): array
    {
        $end = CarbonImmutable::parse(min($businessDate, CarbonImmutable::now('Asia/Tokyo')->toDateString()))->subDay();

        return ['start' => max($end->subDays(89)->toDateString(), config('replenishment.recommendation_not_before', '2026-08-01')),
            'end' => $end->toDateString()];
    }

    public function select(array $daily, array $period): array
    {
        $start = CarbonImmutable::parse($period['end'])->subDays(27)->toDateString();
        $recent = array_filter($daily, fn ($date) => $date >= $start && $date <= $period['end'], ARRAY_FILTER_USE_KEY);
        $values = array_filter($recent, fn ($v) => $v !== null);
        $fallback = count($values) < 14 || count(array_filter($values, fn ($v) => $v > 0)) < 3;

        return ['daily' => $fallback ? $daily : $recent, 'fallback' => $fallback];
    }

    public function create(array $daily, bool $fallback = false): array
    {
        $summary = app(WeekdayDemand::class)->summarize($daily);
        $period = ['start' => array_key_first($daily), 'end' => array_key_last($daily)];

        return $summary + ['version' => 'weekday-demand-v1', 'period' => $period,
            'anchor_date' => CarbonImmutable::parse($period['end'])->addDay()->toDateString(),
            'total_pieces' => array_sum(array_column($summary['weekdays'], 'total')),
            'selection' => $fallback ? 'history_fallback' : 'recent_28_days', 'legacy' => false,
            'warnings' => $fallback ? ['直近の実績が不足するため、発注実績と同じ対象期間まで需要の算定期間を広げています。'] : []];
    }

    public function fromBasis(array $basis): array
    {
        if (array_key_exists('demand_profile', $basis)) {
            $profile = $basis['demand_profile'];
            throw_unless(is_array($profile) && ($profile['version'] ?? '') === 'weekday-demand-v1', \RuntimeException::class,
                '需要の算定根拠を読み込めません。「おすすめ設定」で再計算してください。');
            throw_unless(is_string($profile['period']['start'] ?? null) && is_string($profile['period']['end'] ?? null)
                && is_string($profile['anchor_date'] ?? null), \RuntimeException::class,
                '需要の算定期間を確認できません。「おすすめ設定」で再計算してください。');
            $this->date($profile['period']['start'] ?? '');
            $end = $this->date($profile['period']['end'] ?? '');
            throw_unless($profile['period']['start'] <= $profile['period']['end']
                && ($profile['anchor_date'] ?? '') === $end->addDay()->toDateString(), \RuntimeException::class,
                '需要の算定期間を確認できません。「おすすめ設定」で再計算してください。');
            $mean = $profile['mean'] ?? null;
            throw_unless(is_numeric($mean) && is_finite((float) $mean) && $mean >= 0 && $mean <= 2147483647
                && is_array($profile['weekdays'] ?? null) && count($profile['weekdays']) === 7
                && is_int($profile['observed_days'] ?? null) && $profile['observed_days'] > 0
                && is_int($profile['missing_days'] ?? null) && $profile['missing_days'] >= 0
                && is_array($profile['warnings'] ?? null), \RuntimeException::class,
                '需要の算定根拠を読み込めません。「おすすめ設定」で再計算してください。');
            throw_if(isset($basis['mean_daily']) && (! is_numeric($basis['mean_daily'])
                || ! is_finite((float) $basis['mean_daily'])
                || abs(round((float) $mean, 4) - (float) $basis['mean_daily']) > 0.00001), \RuntimeException::class,
                '平均消費量と算定根拠が一致しません。「おすすめ設定」で再計算してください。');
            foreach (range(0, 6) as $weekday) {
                $mean = $profile['weekdays'][$weekday]['mean'] ?? null;
                throw_unless(is_numeric($mean) && is_finite((float) $mean) && $mean >= 0 && $mean <= 2147483647,
                    \RuntimeException::class, '曜日別の消費量を確認できません。「おすすめ設定」で再計算してください。');
            }

            return array_replace($profile, ['legacy' => false]);
        }

        $mean = $basis['mean_daily'] ?? null;
        throw_unless(is_numeric($mean) && is_finite((float) $mean) && $mean > 0 && $mean <= 2147483647,
            \RuntimeException::class, '出荷見込が未設定です。「おすすめ設定」で再計算してください。');
        // The old top-level period describes receipt history, not necessarily the demand window.
        $period = $basis['demand_period'] ?? (isset($basis['demand_start'], $basis['period']['end'])
            ? ['start' => $basis['demand_start'], 'end' => $basis['period']['end']] : null);
        if ($period !== null) {
            throw_unless(is_array($period) && is_string($period['start'] ?? null) && is_string($period['end'] ?? null),
                \RuntimeException::class, '需要の算定期間を確認できません。「おすすめ設定」で再計算してください。');
            $this->date($period['start']);
            $this->date($period['end']);
            throw_if($period['start'] > $period['end'], \RuntimeException::class, '需要の算定期間を確認できません。「おすすめ設定」で再計算してください。');
        }
        $anchorEnd = $period['end'] ?? ($basis['period']['end'] ?? null);
        throw_unless($anchorEnd === null || is_string($anchorEnd), \RuntimeException::class,
            '需要の算定期間を確認できません。「おすすめ設定」で再計算してください。');

        // Old saved policies keep their saved demand level; never silently substitute live history.
        return ['version' => 'saved-mean-v1', 'legacy' => true, 'mean' => (float) $mean, 'period' => $period,
            'anchor_date' => $anchorEnd !== null ? $this->date($anchorEnd)->addDay()->toDateString() : '2000-01-02',
            'weekdays' => array_fill(0, 7, ['mean' => (float) $mean, 'fallback' => true, 'days' => 0, 'total' => 0]),
            'observed_days' => $basis['observed_days'] ?? 0, 'missing_days' => $basis['unknown_days'] ?? 0,
            'warnings' => ['旧設定のため保存済み平均を毎日共通で使用します。「おすすめ設定」で再計算・保存すると曜日別の消費に切り替わります。']];
    }

    public function quantity(array $profile, string $start, int $days): int
    {
        throw_unless($days >= 0 && $days <= 56, \RuntimeException::class, '需要の計算日数を確認してください。');
        $date = $this->date($start);
        // Carry fractional pieces across days. Summing graph days exactly equals the arrival forecast,
        // including slow-moving products whose daily mean is less than one piece.
        $quantity = (int) floor($this->cumulative($profile, $date->addDays($days)) + 1e-8)
            - (int) floor($this->cumulative($profile, $date) + 1e-8);
        throw_unless($quantity >= 0 && $quantity <= 2147483647, \RuntimeException::class, '期間内の消費量が上限を超えています。数量条件を確認してください。');

        return $quantity;
    }

    public function maximum(array $profile, int $days): int
    {
        throw_unless($days >= 0 && $days <= 56, \RuntimeException::class, '需要の計算日数を確認してください。');
        $maximum = 0;
        foreach (range(0, 6) as $weekday) {
            $total = 0;
            for ($i = 0; $i < $days; $i++) {
                $total += $profile['weekdays'][($weekday + $i) % 7]['mean'];
            }
            $maximum = max($maximum, (int) ceil($total - 1e-8));
        }
        throw_if($maximum > 2147483647, \RuntimeException::class, '期間内の消費量が上限を超えています。数量条件を確認してください。');

        return $maximum;
    }

    public function formula(array $profile, string $start, int $days): string
    {
        return '入荷までの消費見込：'.($profile['legacy'] ? '保存済み平均' : '曜日別の消費')."を{$days}日分合計 ＝ ".$this->quantity($profile, $start, $days).'バラ';
    }

    private function cumulative(array $profile, CarbonImmutable $date): float
    {
        $anchor = $this->date($profile['anchor_date']);
        $offset = (int) $anchor->diffInDays($date, false);
        $weeks = (int) floor($offset / 7);
        $remainder = $offset - $weeks * 7;
        $total = $weeks * array_sum(array_column($profile['weekdays'], 'mean'));
        for ($i = 0; $i < $remainder; $i++) {
            $total += $profile['weekdays'][($anchor->dayOfWeek + $i) % 7]['mean'];
        }

        return (float) $total;
    }

    private function date(string $date): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
            throw_unless($parsed && $parsed->toDateString() === $date, \RuntimeException::class);

            return $parsed;
        } catch (\Throwable) {
            throw new \RuntimeException('需要の算定日付を確認できません。「おすすめ設定」で再計算してください。');
        }
    }
}
