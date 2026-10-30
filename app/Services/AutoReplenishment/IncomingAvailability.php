<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;

/** Calculation only: expected receipts never update real stock or receipt records. */
final class IncomingAvailability
{
    /** Actual transfer remainder is expected supply even without a confirmed arrival schedule. */
    public function transferDate(?string $date, ?string $businessDate): ?string
    {
        if ($businessDate === null) {
            return $date;
        }
        $arrival = CarbonImmutable::parse($date === null || $date < $businessDate ? $businessDate : $date);

        return ($arrival->isSunday() ? $arrival->addDay() : $arrival)->toDateString();
    }

    public function isToday(?string $date, ?string $businessDate): bool
    {
        return $businessDate !== null && $date === $businessDate && ! CarbonImmutable::parse($businessDate)->isSunday();
    }

    public function forCalculation(array $snapshot): array
    {
        $today = $snapshot['incoming_today'] ?? 0;
        throw_unless(is_int($today) && $today >= 0 && $today <= 2147483647,
            \RuntimeException::class, '当日入荷予定の数量を確認できません。在庫・予定を再取得してください。');
        if ($today === 0) {
            return $snapshot;
        }
        $stock = $snapshot['stock'] ?? null;
        $incoming = $snapshot['incoming'] ?? null;
        throw_unless(is_int($stock) && $stock >= -2147483648 && $stock <= 2147483647
            && is_int($incoming) && $incoming >= $today && $incoming <= 2147483647,
            \RuntimeException::class, '当日入荷予定と未入荷残が一致しません。在庫・予定を再取得してください。');
        throw_if($stock + $today > 2147483647, \RuntimeException::class, '当日入荷後の見込在庫が扱える数量を超えています。数量条件を確認してください。');
        $snapshot['stock'] = $stock + $today;
        $snapshot['incoming'] = $incoming - $today;
        $snapshot['incoming_today'] = 0; // Applying the calculation adapter again must not count it twice.

        return $snapshot;
    }

    public function explanation(array $raw, array $calculated): string
    {
        return '当日入荷予定 '.($raw['incoming_today'] ?? 0).'バラを計算上の在庫に加算：現在庫 '.$raw['stock'].' → '.$calculated['stock'].'バラ、未入荷残 '.$raw['incoming'].' → '.$calculated['incoming'].'バラ（実在庫は変更しません）。';
    }
}
