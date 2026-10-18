<?php

namespace App\Services\SundryInventoryCount;

use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Read-only month-end presentation; never replace the source used by finalization. */
class SundryMonthlyOpeningDisplay
{
    public function __construct(private readonly SundryInventoryTheoryCalculator $calculator) {}

    public function build(WmsSundryInventoryCount $count, Collection $amounts): array
    {
        $references = collect($count->amount_report_references ?? [])->keyBy('category1_id');
        $endDate = ($count->theory_end_date ?? $count->count_date)->toDateString();
        $groups = [];
        $rows = [];

        foreach ($amounts as $amount) {
            $category = (int) $amount->category1_id;
            if (! $references->has($category)) {
                $report = $this->calculator->unmanagedOpeningFromStockReport((int) $count->client_id, (int) $count->warehouse_id, $category, $endDate);
                $references->put($category, [
                    'report_date' => $report['date'] ?? null,
                    'opening_amount' => $report['amount'] ?? null,
                ]);
            }
            $reference = $references->get($category);
            $date = $reference['report_date'] ?? null;
            $sourceDate = $amount->opening_date?->toDateString();
            $row = [
                'id' => $amount->id,
                'category1_id' => $category,
                'category1_code' => $amount->category1_code,
                'category1_name' => $amount->category1_name,
                'category2_code' => $amount->category2_code,
                'category2_name' => $amount->category2_name,
                'date' => $date,
                'calculated_amount' => null,
                'reconciliation_amount' => null,
                'opening_amount' => null,
                'status' => '報告書なし',
            ];
            if ($date && $sourceDate && $sourceDate <= $date && $date <= $endDate
                && $amount->opening_source !== WmsSundryInventoryCountAmount::OPENING_SOURCE_DEFAULT) {
                $key = $sourceDate.'|'.$date;
                $groups[$key]['from'] = CarbonImmutable::parse($sourceDate)->addDay()->toDateString();
                $groups[$key]['to'] = $date;
                $groups[$key]['amounts'][] = $amount;
                $row['status'] = '受払計算';
            } elseif ($date) {
                $row['status'] = $amount->opening_source === WmsSundryInventoryCountAmount::OPENING_SOURCE_DEFAULT
                    ? '計算元の前残が未設定' : '計算元の基準日を確認';
            }
            $rows[$amount->id] = $row;
        }

        // One flow calculation per date range, not one per middle category.
        foreach ($groups as $group) {
            $flows = $this->calculator->unmanagedFlows((int) $count->client_id, (int) $count->warehouse_id,
                array_map(fn ($amount) => (int) $amount->category2_id, $group['amounts']), $group['from'], $group['to'], 2);
            foreach ($group['amounts'] as $amount) {
                $flow = $flows[(int) $amount->category2_id];
                $calculated = round((float) $amount->opening_amount + $flow['purchase'] + $flow['transfer_in']
                    - $flow['transfer_out'] - $flow['sales_cost'] + $flow['adjustment'], 2);
                $gap = $this->historicalGap($count, $amount, $group['to']);
                $rows[$amount->id]['calculated_amount'] = $calculated;
                $rows[$amount->id]['reconciliation_amount'] = $gap;
                $rows[$amount->id]['opening_amount'] = round($calculated + $gap, 2);
            }
        }

        $totals = [];
        foreach (collect($rows)->groupBy('category1_id') as $category => $details) {
            $complete = $details->every(fn ($row) => $row['opening_amount'] !== null);
            $opening = $complete ? round($details->sum('opening_amount'), 2) : null;
            $reportOpening = $references->get($category)['opening_amount'] ?? null;
            $totals[] = [
                'category1_code' => $details->first()['category1_code'],
                'category1_name' => $details->first()['category1_name'],
                'date' => $details->first()['date'],
                'opening_amount' => $opening,
                'report_amount' => $reportOpening,
                'difference_amount' => $opening !== null && $reportOpening !== null ? round($opening - $reportOpening, 2) : null,
            ];
        }

        return ['rows' => array_values($rows), 'totals' => $totals];
    }

    private function historicalGap(WmsSundryInventoryCount $count, WmsSundryInventoryCountAmount $amount, string $date): float
    {
        if ((int) $count->client_id !== (int) config('sundry_book_reconciliation.client_id')
            || $date !== config('sundry_book_reconciliation.reference_date')
            || (int) $amount->category1_code !== 1004
            || $amount->opening_source !== WmsSundryInventoryCountAmount::OPENING_SOURCE_LEGACY
            || $amount->opening_date?->toDateString() !== '2026-05-05') {
            return 0.0;
        }

        // This is the historical August balance, not a new application of the September adjustment.
        return (float) config('sundry_book_reconciliation.unmanaged_gaps.'.(int) $count->warehouse_code.'.'.$amount->category2_code, 0);
    }
}
