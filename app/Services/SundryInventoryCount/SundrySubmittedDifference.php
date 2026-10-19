<?php

namespace App\Services\SundryInventoryCount;

use App\Models\WmsSundryInventoryCount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Department-only differences: never invent product quantities or allocate to middle categories. */
class SundrySubmittedDifference
{
    public function __construct(private readonly SundryInventoryTheoryCalculator $theory = new SundryInventoryTheoryCalculator) {}

    public static function eligible(WmsSundryInventoryCount $count): bool
    {
        $entry = config('sundry_submitted_difference.counts.'.$count->id);
        return $entry !== null && (int) $count->client_id === (int) config('sundry_submitted_difference.client_id')
            && (int) $count->warehouse_code === (int) $entry['warehouse_code'];
    }

    public function append(WmsSundryInventoryCount $count, string $date, array $lines): array
    {
        $policy = config('sundry_submitted_difference');
        $entry = $policy['counts'][$count->id] ?? null;
        if (! $entry || (int) $count->client_id !== (int) $policy['client_id']
            || (int) $count->warehouse_code !== (int) $entry['warehouse_code']
            || $date !== $policy['process_date']) {
            $this->fail('顧客提出差異の対象棚卸し・倉庫・適用日（2026/09/30）が一致しません。');
        }
        $db = DB::connection('sakemaru');
        if ($db->table(SundryBookReconciliation::TABLE)->where('client_id', $count->client_id)
            ->where('warehouse_id', $count->warehouse_id)->where('approval_key', $policy['approval_key'])->exists()) {
            $this->fail('この倉庫の顧客提出差異は計上済みです。入力済み管理品のみを選択してください。');
        }
        $category = $db->table('item_categories')->where('client_id', $count->client_id)
            ->where('depth', 1)->where('code', $policy['category1_code'])->sole();
        $lines[] = $this->line($category, $entry['amount'], $policy['approval_key'], $date,
            '顧客提出の非管理品差異（41＋47）', [
                'type' => 'submitted', 'count_id' => $count->id, 'warehouse_code' => $count->warehouse_code,
                'includes_warehouse_codes' => $entry['includes_warehouse_codes'] ?? [$entry['warehouse_code']],
                'submitted_amount' => $entry['amount'],
            ]);

        return $lines;
    }

    public function balance(WmsSundryInventoryCount $count, string $categoryCode, string $date): float
    {
        $balance = 0.0;
        foreach ($this->journals($count, $categoryCode, $date) as $journal) {
            $line = json_decode($journal->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $balance = ($line['department_adjustment']['type'] ?? '') === 'absorption'
                ? 0.0 : round($balance + $journal->adjustment_amount, 2);
        }
        return $balance;
    }

    public function hasHistory(WmsSundryInventoryCount $count, string $categoryCode, string $date): bool
    {
        return $this->journals($count, $categoryCode, $date)->isNotEmpty();
    }

    /** When the whole department is recounted, absorb its unallocated balance exactly once. */
    public function absorb(WmsSundryInventoryCount $count, string $date, array $lines, array $references): array
    {
        foreach (collect($lines)->where('kind', 'amount')->groupBy('category1_id') as $categoryId => $rows) {
            $code = (string) $rows->first()['category1_code'];
            if (DB::connection('sakemaru')->table(SundryBookReconciliation::TABLE)
                ->where('client_id', $count->client_id)->where('warehouse_id', $count->warehouse_id)->where('category1_code', $code)
                ->where('approval_key', 'like', 'submitted-%')->where('process_date', '>', $date)->exists()) {
                $this->fail('適用日より後に大分類金額調整があります。先行伝票を確認してください。');
            }
            $journals = $this->journals($count, $code, min($date, $count->count_date->toDateString()));
            $balance = $this->balance($count, $code, min($date, $count->count_date->toDateString()));
            $referenceGap = (float) ($references[$categoryId]['department_reference_gap'] ?? 0);
            if ($balance === 0.0 && $referenceGap === 0.0) {
                continue;
            }
            $expected = $this->theory->unmanagedCategories($count->client_id, [$categoryId]);
            if ($expected->pluck('category2_id')->diff($rows->pluck('category2_id'))->isNotEmpty()) {
                $this->fail('過去の大分類金額調整があるため、非管理品は部門内の全中分類を数えてから確定してください。未入力分は棚卸損にしません。');
            }
            $category = (object) ['id' => $categoryId, 'code' => $code, 'name' => $references[$categoryId]['category1_name'] ?? ''];
            $lines[] = $this->line($category, round(-$balance - $referenceGap, 2), 'submitted-absorb-'.$count->id.'-'.$code, $date,
                '前回の大分類調整を今回実棚へ織込み（重複調整防止）', [
                    'type' => 'absorption', 'prior_journal_ids' => $journals->pluck('id')->all(),
                    'prior_balance' => $balance,
                    'report_opening_gap' => $referenceGap,
                    'report_reconciliation' => $references[$categoryId],
                ]);
        }

        return $lines;
    }

    private function journals(WmsSundryInventoryCount $count, string $categoryCode, string $date)
    {
        if (! Schema::connection('sakemaru')->hasTable(SundryBookReconciliation::TABLE)) {
            return collect();
        }
        return DB::connection('sakemaru')->table(SundryBookReconciliation::TABLE)
            ->where('client_id', $count->client_id)->where('warehouse_id', $count->warehouse_id)
            ->where('category1_code', $categoryCode)->where('process_date', '<=', $date)
            ->where('approval_key', 'like', 'submitted-%')->orderBy('process_date')->orderBy('id')->get();
    }

    private function line(object $category, float $amount, string $key, string $date, string $name, array $audit): array
    {
        return [
            'kind' => 'book', 'source_id' => null, 'item_id' => null, 'item_code' => '', 'item_name' => $name,
            'category1_id' => $category->id, 'category1_code' => (string) $category->code, 'category1_name' => $category->name,
            'category2_id' => null, 'category2_code' => '', 'category2_name' => '',
            'count_theory' => null, 'count_actual' => null, 'quantity_before' => null, 'quantity_after' => null,
            'difference_quantity' => null, 'unit_price' => null, 'book_before' => null, 'book_after' => null,
            'amount' => $amount, 'inventory_difference_amount' => 0.0, 'reason' => $name,
            'department_adjustment' => $audit,
            'book_reconciliation' => ['approval_key' => $key, 'amount' => $amount,
                'category1_code' => (string) $category->code, 'reference_date' => $date,
                'report_reconciliation' => ['date' => $date, 'unmanaged_book_amount' => null, 'approved_gap' => null]],
        ];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['finalization' => $message]);
    }
}
