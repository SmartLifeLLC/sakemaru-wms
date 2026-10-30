<?php

namespace App\Services\AutoReplenishment;

/** Advisory assessment only; never changes an order quantity or ON/OFF state. */
final class SafetyStockAssessment
{
    public function assess(int $minimum, int $shortageDays, ?int $safetyPieces, ?int $safetyDays, ?float $mean): array
    {
        $known = $safetyPieces !== null && $safetyDays !== null && $mean !== null && is_finite($mean) && $mean >= 0;
        $gap = $safetyPieces === null ? null : max(0, $safetyPieces - $minimum);
        $status = 'ACCEPTABLE';
        $reason = '安全在庫の下回りは平均消費量の1日分以下で、許容範囲です。';
        if ($shortageDays > 0 || $minimum <= 0) {
            $status = 'REVIEW';
            $reason = '欠品または在庫ゼロ以下の見込みがあるため、設定の見直しが必要です。';
        } elseif ($safetyDays !== null && $safetyDays < 3) {
            $status = 'REVIEW';
            $reason = '安全在庫日数を3日以上にして、おすすめ設定で再計算してください。';
        } elseif (! $known) {
            $status = 'UNASSESSED';
            $reason = '安全在庫の判定根拠が不足しています。おすすめ設定で再計算してください。';
        } elseif ($gap > $mean) {
            $status = 'REVIEW';
            $reason = '安全在庫を平均消費量の1日分を超えて下回るため、設定の見直しが必要です。';
        } elseif ($gap === 0) {
            $reason = '試算最低在庫は安全在庫以上で、許容範囲です。';
        }

        return ['status' => $status, 'requires_review' => $status === 'REVIEW', 'reason' => $reason,
            'shortfall_pieces' => $gap, 'shortfall_days' => $known && $mean > 0 ? $gap / $mean : null,
            'mean_daily' => $mean, 'safety_days' => $safetyDays];
    }
}
