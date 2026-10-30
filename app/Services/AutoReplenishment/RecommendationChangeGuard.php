<?php

namespace App\Services\AutoReplenishment;

/**
 * Pure acceptance rule shared by the weekly refresh and the replay: which quantities changed,
 * and whether a change exceeds the allowed ratio (held for review instead of being adopted).
 */
final class RecommendationChangeGuard
{
    public const GUARDED = ['reorder_point_pieces', 'target_stock_pieces', 'max_order_pieces', 'min_order_pieces', 'order_multiple_pieces'];

    /**
     * @return array{changes: array<string, array{from: int, to: int}>, held: bool, reason: ?string}
     */
    public function evaluate(array $before, array $after, float $maxChange): array
    {
        throw_unless($maxChange >= 1.1 && $maxChange <= 10, \RuntimeException::class, '変化幅の上限は1.1〜10で指定してください。');
        $changes = [];
        foreach (self::GUARDED as $key) {
            if ((int) ($before[$key] ?? 0) !== (int) ($after[$key] ?? 0)) {
                $changes[$key] = ['from' => (int) ($before[$key] ?? 0), 'to' => (int) ($after[$key] ?? 0)];
            }
        }
        foreach ($changes as $key => $change) {
            $ratio = $change['from'] > 0 ? $change['to'] / $change['from'] : INF;
            if ($ratio > $maxChange || $ratio < 1 / $maxChange) {
                return ['changes' => $changes, 'held' => true,
                    'reason' => "{$key} が {$change['from']} → {$change['to']}（×".($ratio === INF ? '∞' : round($ratio, 2))."）で変化幅の上限 ×{$maxChange} を超えるため保留。画面で確認してください。"];
            }
        }

        return ['changes' => $changes, 'held' => false, 'reason' => null];
    }
}
