<?php

namespace Tests\Unit\Services;

use App\Services\SundryInventoryCount\SundryInventoryTheoryCalculator;
use App\Services\SundryInventoryCount\SundryManagedBookValue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SundryManagedBookValueTest extends SundryQuantityValuationServiceTest
{
    private function bookFixture(): void
    {
        $schema = Schema::connection('sakemaru');
        $schema->table('item_categories', function (Blueprint $t): void { $t->string('code')->nullable(); $t->integer('depth')->nullable(); });
        $schema->table('stats_monthly_stock_amount_reports', function (Blueprint $t): void { $t->string('category_code'); $t->decimal('closing_amount', 18, 2); });
        $schema->create('item_cost_snapshots', function (Blueprint $t): void {
            $t->id(); $t->integer('client_id'); $t->integer('item_id'); $t->string('target_year_month'); $t->decimal('cost_unit_price', 18, 6);
        });
        $schema->create('stats_item_stock_movement_monthly_summaries', function (Blueprint $t): void {
            $t->id(); $t->integer('client_id'); $t->integer('warehouse_id'); $t->integer('item_id'); $t->string('closing_month');
            $t->decimal('opening_quantity', 18, 3); $t->decimal('closing_quantity', 18, 3); $t->decimal('real_stock_quantity_at_close', 18, 3)->nullable();
        });
        $db = DB::connection('sakemaru');
        $db->table('item_categories')->where('id', 1)->update(['code' => '1004', 'depth' => 1]);
        foreach (['2026-05', '2026-06', '2026-07', '2026-08'] as $month) {
            $db->table('stats_item_stock_movement_monthly_summaries')->insert(['client_id' => 6, 'warehouse_id' => 10, 'item_id' => 4,
                'closing_month' => $month, 'opening_quantity' => 10, 'closing_quantity' => 10]);
            $db->table('item_cost_snapshots')->insert(['client_id' => 6, 'item_id' => 4, 'target_year_month' => $month,
                'cost_unit_price' => $month === '2026-08' ? 120 : 100]);
        }
        $db->table('stats_monthly_stock_amount_reports')->insert(['client_id' => 6, 'warehouse_id' => 10,
            'target_year_month' => '2026-08', 'category_code' => '4', 'closing_amount' => 1100]);
    }

    public function test_cost_change_does_not_create_a_false_unmanaged_gap(): void
    {
        $this->bookFixture();
        $ref = (new SundryInventoryTheoryCalculator)->unmanagedOpeningFromStockReport(6, 10, 1, '2026-09-29');
        $this->assertSame(1000.0, $ref['managed_amount']);
        $this->assertSame(1200.0, $ref['managed_revalued_amount']);
        $this->assertSame(100.0, $ref['amount']);
    }

    public function test_movements_use_each_months_cost_and_zero_cost_is_valid(): void
    {
        $this->bookFixture();
        $db = DB::connection('sakemaru');
        $db->table('stats_item_stock_movement_monthly_summaries')->where('closing_month', '2026-08')->update(['closing_quantity' => 12]);
        $this->assertSame(1240.0, (new SundryManagedBookValue)->calculate(6, 10, 1, '2026-08')['amount']);
        $db->table('item_cost_snapshots')->where('target_year_month', '2026-08')->update(['cost_unit_price' => 0]);
        $this->assertSame(1000.0, (new SundryManagedBookValue)->calculate(6, 10, 1, '2026-08')['amount']);
    }

    public function test_october_posting_uses_september_reference_even_when_october_report_is_regenerated(): void
    {
        $this->bookFixture();
        $db = DB::connection('sakemaru');
        foreach (['2026-09', '2026-10'] as $month) {
            $db->table('stats_item_stock_movement_monthly_summaries')->insert(['client_id' => 6, 'warehouse_id' => 10, 'item_id' => 4,
                'closing_month' => $month, 'opening_quantity' => 10, 'closing_quantity' => 10]);
            $db->table('item_cost_snapshots')->insert(['client_id' => 6, 'item_id' => 4, 'target_year_month' => $month, 'cost_unit_price' => 120]);
            $db->table('stats_monthly_stock_amount_reports')->insert(['client_id' => 6, 'warehouse_id' => 10,
                'target_year_month' => $month, 'category_code' => '4', 'closing_amount' => $month === '2026-09' ? 1280 : 9999]);
        }
        $calculator = new SundryInventoryTheoryCalculator;
        $proof = new \App\Support\SundryCalculationProof($db);
        [$reference, $queries] = $proof->capture(fn () => $calculator->unmanagedOpeningFromStockReport(6, 10, 1, '2026-10-29'));
        $this->assertSame('2026-09-30', $reference['date']);
        $this->assertSame(280.0, $reference['amount']);
        $this->assertSame($reference, $calculator->unmanagedOpeningFromStockReport(6, 10, 1, '2026-10-30'));
        $db->table('stats_monthly_stock_amount_reports')->where('target_year_month', '2026-10')->update(['closing_amount' => 1440]);
        $db->table('stats_item_stock_movement_monthly_summaries')->where('closing_month', '2026-10')->update(['closing_quantity' => 9]);
        $proof->verify($queries);
        $this->assertSame($reference, $calculator->unmanagedOpeningFromStockReport(6, 10, 1, '2026-10-29'));
    }

    public function test_missing_month_cost_or_discontinuous_quantity_is_not_invented(): void
    {
        $this->bookFixture();
        $db = DB::connection('sakemaru');
        $db->table('stats_item_stock_movement_monthly_summaries')->where('closing_month', '2026-08')->update(['opening_quantity' => 8]);
        $this->assertNull((new SundryManagedBookValue)->calculate(6, 10, 1, '2026-08'));
        $db->table('stats_item_stock_movement_monthly_summaries')->where('closing_month', '2026-08')->update(['opening_quantity' => 10]);
        $db->table('item_cost_snapshots')->where('target_year_month', '2026-07')->delete();
        $this->assertNotNull((new SundryManagedBookValue)->calculate(6, 10, 1, '2026-08'));
        $db->table('stats_item_stock_movement_monthly_summaries')->where('closing_month', '2026-08')->update(['closing_quantity' => 12]);
        $db->table('item_cost_snapshots')->where('target_year_month', '2026-08')->delete();
        $this->assertNull((new SundryManagedBookValue)->calculate(6, 10, 1, '2026-08'));
    }
}
