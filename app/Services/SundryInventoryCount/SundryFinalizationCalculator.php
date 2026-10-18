<?php

namespace App\Services\SundryInventoryCount;

use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryValuationItem;
use App\Services\InventoryCount\InventoryCountLedgerBalanceService;
use App\Support\SundryCalculationProof;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only calculation. Entered quantities and original count-day prices never change. */
class SundryFinalizationCalculator
{
    public function __construct(
        private readonly SundryInventoryTheoryCalculator $theory = new SundryInventoryTheoryCalculator,
        private readonly InventoryCountLedgerBalanceService $ledger = new InventoryCountLedgerBalanceService,
    ) {}

    public function calculate(WmsSundryInventoryCount $count, string $date): array
    {
        $date = $this->date($date);
        $countDate = $count->count_date->toDateString();
        if ($count->status !== WmsSundryInventoryCount::STATUS_CONFIRMED) {
            $this->fail('先に棚卸し入力を確定してください。');
        }
        if (SundryAmountAdjustmentService::isApplied($count->id)) {
            $this->fail('この棚卸しは既に金額適用されています。二重適用を防ぐため処理を停止しました。調整履歴を確認してください。');
        }
        if ($date < InventoryCountLedgerBalanceService::OPENING_DATE || $date > today()->toDateString()) {
            $this->fail('適用日は2026/05/06以降、今日以前の日付を指定してください。');
        }
        if ($countDate > today()->toDateString()) {
            $this->fail('棚卸し実施日が未来です。実際に数えた日付を確認してください。');
        }
        (new SundryQuantityValuationService)->assertComplete($count);
        $items = $count->items()->orderBy('id')->get();
        $amounts = $count->amounts()->orderBy('id')->get();
        if ($items->contains(fn ($r) => $r->counted_quantity === null) || $amounts->contains(fn ($r) => $r->counted_amount === null)) {
            $this->fail('未入力の商品数量または分類別実棚金額があります。0の場合も明示的に入力してください。');
        }
        $db = DB::connection('sakemaru');
        $ids = $items->pluck('item_id')->all();
        $master = $db->table('items as i')->join('item_categories as c1', 'c1.id', '=', 'i.item_category1_id')
            ->where('i.client_id', $count->client_id)->whereIn('i.id', $ids)
            ->where('i.is_managed_stock', true)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('item_sets as s')
                ->whereColumn('s.id', 'i.item_set_id')->where('s.is_active', true)->where('s.set_type', 'OWNED'))
            ->get(['i.id', 'i.item_category1_id', 'i.item_category2_id', 'c1.code as category1_code'])->keyBy('id');
        if ($master->count() !== count(array_unique($ids))) {
            $this->fail('商品の在庫管理設定・自社セット設定が変わっています。対象商品を確認してください。');
        }
        $countBalances = $ids === [] ? [] : $this->theory->managedBalances($count->client_id, $count->warehouse_id, $countDate);
        $postingBalances = $ids === [] ? [] : $this->ledger->balancesBeforeAdjustmentByItem($count->client_id, $count->warehouse_id, $date);
        $costs = $db->table('item_cost_snapshots')->where('client_id', $count->client_id)
            ->where('target_year_month', substr($date, 0, 7))->whereIn('item_id', $ids)->get()->keyBy('item_id');
        [$allocations, $normal] = (new SundryCalculationProof($db))->applicationOnly(fn () => [
            $db->table('real_stocks')->where('client_id', $count->client_id)->where('warehouse_id', $count->warehouse_id)
                ->whereIn('item_id', $ids)->select('item_id', 'stock_allocation_id')->distinct()->get()->groupBy('item_id'),
            $db->table('stock_allocations')->where('client_id', $count->client_id)->where('code', '1')->value('id'),
        ]);
        $lines = [];
        foreach ($items as $item) {
            $this->assertSupportedCategory((string) $master[$item->item_id]->category1_code);
            if ((int) $master[$item->item_id]->item_category2_id !== (int) $item->category2_id) {
                $this->fail("商品CD {$item->item_code} の中分類が変わっています。対象商品を確認してください。");
            }
            if (! isset($costs[$item->item_id])) {
                $this->fail("商品CD {$item->item_code} の適用月原価がありません。在庫金額報告書用の原価を保存してください。");
            }
            $allocationIds = ($allocations[$item->item_id] ?? collect())->pluck('stock_allocation_id')->unique();
            if ($allocationIds->count() > 1 || (! $allocationIds->count() && ! $normal)) {
                $this->fail("商品CD {$item->item_code} の在庫区分を一意に決められません。在庫区分を確認してください。");
            }
            $theory = round((float) ($countBalances[$item->item_id] ?? 0), 3);
            $delta = round($item->counted_quantity - $theory, 3);
            $before = round((float) ($postingBalances[$item->item_id] ?? 0), 3);
            $price = (float) $costs[$item->item_id]->cost_unit_price;
            $rawAmount = BigDecimal::of((string) $delta)->multipliedBy((string) $costs[$item->item_id]->cost_unit_price);
            $lines[] = [
                'kind' => 'managed', 'source_id' => $item->id, 'item_id' => $item->item_id,
                'item_code' => $item->item_code, 'item_name' => $item->item_name,
                'category1_id' => $master[$item->item_id]->item_category1_id,
                'category1_code' => $master[$item->item_id]->category1_code,
                'category2_id' => $item->category2_id, 'category2_code' => $item->category2_code, 'category2_name' => $item->category2_name,
                'stock_allocation_id' => $allocationIds->first() ?? $normal,
                'count_theory' => $theory, 'count_actual' => $item->counted_quantity,
                'quantity_before' => $before, 'quantity_after' => round($before + $delta, 3), 'difference_quantity' => $delta,
                'unit_price' => $price, 'cost_snapshot_id' => $costs[$item->item_id]->id,
                'amount' => $rawAmount->toScale(2, RoundingMode::HALF_UP)->toFloat(),
                'unrounded_amount' => (string) $rawAmount, 'original_count_row' => $item->getAttributes(),
            ];
        }
        foreach ($amounts as $amount) {
            $this->assertSupportedCategory((string) $amount->category1_code);
            if (! $amount->opening_date || $amount->opening_source === 'default') {
                $this->fail("中分類CD {$amount->category2_code} の前残が未設定です。帳簿を確認してください。");
            }
            $openingDate = $amount->opening_date->toDateString();
            $opening = $this->effectiveOpening($count, $amount->getAttributes());
            $earliest = min($date, $countDate);
            if ($openingDate > $earliest) {
                $this->fail("中分類CD {$amount->category2_code} の前残基準日より前には適用できません。");
            }
            $prior = $db->table(SundryAmountAdjustmentService::TABLE)->where('client_id', $count->client_id)
                ->where('warehouse_id', $count->warehouse_id)->where('category2_id', $amount->category2_id)
                ->where('is_active', true)->where('process_date', '>', $openingDate)->where('process_date', '<=', $earliest)
                ->orderByDesc('process_date')->orderByDesc('id')->first();
            if ($prior) {
                $openingDate = $prior->process_date;
                $opening = $prior->book_amount + $prior->adjustment_amount;
            }
            $book = fn (string $end): float => $this->bookAmount($count, $amount->category2_id, $openingDate, $opening, $end);
            $countBook = $book($countDate);
            $postingBook = $book($date);
            $delta = round($amount->counted_amount - $countBook, 2);
            $values = WmsSundryValuationItem::where('amount_id', $amount->id)->orderBy('id')->get();
            $current = $db->table('items as i')->leftJoin('item_categories as c1', 'c1.id', '=', 'i.item_category1_id')
                ->leftJoin('item_categories as c2', 'c2.id', '=', 'i.item_category2_id')->leftJoin('item_categories as c3', 'c3.id', '=', 'i.item_category3_id')
                ->where('i.client_id', $count->client_id)->whereIn('i.id', $values->pluck('item_id'))
                ->where('i.is_managed_stock', false)->where('i.item_category1_id', $amount->category1_id)->where('i.item_category2_id', $amount->category2_id)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('item_sets as s')->whereColumn('s.id', 'i.item_set_id')->where('s.is_active', true)->where('s.set_type', 'OWNED'))
                ->selectRaw('i.id, COALESCE(c3.cost_rate, c2.cost_rate, c1.cost_rate, 0) AS cost_rate')->get()->keyBy('id');
            foreach ($values as $value) {
                if (! isset($current[$value->item_id]) || round((float) $current[$value->item_id]->cost_rate, 4) !== round((float) $value->cost_rate, 4)) {
                    $this->fail("商品CD {$value->item_code} の管理区分・分類・原価率が入力時と異なります。評価根拠を確認してください。");
                }
            }
            if ($values->isNotEmpty() && round($values->sum('amount'), 2) !== round($amount->counted_amount, 2)) {
                $this->fail("中分類CD {$amount->category2_code} の商品評価合計が実棚金額と一致しません。");
            }
            $lines[] = [
                'kind' => 'amount', 'source_id' => $amount->id, 'item_id' => null, 'item_code' => '', 'item_name' => '',
                'category1_id' => $amount->category1_id, 'category1_code' => $amount->category1_code,
                'category2_id' => $amount->category2_id, 'category2_code' => $amount->category2_code, 'category2_name' => $amount->category2_name,
                'count_theory' => $countBook, 'count_actual' => $amount->counted_amount,
                'book_before' => $postingBook, 'book_after' => round($postingBook + $delta, 2),
                'quantity_before' => null, 'quantity_after' => null, 'difference_quantity' => null,
                'unit_price' => null, 'amount' => $delta, 'opening_date' => $openingDate, 'opening_amount' => $opening,
                'original_count_row' => $amount->getAttributes(), 'valuations' => $values->map->getAttributes()->all(),
            ];
        }
        if ($lines === []) {
            $this->fail('確定する棚卸し明細がありません。');
        }
        $references = $this->assertReportOpenings($count, $lines, min($date, $countDate));
        $lines = (new SundryBookReconciliation)->includeInLines($count, $date, $lines, $references);
        $lines = (new SundryBookReconciliation)->includeUnknownLines($count, $date, $lines, $references);
        $lines = $this->allocateManagedRounding($lines);
        (new SundryCalculationProof($db))->applicationOnly(
            fn () => $this->assertNoLaterAdjustment($count, $date, $ids, $amounts->pluck('category2_id')->all())
        );
        return ['version' => 3, 'count_id' => $count->id, 'count_no' => $count->count_no,
            'client_id' => $count->client_id, 'warehouse_id' => $count->warehouse_id, 'warehouse_code' => $count->warehouse_code,
            'count_date' => $countDate, 'process_date' => $date, 'lines' => $lines,
            'report_reconciliations' => $references,
            'total_amount' => round(array_sum(array_column($lines, 'amount')), 2),
            'inputs' => ['header' => $count->getAttributes(), 'items' => $items->map->getAttributes()->all(), 'amounts' => $amounts->map->getAttributes()->all()],
        ];
    }

    private function allocateManagedRounding(array $lines): array
    {
        $totals = [];
        foreach ($lines as $line) {
            $category = $line['category1_id'];
            $fixedAmount = $line['kind'] === 'managed' ? ($line['book_reconciliation']['amount'] ?? 0) : $line['amount'];
            $totals[$category] = ($totals[$category] ?? BigDecimal::zero())->plus((string) $fixedAmount);
        }
        foreach ($lines as &$line) {
            if ($line['kind'] !== 'managed') {
                continue;
            }
            // Include amount-only adjustments before rounding; opposite signs matter at half-cent boundaries.
            $category = $line['category1_id'];
            $previous = $totals[$category];
            $totals[$category] = $previous->plus($line['unrounded_amount']);
            $amount = $totals[$category]->toScale(2, RoundingMode::HALF_UP)
                ->minus($previous->toScale(2, RoundingMode::HALF_UP));
            $line['rounding_adjustment'] = $amount->minus(BigDecimal::of($line['unrounded_amount'])->toScale(2, RoundingMode::HALF_UP))->toFloat();
            if (isset($line['book_reconciliation'])) {
                $line['inventory_difference_amount'] = $amount->toFloat();
            }
            $line['amount'] = $amount->plus((string) ($line['book_reconciliation']['amount'] ?? 0))->toFloat();
        }
        return $lines;
    }

    private function bookAmount(WmsSundryInventoryCount $count, int $category, string $openingDate, float $opening, string $end): float
    {
        $flows = $this->theory->unmanagedFlows($count->client_id, $count->warehouse_id, [$category], CarbonImmutable::parse($openingDate)->addDay()->toDateString(), $end)[$category];
        return round($opening + $flows['purchase'] + $flows['transfer_in'] - $flows['transfer_out'] - $flows['sales_cost'] + $flows['adjustment'], 2);
    }

    private function effectiveOpening(WmsSundryInventoryCount $count, array $row): float
    {
        if ($row['opening_source'] === 'legacy' && substr($row['opening_date'], 0, 10) === '2026-05-05'
            && (int) $row['category2_code'] === 2052) {
            return $this->theory->unmanagedLegacyOpening((string) $count->warehouse_code, '2052', '2026-05-05')['amount']
                ?? (float) $row['opening_amount'];
        }
        return (float) $row['opening_amount'];
    }

    private function assertReportOpenings(WmsSundryInventoryCount $count, array $lines, string $date): array
    {
        $groups = collect($lines)->where('kind', 'amount')->groupBy('category1_id');
        $policy = new SundryBookReconciliation;
        foreach (collect($lines)->where('kind', 'managed') as $line) {
            if (! $groups->has($line['category1_id']) && $policy->approvedGap($count, $line['category1_code'], config('sundry_book_reconciliation.reference_date')) !== 0.0) {
                $groups[$line['category1_id']] = collect();
            }
        }
        $references = [];
        foreach ($groups as $category => $rows) {
            $categoryCode = collect($lines)->firstWhere('category1_id', $category)['category1_code'];
            $ref = $this->theory->unmanagedOpeningFromStockReport($count->client_id, $count->warehouse_id, $category, CarbonImmutable::parse($date)->subDay()->toDateString());
            if (! $ref) {
                $this->fail("大分類CD {$categoryCode} の前残を照合できません。前月報告書、5月以降の月次数量の連続性、金額計算に必要な保存原価を確認してください。");
            }
            $expectedCategories = $this->theory->unmanagedCategories($count->client_id, [$category]);
            if ($rows->pluck('category2_id')->diff($expectedCategories->pluck('category2_id'))->isNotEmpty()) {
                $this->fail('非管理品の分類構成が変わっています。対象分類を確認してください。');
            }
            $sum = 0;
            foreach ($expectedCategories as $expected) {
                $row = $rows->firstWhere('category2_id', $expected->category2_id);
                if ($row) {
                    $openingDate = substr($row['original_count_row']['opening_date'], 0, 10);
                    $opening = $this->effectiveOpening($count, $row['original_count_row']);
                } else {
                    // Reconcile the full department without creating counts for unselected categories.
                    $basis = $this->reconciliationOpening($count, $expected, $ref['date']);
                    $openingDate = $basis['date'];
                    $opening = $basis['amount'];
                }
                if ($openingDate > $ref['date']) {
                    $this->fail('前残基準日が報告書の照合日より後です。前残の根拠を確認してください。');
                }
                $sum += $this->bookAmount($count, $expected->category2_id, $openingDate, $opening, $ref['date']);
            }
            $approvedGap = $policy->approvedGap($count, $categoryCode, $ref['date']);
            $references[$category] = ['date' => $ref['date'], 'category1_code' => $categoryCode,
                'category1_name' => DB::connection('sakemaru')->table('item_categories')->where('id', $category)->value('name'),
                'unmanaged_book_amount' => round($sum, 2), 'approved_gap' => $approvedGap, 'report_residual_amount' => $ref['amount'],
                'unknown_gap' => round($ref['amount'] - $sum - $approvedGap, 2), 'reference' => $ref];
        }
        return $references;
    }

    private function reconciliationOpening(WmsSundryInventoryCount $count, object $category, string $date): array
    {
        $prior = DB::connection('sakemaru')->table(SundryAmountAdjustmentService::TABLE)
            ->where('client_id', $count->client_id)->where('warehouse_id', $count->warehouse_id)
            ->where('category2_id', $category->category2_id)->where('is_active', true)->where('process_date', '<=', $date)
            ->orderByDesc('process_date')->orderByDesc('id')->first();
        if ($prior) {
            return ['date' => $prior->process_date, 'amount' => round($prior->book_amount + $prior->adjustment_amount, 2)];
        }
        $legacy = $this->theory->unmanagedLegacyOpening((string) $count->warehouse_code, (string) $category->category2_code, $date);
        if ($legacy === null) {
            $this->fail("中分類CD {$category->category2_code} の照合用前残がありません。帳簿を確認してください。");
        }
        return $legacy;
    }

    private function assertSupportedCategory(string $code): void
    {
        if (! in_array((int) $code, [1001, 1002, 1003, 1004, 1005, 1006], true)) {
            $this->fail("大分類CD {$code} は雑貨棚卸しの適用対象外です。容器等の専用評価には適用できません。");
        }
    }

    private function assertNoLaterAdjustment(WmsSundryInventoryCount $count, string $date, array $itemIds, array $categoryIds): void
    {
        $db = DB::connection('sakemaru');
        if ($db->table('inventory_adjustments as a')->join('inventory_adjustment_items as ai', 'ai.inventory_adjustment_id', '=', 'a.id')
            ->join('trade_items as ti', 'ti.id', '=', 'ai.trade_item_id')->join('trades as t', 't.id', '=', 'a.trade_id')
            ->where('a.client_id', $count->client_id)->where('a.warehouse_id', $count->warehouse_id)->where('a.is_active', true)
            ->where('t.is_active', true)->where('t.is_latest', true)->where('a.adjustment_date', '>', $date)->whereIn('ti.item_id', $itemIds)->exists()
            || $db->table(SundryAmountAdjustmentService::TABLE)->where('client_id', $count->client_id)->where('warehouse_id', $count->warehouse_id)
                ->where('is_active', true)->whereIn('category2_id', $categoryIds)->where('process_date', '>', $date)->exists()) {
            $this->fail('適用日より後に対象商品の棚卸し調整があります。先行伝票を確認してください。');
        }
    }

    public function assertPeriodOpen(WmsSundryInventoryCount $count, string $date): void
    {
        $db = DB::connection('sakemaru');
        if ((int) $count->warehouse_code === 23) {
            $this->fail('この倉庫は在庫金額報告書の前残固定対象のため最終確定できません。');
        }
        if ($db->table('stats_monthly_stock_amount_reports')->where('client_id', $count->client_id)->where('warehouse_id', $count->warehouse_id)
            ->where('target_year_month', '>=', substr($date, 0, 7))->exists()) {
            $this->fail('適用月以降の在庫金額報告書が保存されています。先に再作成手順を確認してください。');
        }
    }

    private function date(string $value): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if ($date->toDateString() === $value) {
                return $value;
            }
        } catch (\Throwable) {}
        $this->fail('適用日を正しい日付で指定してください。');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['finalization' => $message]);
    }
}
