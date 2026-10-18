<?php

namespace Tests\Unit\Services;

use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountAmount;
use App\Services\SundryInventoryCount\SundryInventoryTheoryCalculator;
use App\Services\SundryInventoryCount\SundryMonthlyOpeningDisplay;
use Mockery;
use Tests\TestCase;

class SundryMonthlyOpeningDisplayTest extends TestCase
{
    private function countWithReference(?string $date = '2026-08-31'): WmsSundryInventoryCount
    {
        return new WmsSundryInventoryCount([
            'client_id' => 6, 'warehouse_id' => 1, 'warehouse_code' => '1', 'count_date' => '2026-10-06',
            'theory_end_date' => '2026-10-06', 'amount_report_references' => [[
                'category1_id' => 1, 'report_date' => $date, 'opening_amount' => $date ? 4483 : null,
            ]],
        ]);
    }

    private function amount(int $id, string $code, float $opening): WmsSundryInventoryCountAmount
    {
        $row = new WmsSundryInventoryCountAmount([
            'category1_id' => 1, 'category1_code' => '1004', 'category1_name' => '雑貨',
            'category2_id' => $id, 'category2_code' => $code, 'category2_name' => $code,
            'opening_source' => 'legacy', 'opening_date' => '2026-05-05', 'opening_amount' => $opening,
            'counted_amount' => 123.45, 'purchase_amount' => 10, 'system_amount' => $opening + 10,
        ]);
        $row->id = $id;

        return $row;
    }

    public function test_august_opening_is_calculated_by_middle_category_without_mutating_inputs_or_finalization_basis(): void
    {
        $count = $this->countWithReference();
        $rows = collect([$this->amount(41, '2041', 1000), $this->amount(47, '2047', 500)]);
        $before = $rows->map->getAttributes()->all();
        $header = $count->getAttributes();
        $calculator = Mockery::mock(SundryInventoryTheoryCalculator::class);
        $calculator->shouldReceive('unmanagedFlows')->once()->with(6, 1, [41, 47], '2026-05-06', '2026-08-31', 2)
            ->andReturn(array_fill_keys([41, 47], ['purchase' => 100, 'transfer_in' => 20, 'transfer_out' => 10, 'sales_cost' => 85, 'adjustment' => 5]));
        $calculator->shouldNotReceive('unmanagedOpeningFromStockReport');
        $display = new SundryMonthlyOpeningDisplay($calculator);
        $result = $display->build($count, $rows);

        $this->assertSame([1030.0, 530.0], array_column($result['rows'], 'calculated_amount'));
        $this->assertSame([2698.0, 225.0], array_column($result['rows'], 'reconciliation_amount'));
        $this->assertSame([3728.0, 755.0], array_column($result['rows'], 'opening_amount'));
        $this->assertSame(4483.0, $result['totals'][0]['opening_amount']);
        $this->assertSame(0.0, $result['totals'][0]['difference_amount']);
        $this->assertSame($before, $rows->map->getAttributes()->all());
        $this->assertSame($header, $count->getAttributes());
    }

    public function test_manual_and_previous_count_openings_never_receive_legacy_correction(): void
    {
        foreach (['manual', 'previous_count'] as $source) {
            $row = $this->amount(41, '2041', 1000);
            $row->opening_source = $source;
            $calculator = Mockery::mock(SundryInventoryTheoryCalculator::class);
            $calculator->shouldReceive('unmanagedFlows')->once()->andReturn([41 => $this->zeroFlow()]);
            $result = (new SundryMonthlyOpeningDisplay($calculator))->build($this->countWithReference(), collect([$row]));
            $this->assertSame(1000.0, $result['rows'][0]['opening_amount']);
            $this->assertSame(0.0, $result['rows'][0]['reconciliation_amount']);
        }
    }

