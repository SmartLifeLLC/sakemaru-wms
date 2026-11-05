<?php

namespace App\Services\SundryInventoryCount;

use App\Models\WmsSundryInventoryCount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Approved book-only differences never change item quantities or unmanaged movement balances. */
class SundryBookReconciliation
{
    public const TABLE = 'wms_sundry_book_reconciliations';

    public function approvedGap(WmsSundryInventoryCount $count, string $category1Code, string $referenceDate): float
    {
        if (! $this->clientMatches($count) || $referenceDate !== config('sundry_book_reconciliation.reference_date')) {
            return 0.0;
        }
        $warehouse = (int) $count->warehouse_code;
        $unmanaged = (int) $category1Code === 1004
            ? array_sum(config("sundry_book_reconciliation.unmanaged_gaps.{$warehouse}", [])) : 0;
        return $unmanaged + (float) collect(config("sundry_book_reconciliation.managed_gaps.{$warehouse}", []))
            ->where('category1_code', (int) $category1Code)->sum('amount');
    }

    public function includeInLines(WmsSundryInventoryCount $count, string $date, array $lines, array $references): array
    {
        if (! $this->clientMatches($count)) {
            return $lines;
        }
        $warehouse = (int) $count->warehouse_code;
        foreach ($lines as &$line) {
            $gap = 0.0;
            if ($line['kind'] === 'amount') {
                $gap = (float) config("sundry_book_reconciliation.unmanaged_gaps.{$warehouse}.{$line['category2_code']}", 0);
                $key = '2026-09-legacy-amount-'.$line['category2_code'];
            } elseif ($line['kind'] === 'managed') {
                $gap = (float) config("sundry_book_reconciliation.managed_gaps.{$warehouse}.{$line['item_code']}.amount", 0);
                $key = '2026-09-legacy-item-'.$line['item_code'];
            }
            if ($gap === 0.0) {
                continue;
            }
            if (! Schema::connection('sakemaru')->hasTable(self::TABLE)) {
                throw ValidationException::withMessages(['finalization' => '承認済み差額の適用履歴テーブルがありません。更新作業を完了してください。']);
            }
            if (DB::connection('sakemaru')->table(self::TABLE)->where('client_id', $count->client_id)
                ->where('warehouse_id', $count->warehouse_id)->where('approval_key', $key)->exists()) {
                continue;
            }
            if ($date !== config('sundry_book_reconciliation.process_date')
                || ($references[$line['category1_id']]['date'] ?? null) !== config('sundry_book_reconciliation.reference_date')) {
                throw ValidationException::withMessages(['finalization' => '承認済みの帳簿差額は8月末報告書を照合し、2026/09/30付で適用してください。']);
            }
            if ($line['kind'] === 'amount' && ($line['original_count_row']['opening_source'] !== 'legacy'
                || substr($line['original_count_row']['opening_date'], 0, 10) !== '2026-05-05')) {
                throw ValidationException::withMessages(['finalization' => '承認済み差額の対象分類で前残が変更されています。差額を二重計上しないよう前残の根拠を確認してください。']);
            }
            $line['inventory_difference_amount'] = $line['amount'];
            $line['book_reconciliation'] = ['approval_key' => $key, 'amount' => -$gap,
                'category1_code' => (string) $line['category1_code'],
                'reference_date' => config('sundry_book_reconciliation.reference_date'),
                'report_reconciliation' => $references[$line['category1_id']]];
            $line['amount'] = round($line['amount'] - $gap, 2);
            if ($line['kind'] === 'amount') {
                $line['inventory_book_before'] = $line['book_before'];
                $line['inventory_count_theory'] = $line['count_theory'];
                $line['book_before'] = round($line['book_before'] + $gap, 2);
                $line['count_theory'] = round($line['count_theory'] + $gap, 2);
            }
        }
        return $lines;
    }

    private function clientMatches(WmsSundryInventoryCount $count): bool
    {
        return (int) $count->client_id === (int) config('sundry_book_reconciliation.client_id');
    }

    public function includeUnknownLines(WmsSundryInventoryCount $count, string $date, array $lines, array $references): array
    {
        foreach ($references as $category => $ref) {
            $gap = (float) $ref['unknown_gap'];
            if ($gap === 0.0) {
                continue;
            }
            if (! $this->clientMatches($count) || $date !== config('sundry_book_reconciliation.process_date')
                || $ref['date'] !== config('sundry_book_reconciliation.reference_date')) {
                throw ValidationException::withMessages(['finalization' => '部門の不明差異は8月末の帳簿を照合し、2026/09/30付で確認してください。']);
            }
            if (! Schema::connection('sakemaru')->hasTable(self::TABLE)) {
                throw ValidationException::withMessages(['finalization' => '不明差異の履歴テーブルがありません。更新作業を完了してください。']);
            }
            $key = 'unknown-'.$ref['date'].'-'.$ref['category1_code'];
            if (DB::connection('sakemaru')->table(self::TABLE)->where('client_id', $count->client_id)
                ->where('warehouse_id', $count->warehouse_id)->where('approval_key', $key)->exists()) {
                continue;
            }
            // Department residuals must not be assigned to a counted product or to cheese alone.
            $lines[] = [
                'kind' => 'book', 'source_id' => null, 'item_id' => null, 'item_code' => '',
                'item_name' => '棚卸不明差異（部門帳簿差額・数量変更なし）',
                'category1_id' => $category, 'category1_code' => $ref['category1_code'],
                'category1_name' => $ref['category1_name'] ?? '',
                'category2_id' => null, 'category2_code' => '', 'category2_name' => '',
                'count_theory' => null, 'count_actual' => null, 'quantity_before' => null, 'quantity_after' => null,
                'difference_quantity' => null, 'unit_price' => null, 'book_before' => null, 'book_after' => null,
                'amount' => -$gap, 'inventory_difference_amount' => 0.0, 'reason' => '棚卸不明差異',
                'book_reconciliation' => ['approval_key' => $key, 'amount' => -$gap,
                    'category1_code' => (string) $ref['category1_code'], 'reference_date' => $ref['date'],
                    'report_reconciliation' => $ref],
            ];
        }
        return $lines;
    }
}
