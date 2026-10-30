<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Completed-day consumption. Missing POS feeds are not zero-sales days. */
class WeekdayDemand
{
    public function read(int $client, int $warehouse, int $item, string $businessDate): array
    {
        $asOf = CarbonImmutable::parse(min($businessDate, CarbonImmutable::now('Asia/Tokyo')->toDateString()));
        $period = app(DemandProfile::class)->historyPeriod($asOf->toDateString());
        $db = DB::connection('sakemaru');
        $key = 'auto-order-weekday-demand:v2:'.hash('sha256', json_encode([$db->getDatabaseName(), $client, $warehouse, $item, $period]));

        return Cache::store('file')->remember($key, 900, function () use ($db, $client, $warehouse, $item, $period) {
            $ids = $db->table('stock_allocations')->where(['client_id' => $client, 'code' => 1, 'is_active' => 1])->pluck('id');
            throw_unless($ids->count() === 1, \RuntimeException::class, '在庫区分「正常品」を特定できません。');
            $demand = app(SettingsRecommendationHistory::class)->dailyDemand($client, $warehouse, $item, (int) $ids->first(), $period);

            $selection = app(DemandProfile::class)->select($demand['daily'], $period);
            $profile = app(DemandProfile::class)->create($selection['daily'], $selection['fallback'], $selection['slow'] ?? false);
            $profile['warnings'] = array_merge($profile['warnings'], $demand['warnings']);

            return $profile;
        });
    }

    public function summarize(array $daily): array
    {
        $weekdays = array_fill(0, 7, ['total' => 0, 'days' => 0]);
        $missing = 0;
        foreach ($daily as $date => $quantity) {
            if ($quantity === null) {
                $missing++;

                continue;
            }
            throw_unless(is_int($quantity) && $quantity >= 0 && $quantity <= 2147483647, \RuntimeException::class, '販売履歴の数量を確認してください。');
            $day = CarbonImmutable::parse($date)->dayOfWeek;
            $weekdays[$day]['total'] += $quantity;
            $weekdays[$day]['days']++;
        }
        $days = array_sum(array_column($weekdays, 'days'));
        throw_unless($days > 0, \RuntimeException::class, '対象期間の販売・出荷実績が未連携のため、在庫推移を計算できません。');
        $mean = array_sum(array_column($weekdays, 'total')) / $days;
        foreach ($weekdays as &$day) {
            $day['mean'] = $day['days'] ? $day['total'] / $day['days'] : $mean;
            $day['fallback'] = $day['days'] === 0;
        }

        return ['weekdays' => $weekdays, 'observed_days' => $days, 'missing_days' => $missing, 'mean' => $mean];
    }
}
