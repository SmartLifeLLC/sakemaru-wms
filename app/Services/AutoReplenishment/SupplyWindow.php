<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;

/** Pure classification of already reconciled, outstanding supply. Never invents a receipt date. */
final class SupplyWindow
{
    public const DAYS = 60;

    public function classify(array $events, string $businessDate): array
    {
        throw_unless($this->validDate($businessDate), \RuntimeException::class, '発注判定の業務日を確認してください。');
        $cutoff = CarbonImmutable::parse($businessDate)->addDays(self::DAYS)->toDateString();
        $totals = ['today' => 0, 'future' => 0, 'undated' => 0, 'excluded' => 0];
        $normalized = $warnings = $seen = [];
        foreach ($events as $event) {
            $pieces = $event['pieces'] ?? null;
            throw_unless(is_int($pieces) && $pieces >= 0 && $pieces <= 2147483647,
                \RuntimeException::class, '既発注の未入荷数量を確認できません。在庫・予定を再取得してください。');
            if (isset($event['supply_id'])) {
                throw_if(isset($seen[$event['supply_id']]), \RuntimeException::class, '同じ入荷予定が重複しています。管理者に紐付けを確認してください。');
                $seen[$event['supply_id']] = true;
            }
            $raw = array_key_exists('raw_date', $event) ? $event['raw_date'] : ($event['date'] ?? null);
            $valid = $this->validDate($raw);
            $date = null;
            $bucket = 'undated';
            $code = $raw === null || $raw === '' ? 'SUPPLY_DATE_UNKNOWN' : ($valid ? 'SUPPLY_DATE_OVERDUE' : 'SUPPLY_DATE_INVALID');
            if ($valid && $raw >= $businessDate) {
                // Existing supply keeps its recorded date, including an individually arranged Sunday receipt.
                $date = $raw;
                $bucket = $date === $businessDate ? 'today' : ($date <= $cutoff ? 'future' : 'excluded');
            }
            $totals[$bucket] += $pieces;
            if ($bucket === 'undated' && $pieces > 0) {
                $warnings[] = ['code' => $code, 'supply_id' => $event['supply_id'] ?? null, 'raw_date' => $raw, 'pieces' => $pieces,
                    'message' => '入荷日未定・期限超過等の既発注 '.$pieces.'バラを発注判定に含めています。入荷日を確認してください（見込在庫線には未加算）。'];
            }
            $normalized[] = array_replace($event, ['raw_date' => $raw, 'date' => $date, 'bucket' => $bucket]);
        }
        $total = array_sum($totals);
        throw_if($total > 2147483647, \RuntimeException::class, '既発注の未入荷合計が扱える数量を超えています。');

        return $totals + ['total' => $total, 'included' => $total - $totals['excluded'], 'cutoff_date' => $cutoff,
            'events' => $normalized, 'warnings' => $warnings];
    }

    /** Must run before the existing, idempotent today-incoming adapter. */
    public function prepare(array $snapshot): array
    {
        throw_unless(isset($snapshot['supply_events']) && is_array($snapshot['supply_events'])
            && is_int($snapshot['incoming'] ?? null) && ($snapshot['claims'] ?? null) === 0,
            \RuntimeException::class, '既発注の明細を確認できません。在庫・予定を再取得してください。');
        $window = $this->classify($snapshot['supply_events'], $snapshot['business_date'] ?? '');
        throw_unless($window['total'] === $snapshot['incoming'], \RuntimeException::class, '未入荷残と入荷予定明細の合計が一致しません。');

        return array_replace($snapshot, ['incoming' => $window['included'], 'incoming_today' => $window['today'],
            'supply_window' => $window, 'raw_stock' => $snapshot['stock'] ?? null]);
    }

    public function validDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
