<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Chart-only calendar: use existing lead times, with the operational Sunday exclusion. */
class InventoryProjectionCalendar
{
    public function read(int $warehouse, int $contractor, string $channel, string $date, array $config): array
    {
        $db = DB::connection('sakemaru');
        $row = $db->table('wms_contractor_warehouse_delivery_days')->where(['contractor_id' => $contractor, 'warehouse_id' => $warehouse])->first();
        throw_unless($row, \RuntimeException::class, '倉庫別の納品曜日が未設定です。発注先の納品曜日を登録してから在庫推移を確認してください。');
        $allowed = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $index => $name) {
            if ($row->{'delivery_'.$name}) {
                $allowed[] = $index + 1;
            }
        }
        throw_unless($allowed, \RuntimeException::class, '日曜日以外の納品曜日が未設定です。発注先の納品曜日を確認してください。');
        $schedule = app(RecommendationArrivalSchedule::class)->calculate($warehouse, $contractor, $channel, $date, $config);
        $holidays = $db->table('wms_warehouse_calendars')->where(['warehouse_id' => $warehouse, 'is_holiday' => 1])
            ->whereBetween('target_date', [$date, CarbonImmutable::parse($date)->addDays(90)->toDateString()])->pluck('target_date')->all();
        $holidays = array_merge($holidays, $db->table('wms_contractor_holidays')->where('contractor_id', $contractor)
            ->whereBetween('holiday_date', [$date, CarbonImmutable::parse($date)->addDays(90)->toDateString()])->pluck('holiday_date')->all());

        return $this->normalize($schedule['opportunities'], $allowed, $holidays)
            + ['weekdays' => $allowed, 'sunday_master_enabled' => (bool) $row->delivery_sun];
    }

    public function normalize(array $opportunities, array $allowed, array $holidays): array
    {
        $result = [];
        $adjusted = false;
        foreach ($opportunities as $entry) {
            $arrival = CarbonImmutable::parse($entry['arrival_date']);
            for ($i = 0; $i < 28; $i++, $arrival = $arrival->addDay()) {
                if (! $arrival->isSunday() && in_array($arrival->dayOfWeek, $allowed, true) && ! in_array($arrival->toDateString(), $holidays, true)) {
                    break;
                }
            }
            throw_if($i === 28, \RuntimeException::class, '28日以内に入荷可能日がありません。納品曜日・休日設定を確認してください。');
            $adjusted = $adjusted || $arrival->toDateString() !== $entry['arrival_date'];
            $result[$entry['generation_date']] = $arrival->toDateString();
        }
        ksort($result);

        return ['arrivals' => $result, 'adjusted' => $adjusted];
    }
}