    public function test_correction_does_not_leak_to_a_different_client_or_report_month(): void
    {
        foreach (['client', 'month'] as $case) {
            $count = $this->countWithReference($case === 'month' ? '2026-09-30' : '2026-08-31');
            if ($case === 'client') {
                $count->client_id = 7;
            }
            $calculator = Mockery::mock(SundryInventoryTheoryCalculator::class);
            $calculator->shouldReceive('unmanagedFlows')->once()->andReturn([41 => $this->zeroFlow()]);
            $result = (new SundryMonthlyOpeningDisplay($calculator))->build($count, collect([$this->amount(41, '2041', 1000)]));
            $this->assertSame(0.0, $result['rows'][0]['reconciliation_amount']);
        }
    }

    public function test_unknown_openings_are_not_reported_as_zero_and_partial_totals_are_not_reported_as_complete(): void
    {
        foreach (['no_report', 'later_opening', 'default', 'no_date', 'future_report'] as $case) {
            $count = $this->countWithReference($case === 'no_report' ? null : ($case === 'future_report' ? '2026-10-31' : '2026-08-31'));
            $row = $this->amount(41, '2041', 1000);
            if ($case === 'later_opening') {
                $row->opening_date = '2026-09-01';
            } elseif ($case === 'no_date') {
                $row->opening_date = null;
            } elseif ($case === 'default') {
                $row->opening_source = 'default';
            }
            $calculator = Mockery::mock(SundryInventoryTheoryCalculator::class);
            $calculator->shouldNotReceive('unmanagedFlows');
            $result = (new SundryMonthlyOpeningDisplay($calculator))->build($count, collect([$row]));
            $this->assertNull($result['rows'][0]['opening_amount']);
            $this->assertNull($result['totals'][0]['opening_amount']);
            $this->assertNull($result['totals'][0]['difference_amount']);
        }
    }

    public function test_unreferenced_department_is_looked_up_once_and_existing_nonzero_mismatch_stays_visible(): void
    {
        $count = $this->countWithReference();
        $count->amount_report_references = [];
        $calculator = Mockery::mock(SundryInventoryTheoryCalculator::class);
        $calculator->shouldReceive('unmanagedOpeningFromStockReport')->once()->with(6, 1, 1, '2026-10-06')
            ->andReturn(['date' => '2026-08-31', 'amount' => 5000]);
        $calculator->shouldReceive('unmanagedFlows')->once()->andReturn([41 => $this->zeroFlow(), 47 => $this->zeroFlow()]);
        $result = (new SundryMonthlyOpeningDisplay($calculator))->build($count,
            collect([$this->amount(41, '2041', 1000), $this->amount(47, '2047', 500)]));
        $this->assertSame(-577.0, $result['totals'][0]['difference_amount']);
        $this->assertSame([], $count->amount_report_references);
    }

    public function test_same_day_opening_is_not_counted_twice(): void
    {
        $row = $this->amount(41, '2041', 1000);
        $row->opening_date = '2026-08-31';
        $calculator = Mockery::mock(SundryInventoryTheoryCalculator::class);
        $calculator->shouldReceive('unmanagedFlows')->once()->with(6, 1, [41], '2026-09-01', '2026-08-31', 2)
            ->andReturn([41 => $this->zeroFlow()]);
        $result = (new SundryMonthlyOpeningDisplay($calculator))->build($this->countWithReference(), collect([$row]));
        $this->assertSame(1000.0, $result['rows'][0]['opening_amount']);
    }

    public function test_empty_count_does_not_query_or_invent_an_opening(): void
    {
        $calculator = Mockery::mock(SundryInventoryTheoryCalculator::class);
        $this->assertSame(['rows' => [], 'totals' => []],
            (new SundryMonthlyOpeningDisplay($calculator))->build($this->countWithReference(), collect()));
    }

    private function zeroFlow(): array
    {
        return ['purchase' => 0, 'transfer_in' => 0, 'transfer_out' => 0, 'sales_cost' => 0, 'adjustment' => 0];
    }
}
