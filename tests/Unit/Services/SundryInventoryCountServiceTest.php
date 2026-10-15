<?php

namespace Tests\Unit\Services;

use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountAmount;
use App\Models\WmsSundryInventoryCountItem;
use App\Services\SundryInventoryCount\SundryInventoryCountService;
use App\Services\SundryInventoryCount\SundryInventoryDifferenceWorkbookService;
use App\Services\SundryInventoryCount\SundryInventoryInstructionSheetPdfService;
use App\Services\SundryInventoryCount\SundryInventorySettings;
use App\Services\SundryInventoryCount\SundryInventoryTheoryCalculator;
use App\Services\SundryInventoryCount\WeighedInventoryDifferenceWorkbookService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SundryInventoryCountServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::connection('sakemaru')->hasTable('wms_sundry_inventory_counts')) {
            $this->markTestSkipped('wms_sundry_inventory_counts table is not available.');
        }
    }

    public function test_item_row_values_quantity_by_cost(): void
    {
        $item = new WmsSundryInventoryCountItem(['cost_price' => 120.5, 'system_quantity' => 10]);

        $item->recalculate();
        $this->assertSame(1205.0, $item->system_amount);
        $this->assertNull($item->counted_amount);
        $this->assertNull($item->difference_quantity);
        $this->assertNull($item->difference_amount);

        $item->counted_quantity = 7;
        $item->recalculate();
        $this->assertSame(843.5, $item->counted_amount);
        $this->assertSame(-3.0, $item->difference_quantity);
        $this->assertSame(-361.5, $item->difference_amount);
    }

    public function test_amount_row_uses_flow_formula(): void
    {
        $row = new WmsSundryInventoryCountAmount([
            'opening_amount' => 1000,
            'purchase_amount' => 300,
            'transfer_in_amount' => 50,
            'transfer_out_amount' => 20,
            'sales_cost_amount' => 400,
            'adjustment_amount' => 10,
        ]);

        $row->recalculate();
        $this->assertSame(940.0, $row->system_amount);
        $this->assertNull($row->difference_amount);

        $row->counted_amount = 900;
        $row->recalculate();
        $this->assertSame(-40.0, $row->difference_amount);
    }

    public function test_default_targets_are_the_sundry_departments(): void
    {
        $this->assertSame(
            [2040, 2041, 2043, 2044, 2047, 2051, 2052],
            SundryInventorySettings::defaultCategoryCodes(),
        );
        $this->assertSame([1004], SundryInventorySettings::defaultAmountCategoryCodes());
        // 旧システムは 5/5 まで。新システムの受払は 5/6 から。
        $this->assertSame('2026-05-05', SundryInventorySettings::defaultOpeningDate());
    }

    public function test_create_limits_details_to_target_categories_and_values_them_by_amount(): void
    {
        $fixture = $this->fixture();
        $inventoryCountsBefore = DB::connection('sakemaru')->table('wms_inventory_counts')->count();

        $count = $this->service($fixture)->create($this->createData($fixture) + ['memo' => 'phpunit']);

        $this->assertSame(WmsSundryInventoryCount::STATUS_COUNTING, $count->status);
        $this->assertSame(WmsSundryInventoryCount::KIND_SUNDRY, $count->fresh()->kind);
        $this->assertFalse($count->isWeighed());
        $this->assertSame('2026-06-30', $count->theory_end_date->toDateString());
        $this->assertSame([$fixture['target_category_id']], $count->targetCategoryIds());
        $this->assertSame([$fixture['major_category_id']], $count->amountCategoryIds());

        // 数量明細は対象中分類の在庫管理あり商品だけ。
        $items = $count->items()->get();
        $this->assertSame([$fixture['managed_item_id']], $items->pluck('item_id')->map(fn ($id) => (int) $id)->all());
        $item = $items->first();
        $this->assertSame('2041', $item->category2_code);
        $this->assertSame('雑貨', $item->category2_name);
        $this->assertSame(7.0, $item->system_quantity);
        $this->assertSame(100.0, $item->cost_price);
        $this->assertSame(700.0, $item->system_amount);
        $this->assertFalse($item->is_additional);

        // 金額明細は在庫管理なし商品を持つ中分類ごとに1行。前残の元データが無ければ0円・既定の基準日。
        $amounts = $count->amounts()->get();
        $this->assertCount(1, $amounts);
        $amount = $amounts->first();
        $this->assertSame('1004', $amount->category1_code);
        $this->assertSame('雑貨', $amount->category1_name);
        $this->assertSame($fixture['target_category_id'], (int) $amount->category2_id);
        $this->assertSame('2041', $amount->category2_code);
        $this->assertSame('雑貨', $amount->category2_name);
        $this->assertFalse($amount->is_additional);
        $this->assertSame(SundryInventorySettings::defaultOpeningDate(), $amount->opening_date->toDateString());
        $this->assertSame(WmsSundryInventoryCountAmount::OPENING_SOURCE_DEFAULT, $amount->opening_source);
        $this->assertSame('未設定', $amount->opening_source_label);
        $this->assertSame(0.0, $amount->opening_amount);
        $this->assertSame(1000.0, $amount->purchase_amount);
        $this->assertSame($this->expectedSalesCost(), $amount->sales_cost_amount);
        $this->assertSame(1000.0 - $this->expectedSalesCost(), $amount->system_amount);

        // 在庫金額報告書が無い大分類は、突合欄に「報告書なし」で残す。
        $references = $this->service($fixture)->reportReferences($count->fresh());
        $this->assertCount(1, $references);
        $this->assertSame('1004', $references[0]['category1_code']);
        $this->assertNull($references[0]['report_month']);
        $this->assertNull($references[0]['difference_amount']);
        $this->assertEquals(1000.0 - $this->expectedSalesCost(), $references[0]['detail_system_amount']);

        // 既存の棚卸しには何も作らない。
        $this->assertSame($inventoryCountsBefore, DB::connection('sakemaru')->table('wms_inventory_counts')->count());
    }

    public function test_target_subcategories_get_amount_rows_without_selecting_the_major_category(): void
    {
        $fixture = $this->fixture();

        // 大分類を選ばなくても、対象中分類に在庫管理なし商品があれば金額明細を作る（例: 店舗限定のチーズ）。
        $count = $this->service($fixture)->create(['amount_category_ids' => []] + $this->createData($fixture));

        $amount = $count->amounts()->sole();
        $this->assertSame('2041', $amount->category2_code);
        $this->assertFalse($amount->is_additional);
        $this->assertSame(1000.0, $amount->purchase_amount);
        // 突合は大分類を選んだときだけ。
        $this->assertSame([], $this->service($fixture)->reportReferences($count->fresh()));
    }

    public function test_opening_amount_comes_from_the_legacy_balance_and_is_reconciled_with_the_stock_report(): void
    {
        $fixture = $this->fixture();
        // 旧システム残高: 中分類2041 の残高 3,800（このテストでは基準日 5/31 とする）。
        // 在庫金額報告書 2026-05（大分類）: 月末金額 5,000（うち在庫管理あり 1,200）→ 非管理品 3,800。
        $service = $this->service($fixture, [
            'month' => '2026-05',
            'date' => '2026-05-31',
            'report_amount' => 5000.0,
            'managed_amount' => 1200.0,
            'amount' => 3800.0,
        ], ['2041' => ['date' => '2026-05-31', 'amount' => 3800.0]]);
        $count = $service->create($this->createData($fixture));
        $amount = $count->amounts()->firstOrFail();
        $flow = 1000.0 - $this->expectedSalesCost();

        $this->assertSame(WmsSundryInventoryCountAmount::OPENING_SOURCE_LEGACY, $amount->opening_source);
        $this->assertSame('旧システム残高', $amount->opening_source_label);
        $this->assertSame('2026-05-31', $amount->opening_date->toDateString());
        $this->assertSame(3800.0, $amount->opening_amount);
        // 受払は基準日の翌日（6/1）から。6/10の仕入と6/15の売上が入る。
        $this->assertSame(1000.0, $amount->purchase_amount);
        $this->assertSame(3800.0 + $flow, $amount->system_amount);

        // 大分類の合計は、在庫金額報告書から求めた理論金額と一致する。
        $references = $service->reportReferences($count->fresh());
        $this->assertCount(1, $references);
        $this->assertSame('1004', $references[0]['category1_code']);
        $this->assertSame('2026-05', $references[0]['report_month']);
        $this->assertEquals(5000.0, $references[0]['report_amount']);
        $this->assertEquals(1200.0, $references[0]['managed_amount']);
        $this->assertEquals(3800.0, $references[0]['opening_amount']);
        $this->assertEquals($flow, $references[0]['flow_amount']);
        $this->assertEquals(3800.0 + $flow, $references[0]['system_amount']);
        $this->assertEquals(3800.0 + $flow, $references[0]['detail_system_amount']);
        $this->assertEquals(0.0, $references[0]['difference_amount']);

        // 手で直した前残は、理論在庫更新では自動の値に戻さない。報告書との差として見える。
        $service->saveChanges($count, [], [$amount->id => ['opening_amount' => '4000']], 'WEB: phpunit');
        $service->refreshTheory($count, '2026-06-30');
        $amount->refresh();
        $this->assertSame(WmsSundryInventoryCountAmount::OPENING_SOURCE_MANUAL, $amount->opening_source);
        $this->assertSame(4000.0, $amount->opening_amount);
        $this->assertEquals(200.0, $service->reportReferences($count->fresh())[0]['difference_amount']);

        // 指定したときだけ自動の値で上書きする。
        $service->refreshTheory($count, '2026-06-30', true);
        $amount->refresh();
        $this->assertSame(WmsSundryInventoryCountAmount::OPENING_SOURCE_LEGACY, $amount->opening_source);
        $this->assertSame(3800.0, $amount->opening_amount);
    }

    public function test_opening_amount_prefers_the_previous_confirmed_count(): void
    {
        $fixture = $this->fixture();
        $service = $this->service($fixture, null, ['2041' => ['date' => '2026-05-31', 'amount' => 3800.0]]);

        $previous = $service->create(['count_date' => '2026-06-20'] + $this->createData($fixture));
        $service->saveChanges($previous, [], [$previous->amounts()->firstOrFail()->id => ['counted_amount' => '900']], 'WEB: phpunit');
        $service->confirm($previous, null);

        $count = $service->create($this->createData($fixture));
        $amount = $count->amounts()->firstOrFail();

        // 前回確定した棚卸しの実棚金額が前残。受払は前回棚卸し日の翌日から（6/10仕入・6/15売上は入らない）。
        $this->assertSame(WmsSundryInventoryCountAmount::OPENING_SOURCE_PREVIOUS_COUNT, $amount->opening_source);
        $this->assertSame('前回棚卸の実棚', $amount->opening_source_label);
        $this->assertSame('2026-06-20', $amount->opening_date->toDateString());
        $this->assertSame(900.0, $amount->opening_amount);
        $this->assertSame(0.0, $amount->purchase_amount);
        $this->assertSame(900.0, $amount->system_amount);
    }

    public function test_legacy_opening_balances_are_read_per_warehouse_and_subcategory(): void
    {
        $calculator = new SundryInventoryTheoryCalculator;

        // database/data/sundry_inventory/nonmanaged_opening/2026-05-05.csv（旧システム最終日 2026-05-05 の残高）
        $this->assertSame(['date' => '2026-05-05', 'amount' => 312448.0], $calculator->unmanagedLegacyOpening('1', '2041', '2026-10-06'));
        $this->assertSame(['date' => '2026-05-05', 'amount' => 383049.0], $calculator->unmanagedLegacyOpening('01', '2047', '2026-05-05'));
        // 残高データのある倉庫で、行の無い中分類は 0円。
        $this->assertSame(['date' => '2026-05-05', 'amount' => 0.0], $calculator->unmanagedLegacyOpening('1', '2040', '2026-10-06'));
        // 基準日より前、または残高データの無い倉庫は対象なし。
        $this->assertNull($calculator->unmanagedLegacyOpening('1', '2041', '2026-05-04'));
        $this->assertNull($calculator->unmanagedLegacyOpening('999999', '2041', '2026-10-06'));
    }

    public function test_save_changes_calculates_differences_and_recalculates_flows_from_new_opening_date(): void
    {
        $fixture = $this->fixture();
        $service = $this->service($fixture);
        $count = $service->create($this->createData($fixture));
        $item = $count->items()->firstOrFail();
        $amount = $count->amounts()->firstOrFail();

        $result = $service->saveChanges(
            $count,
            [$item->id => '５'],
            [$amount->id => ['counted_amount' => '1,250', 'opening_amount' => '300']],
            'WEB: phpunit',
        );

        $this->assertSame(['items' => 1, 'amounts' => 1], $result);

        $item->refresh();
        $this->assertSame(5.0, $item->counted_quantity);
        $this->assertSame(-2.0, $item->difference_quantity);
        $this->assertSame(500.0, $item->counted_amount);
        $this->assertSame(-200.0, $item->difference_amount);
        $this->assertSame('WEB: phpunit', $item->counted_by_name);

        $amount->refresh();
        $this->assertSame(300.0, $amount->opening_amount);
        $this->assertSame(WmsSundryInventoryCountAmount::OPENING_SOURCE_MANUAL, $amount->opening_source);
        $this->assertSame(1250.0, $amount->counted_amount);
        $this->assertSame(round(1250.0 - $amount->system_amount, 2), $amount->difference_amount);
        $this->assertSame(1000.0, $amount->purchase_amount);

        // 基準日を仕入日以降にすると、その仕入は受払に入らない。
        $service->saveChanges($count, [], [$amount->id => ['opening_date' => '2026-06-20']], 'WEB: phpunit');
        $amount->refresh();
        $this->assertSame('2026-06-20', $amount->opening_date->toDateString());
        $this->assertSame(0.0, $amount->purchase_amount);
        $this->assertSame(300.0, $amount->system_amount);

        // 実棚を空にすると未入力に戻る。
        $service->saveChanges($count, [$item->id => null], [], 'WEB: phpunit');
        $item->refresh();
        $this->assertNull($item->counted_quantity);
        $this->assertNull($item->difference_amount);
        $this->assertNull($item->counted_by_name);

        $this->expectException(ValidationException::class);
        $service->saveChanges($count, [$item->id => '12個'], [], 'WEB: phpunit');
    }

    public function test_items_outside_target_categories_can_be_added_by_code(): void
    {
        $fixture = $this->fixture();
        $service = $this->service($fixture);
        $count = $service->create($this->createData($fixture));

        $managed = $service->addItemByCode($count, (string) $fixture['other_managed_item_code']);
        $this->assertSame('item', $managed['type']);
        $this->assertTrue($managed['inserted']);

        $added = $count->items()->where('item_id', $fixture['other_managed_item_id'])->firstOrFail();
        $this->assertTrue($added->is_additional);
        $this->assertSame('2012', $added->category2_code);
        $this->assertSame(3.0, $added->system_quantity);

        $again = $service->addItemByCode($count, (string) $fixture['other_managed_item_code']);
        $this->assertFalse($again['inserted']);
        $this->assertSame(2, $count->items()->count());

        // 在庫管理なしの商品は、その中分類を金額明細に追加する。
        $unmanaged = $service->addItemByCode($count, (string) $fixture['other_unmanaged_item_code']);
        $this->assertSame('amount', $unmanaged['type']);
        $this->assertTrue($unmanaged['inserted']);
        $this->assertSame('2012', $unmanaged['category_code']);
        $addedAmount = $count->amounts()->where('category2_code', '2012')->firstOrFail();
        $this->assertTrue($addedAmount->is_additional);
        $this->assertSame('1001', $addedAmount->category1_code);
        $this->assertSame('焼酎', $addedAmount->category2_name);

        // 登録済みの中分類の商品なら増えない。
        $sameCategory = $service->addItemByCode($count, (string) $fixture['unmanaged_item_code']);
        $this->assertSame('amount', $sameCategory['type']);
        $this->assertFalse($sameCategory['inserted']);

        // 理論更新しても追加した明細は残る。
        $service->refreshTheory($count, '2026-06-30');
        $this->assertSame(2, $count->items()->count());
        $this->assertSame(2, $count->amounts()->count());

        $this->expectException(ValidationException::class);
        $service->addItemByCode($count, '0');
    }

    public function test_confirm_locks_input_and_summary_totals_amounts(): void
    {
        $fixture = $this->fixture();
        $service = $this->service($fixture);
        $count = $service->create($this->createData($fixture));
        $item = $count->items()->firstOrFail();
        $amount = $count->amounts()->firstOrFail();

        $this->assertSame(1, $service->fillUncountedWithZero($count, 'WEB: phpunit'));
        $service->saveChanges($count, [], [$amount->id => ['counted_amount' => 880]], 'WEB: phpunit');
        $service->confirm($count, null);

        $count->refresh();
        $this->assertSame(WmsSundryInventoryCount::STATUS_CONFIRMED, $count->status);
        $this->assertNotNull($count->confirmed_at);
        $this->assertSame(0.0, $item->fresh()->counted_quantity);

        try {
            $service->saveChanges($count, [$item->id => 1], [], 'WEB: phpunit');
            $this->fail('確定済みの棚卸しを変更できてはいけません。');
        } catch (ValidationException) {
            $this->assertSame(0.0, $item->fresh()->counted_quantity);
        }

        $summary = $service->summary($count);
        $unmanagedSystem = 1000.0 - $this->expectedSalesCost();

        $this->assertSame(['2041'], array_column($summary['managed'], 'code'));
        $this->assertSame(['2041'], array_column($summary['unmanaged'], 'code'));
        $this->assertSame(700.0, $summary['totals']['managed']['system']);
        $this->assertSame(-700.0, $summary['totals']['managed']['difference']);
        $this->assertSame($unmanagedSystem, $summary['totals']['unmanaged']['system']);
        $this->assertSame(880.0, $summary['totals']['unmanaged']['counted']);
        $this->assertSame(round(880.0 - $unmanagedSystem, 2), $summary['totals']['unmanaged']['difference']);
        $this->assertSame(700.0 + $unmanagedSystem, $summary['totals']['total']['system']);
        $this->assertSame(0, $summary['totals']['total']['uncounted_count']);

        $service->reopen($count);
        $this->assertSame(WmsSundryInventoryCount::STATUS_COUNTING, $count->fresh()->status);
    }

    public function test_reports_are_generated_for_a_count(): void
    {
        $fixture = $this->fixture();
        $service = $this->service($fixture);
        $count = $service->create($this->createData($fixture));
        $service->saveChanges($count, [$count->items()->firstOrFail()->id => 9], [], 'WEB: phpunit');

        $pdfService = new SundryInventoryInstructionSheetPdfService;
        $this->assertSame(['2041' => '[2041]雑貨'], $pdfService->categoryOptions($count));
        $this->assertStringStartsWith('%PDF', $pdfService->generate($count, ['2041'], false));
        $this->assertStringStartsWith('%PDF', $pdfService->generate($count, null, true, false));
        $this->assertStringStartsWith('%PDF', $pdfService->generate($count));

        $workbook = (new SundryInventoryDifferenceWorkbookService)->build($count);
        $this->assertSame(['集計', '数量明細', '金額明細', '非管理品仕入'], $workbook->getSheetNames());

        $itemSheet = $workbook->getSheetByName('数量明細');
        $this->assertSame((string) $fixture['managed_item_code'], (string) $itemSheet->getCell('C2')->getValue());
        $this->assertEquals(7, $itemSheet->getCell('F2')->getValue());
        $this->assertEquals(9, $itemSheet->getCell('G2')->getValue());
        $this->assertEquals(200, $itemSheet->getCell('K2')->getValue());

        $amountSheet = $workbook->getSheetByName('金額明細');
        $this->assertSame('1004', (string) $amountSheet->getCell('A2')->getValue());
        $this->assertSame('2041', (string) $amountSheet->getCell('C2')->getValue());
        $this->assertSame('雑貨', $amountSheet->getCell('D2')->getValue());
        $this->assertSame('未設定', $amountSheet->getCell('G2')->getValue());
        $this->assertEquals(1000, $amountSheet->getCell('H2')->getValue());
        $this->assertSame('在庫金額報告書との突合（大分類別・参考）', $amountSheet->getCell('A7')->getValue());
        $this->assertSame('1004', (string) $amountSheet->getCell('A9')->getValue());
        $this->assertSame('報告書なし', $amountSheet->getCell('C9')->getValue());
        $this->assertEquals(1000 - $this->expectedSalesCost(), $amountSheet->getCell('I9')->getValue());

        $purchaseSheet = $workbook->getSheetByName('非管理品仕入');
        $this->assertSame('2041', (string) $purchaseSheet->getCell('E2')->getValue());
        $this->assertSame((string) $fixture['unmanaged_item_code'], (string) $purchaseSheet->getCell('F2')->getValue());
        $this->assertEquals(1000, $purchaseSheet->getCell('K2')->getValue());

        $summarySheet = $workbook->getSheetByName('集計');
        $this->assertSame('在庫管理あり（中分類・数量）', $summarySheet->getCell('A9')->getValue());
        $this->assertSame('2041', (string) $summarySheet->getCell('B9')->getValue());
        $this->assertEquals(700, $summarySheet->getCell('F9')->getValue());
        $this->assertSame('在庫管理なし（中分類・金額）', $summarySheet->getCell('A11')->getValue());
        $this->assertSame('2041', (string) $summarySheet->getCell('B11')->getValue());
        $this->assertSame('合計', $summarySheet->getCell('C13')->getValue());
        $workbook->disconnectWorksheets();
    }

    public function test_weighed_count_lists_configured_items_with_period_sales(): void
    {
        $fixture = $this->fixture();
        $this->useWeighedItems($fixture);
        $service = $this->service($fixture);

        $count = $service->create($this->weighedData($fixture));

        $this->assertSame(WmsSundryInventoryCount::KIND_WEIGHED, $count->kind);
        $this->assertTrue($count->isWeighed());
        $this->assertSame('2026-06-30', $count->theory_end_date->toDateString());
        $this->assertSame('2026-06-01', $count->sales_from_date->toDateString());
        $this->assertSame([], $count->targetCategoryIds());
        // 量り売りは数量明細だけ。金額明細は作らない。
        $this->assertSame(0, $count->amounts()->count());

        // 対象は設定の商品だけ。並びは設定の順（旧Accessの出力順）。
        $items = $count->items()->orderBy('display_order')->get();
        $this->assertSame(
            [(string) $fixture['other_managed_item_code'], (string) $fixture['managed_item_code']],
            $items->pluck('item_code')->all(),
        );
        $this->assertSame([1, 2], $items->pluck('display_order')->all());

        $weighed = $items->first();
        $this->assertSame('2012', $weighed->category2_code);
        $this->assertSame(3.0, $weighed->system_quantity);
        $this->assertSame(50.0, $weighed->cost_price);
        $this->assertSame(150.0, $weighed->system_amount);
        $this->assertFalse($weighed->is_additional);
        $this->assertNull($weighed->counted_quantity);
        // 期間売上: 6/15 の売上 3（開始日 6/1 〜 受払終了日 6/30）。売上の無い商品は 0。
        $this->assertSame(3.0, $weighed->period_sales_quantity);
        $this->assertSame(0.0, $items->last()->period_sales_quantity);

        // 開始日を売上日より後にすると、期間売上に入らない。
        $service->refreshTheory($count, '2026-06-30', false, '2026-06-16');
        $this->assertSame('2026-06-16', $count->fresh()->sales_from_date->toDateString());
        $this->assertSame(0.0, $weighed->fresh()->period_sales_quantity);

        // 開始日を渡さなければ、開始日はそのまま。
        $service->refreshTheory($count, '2026-06-30');
        $this->assertSame('2026-06-16', $count->fresh()->sales_from_date->toDateString());
    }

    public function test_weighed_count_requires_the_period_sales_start_date(): void
    {
        $fixture = $this->fixture();
        $this->useWeighedItems($fixture);

        try {
            $this->service($fixture)->create(['sales_from_date' => null] + $this->weighedData($fixture));
            $this->fail('期間売上の開始日なしで作成できてはいけません。');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sales_from_date', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $this->service($fixture)->create(['sales_from_date' => '2026-07-01'] + $this->weighedData($fixture));
    }

    public function test_weighed_count_takes_jar_and_reserve_quantities_and_summarizes_loss(): void
    {
        $fixture = $this->fixture();
        $this->useWeighedItems($fixture);
        // ロス率 50%: 期間売上 3 → ロス数量 round(1.5) = 2
        config(['wms_sundry_inventory.weighed_loss_rate' => 0.5]);
        $service = $this->service($fixture);
        $count = $service->create($this->weighedData($fixture));
        $weighed = $count->items()->where('item_id', $fixture['other_managed_item_id'])->firstOrFail();
        $other = $count->items()->where('item_id', $fixture['managed_item_id'])->firstOrFail();

        // 実棚数 = カメ + QT。差異数 = 実棚数 − 理論数、差異金額 = 差異数 × 原価。
        $service->saveChanges($count, [$weighed->id => ['jar' => '２', 'reserve' => '0.5']], [], 'WEB: phpunit');
        $weighed->refresh();
        $this->assertSame(2.0, $weighed->counted_quantity_jar);
        $this->assertSame(0.5, $weighed->counted_quantity_reserve);
        $this->assertSame(2.5, $weighed->counted_quantity);
        $this->assertSame(-0.5, $weighed->difference_quantity);
        $this->assertSame(-25.0, $weighed->difference_amount);
        $this->assertSame('WEB: phpunit', $weighed->counted_by_name);

        $summary = $service->weighedSummary($count);
        $this->assertSame(2, $summary['detail_count']);
        $this->assertSame(1, $summary['counted_count']);
        $this->assertSame(1, $summary['uncounted_count']);
        $this->assertSame(-0.5, $summary['difference_quantity']);
        $this->assertSame(-25.0, $summary['difference_amount']);
        $this->assertSame(3.0, $summary['sales_quantity']);
        $this->assertSame(2.0, $summary['loss_quantity']);
        $this->assertSame(100.0, $summary['loss_amount']);
        // ロス申請後差異金額 = 差異金額 + ロス金額
        $this->assertSame(75.0, $summary['difference_amount_after_loss']);

        // 片方だけ送ると、もう片方は変えない。QT だけでも実棚数になる。
        $service->saveChanges($count, [$weighed->id => ['jar' => null]], [], 'WEB: phpunit');
        $weighed->refresh();
        $this->assertNull($weighed->counted_quantity_jar);
        $this->assertSame(0.5, $weighed->counted_quantity);

        // 両方とも空にすると未入力に戻る。
        $service->saveChanges($count, [$weighed->id => ['reserve' => '']], [], 'WEB: phpunit');
        $weighed->refresh();
        $this->assertNull($weighed->counted_quantity);
        $this->assertNull($weighed->difference_amount);
        $this->assertNull($weighed->counted_by_name);

        // 未入力0は、カメ 0 として登録する。
        $this->assertSame(2, $service->fillUncountedWithZero($count, 'WEB: phpunit'));
        $other->refresh();
        $this->assertSame(0.0, $other->counted_quantity_jar);
        $this->assertNull($other->counted_quantity_reserve);
        $this->assertSame(0.0, $other->counted_quantity);
        $this->assertSame(-7.0, $other->difference_quantity);

        // 在庫管理なしの商品は量り売りに追加できない。
        $this->expectException(ValidationException::class);
        $service->addItemByCode($count, (string) $fixture['unmanaged_item_code']);
    }

    public function test_weighed_reports_are_generated_for_a_count(): void
    {
        $fixture = $this->fixture();
        $this->useWeighedItems($fixture);
        $service = $this->service($fixture);
        $count = $service->create($this->weighedData($fixture));
        $weighed = $count->items()->where('item_id', $fixture['other_managed_item_id'])->firstOrFail();
        $service->saveChanges($count, [$weighed->id => ['jar' => 2, 'reserve' => 2]], [], 'WEB: phpunit');

        $this->assertStringStartsWith('%PDF', (new SundryInventoryInstructionSheetPdfService)->generate($count));

        $workbook = (new WeighedInventoryDifferenceWorkbookService)->build($count);
        $this->assertSame(['差異表', '店舗計', '全明細'], $workbook->getSheetNames());

        // 差異表（店舗へ送る表）は、実棚を入力した明細だけ。
        $sheet = $workbook->getSheetByName('差異表');
        $this->assertSame('実棚数(カメ)', $sheet->getCell('F1')->getValue());
        $this->assertSame((string) $fixture['other_managed_item_code'], (string) $sheet->getCell('C2')->getValue());
        $this->assertEquals(3, $sheet->getCell('E2')->getValue());
        $this->assertEquals(2, $sheet->getCell('F2')->getValue());
        $this->assertEquals(2, $sheet->getCell('G2')->getValue());
        $this->assertEquals(1, $sheet->getCell('H2')->getValue());
        $this->assertEquals(50, $sheet->getCell('I2')->getValue());
        $this->assertEquals(50, $sheet->getCell('J2')->getValue());
        $this->assertEquals(3, $sheet->getCell('K2')->getValue());
        $this->assertSame('合計', $sheet->getCell('D3')->getValue());

        $summarySheet = $workbook->getSheetByName('店舗計');
        $this->assertSame('ロス申請後差異金額', $summarySheet->getCell('H10')->getValue());
        $this->assertEquals(1, $summarySheet->getCell('C11')->getValue());
        $this->assertEquals(3, $summarySheet->getCell('E11')->getValue());

        // 全明細は未入力も含む。
        $allSheet = $workbook->getSheetByName('全明細');
        $this->assertSame('入力済', $allSheet->getCell('O2')->getValue());
        $this->assertSame((string) $fixture['managed_item_code'], (string) $allSheet->getCell('B3')->getValue());
        $this->assertSame('未入力', $allSheet->getCell('O3')->getValue());
        $workbook->disconnectWorksheets();
    }

    /**
     * 量り売りの対象商品を、テスト用の商品（対象外テスト在庫管理あり → 雑貨テスト在庫管理あり の順）に差し替え、
     * 1つ目の商品に 6/15 の売上 3 を入れる。
     *
     * @param  array<string, mixed>  $fixture
     */
    private function useWeighedItems(array $fixture): void
    {
        config(['wms_sundry_inventory.weighed_item_codes' => [
            $fixture['other_managed_item_code'],
            $fixture['managed_item_code'],
        ]]);

        $db = DB::connection('sakemaru');
        $now = now();

        $db->table('item_prices')->insert([
            'client_id' => $fixture['client_id'],
            'creator_id' => 1,
            'item_id' => $fixture['other_managed_item_id'],
            'start_date' => '2026-01-01',
            'producer_unit_price' => 0,
            'producer_case_price' => 0,
            'producer_crate_price' => 0,
            'cost_unit_price' => 50,
            'type' => 'EXEMPT',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $tradeId = (int) $db->table('trades')->insertGetId([
            'client_id' => $fixture['client_id'],
            'creator_id' => 1,
            'last_updater_id' => 1,
            'trade_category' => 'EARNING',
            'uuid' => (string) Str::uuid(),
            'serial_id' => random_int(900000000, 999999999),
            'entry_lot_number' => 0,
            'subtotal' => 0,
            'total' => 0,
            'process_date' => '2026-06-15',
            'is_active' => true,
            'is_latest' => true,
            'trade_item_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->table('earnings')->insert([
            'trade_id' => $tradeId,
            'client_id' => $fixture['client_id'],
            'buyer_id' => 0,
            'warehouse_id' => $fixture['warehouse_id'],
            'delivered_date' => '2026-06-15',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->table('trade_items')->insert([
            'client_id' => $fixture['client_id'],
            'trade_id' => $tradeId,
            'item_id' => $fixture['other_managed_item_id'],
            'item_name' => '対象外テスト在庫管理あり',
            'stock_allocation_id' => 0,
            'order_quantity_type' => 'PIECE',
            'quantity' => 3,
            'quantity_type' => 'PIECE',
            'capacity_case' => 1,
            'capacity_carton' => 1,
            'price_category' => 'OTHER',
            'amount' => 300,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function weighedData(array $fixture): array
    {
        return [
            'kind' => WmsSundryInventoryCount::KIND_WEIGHED,
            'warehouse_id' => $fixture['warehouse_id'],
            'count_date' => '2026-06-30',
            'sales_from_date' => '2026-06-01',
        ];
    }

    /**
     * 売上500円 × 分類原価率80%。原価率カラムが無い環境では売上原価を計上しない。
     */
    private function expectedSalesCost(): float
    {
        return Schema::connection('sakemaru')->hasColumn('item_categories', 'cost_rate') ? 400.0 : 0.0;
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function createData(array $fixture): array
    {
        return [
            'warehouse_id' => $fixture['warehouse_id'],
            'count_date' => '2026-06-30',
            'category_ids' => [$fixture['target_category_id']],
            'amount_category_ids' => [$fixture['major_category_id']],
        ];
    }

    /**
     * 在庫管理ありの理論数と、前残の元データ（旧システム残高・在庫金額報告書）だけ固定値にしたサービス。
     * （受払残は既存棚卸しと共通の実装、報告書は基幹側のテーブルのため、ここでは検証しない）
     *
     * @param  array<string, mixed>  $fixture
     * @param  array<string, mixed>|null  $reportOpening  対象大分類の報告書由来の残高（null は報告書なし）
     * @param  array<string, array{date: string, amount: float}>  $legacyOpenings  中分類コード => 旧システム残高
     */
    private function service(array $fixture, ?array $reportOpening = null, array $legacyOpenings = []): SundryInventoryCountService
    {
        $balances = [
            $fixture['managed_item_id'] => 7.0,
            $fixture['other_managed_item_id'] => 3.0,
        ];

        return new SundryInventoryCountService(new class($balances, $fixture['major_category_id'], $reportOpening, $legacyOpenings) extends SundryInventoryTheoryCalculator
        {
            /**
             * @param  array<int, float>  $balances
             * @param  array<string, mixed>|null  $reportOpening
             * @param  array<string, array{date: string, amount: float}>  $legacyOpenings
             */
            public function __construct(
                private array $balances,
                private int $reportCategoryId,
                private ?array $reportOpening,
                private array $legacyOpenings,
            ) {}

            public function managedBalances(int $clientId, int $warehouseId, string $endDate): array
            {
                return $this->balances;
            }

            public function unmanagedLegacyOpening(string $warehouseCode, string $category2Code, string $onOrBeforeDate): ?array
            {
                return $this->legacyOpenings[$category2Code] ?? null;
            }

            public function unmanagedOpeningFromStockReport(int $clientId, int $warehouseId, int $category1Id, string $onOrBeforeDate): ?array
            {
                return $category1Id === $this->reportCategoryId ? $this->reportOpening : null;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $db = DB::connection('sakemaru');
        $clientId = random_int(880000, 889999);
        $now = now();

        $warehouseId = (int) $db->table('warehouses')->insertGetId([
            'client_id' => $clientId,
            'code' => random_int(900000, 999999),
            'name' => '雑貨棚卸テスト倉庫',
            'out_of_stock_option' => 'IGNORE_STOCK',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $category = fn (int $code, string $name, int $depth, array $extra = []): int => (int) $db->table('item_categories')->insertGetId([
            'client_id' => $clientId,
            'code' => $code,
            'name' => $name,
            'depth' => $depth,
            'created_at' => $now,
            'updated_at' => $now,
        ] + $extra);

        $hasCostRate = Schema::connection('sakemaru')->hasColumn('item_categories', 'cost_rate');
        $majorCategoryId = $category(1004, '【雑貨】', 1);
        $otherMajorCategoryId = $category(1001, '【酒  類】', 1);
        $targetCategoryId = $category(2041, '  （雑貨）', 2);
        $otherCategoryId = $category(2012, '  （焼酎）', 2);
        $smallCategoryId = $category(3410, '    雑貨', 3, $hasCostRate ? ['cost_rate' => 80] : []);

        $item = function (int $category1Id, int $category2Id, bool $managed, string $name) use ($db, $clientId, $smallCategoryId, $now): array {
            $code = random_int(800000000, 899999999);
            $id = (int) $db->table('items')->insertGetId([
                'name_main' => $name,
                'code' => $code,
                'type' => 'NOT_ALCOHOL',
                'manufacturer_id' => 0,
                'volume' => 1,
                'capacity_case' => 1,
                'creator_id' => 1,
                'packaging' => '1',
                'nickname' => $name,
                'client_id' => $clientId,
                'item_set_id' => 0,
                'item_category1_id' => $category1Id,
                'item_category2_id' => $category2Id,
                'item_category3_id' => $smallCategoryId,
                'container_type_id' => 0,
                'manufacture_type_id' => 0,
                'storage_type_id' => 0,
                'measurement_unit_weight' => 0,
                'measurement_case_weight' => 0,
                'order_rank' => 'ORDER_MANUAL',
                'is_managed_stock' => $managed,
                'last_updater_id' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [$id, $code];
        };

        [$managedItemId, $managedItemCode] = $item($majorCategoryId, $targetCategoryId, true, '雑貨テスト在庫管理あり');
        [$unmanagedItemId, $unmanagedItemCode] = $item($majorCategoryId, $targetCategoryId, false, '部システムテスト（雑貨）');
        [$otherManagedItemId, $otherManagedItemCode] = $item($otherMajorCategoryId, $otherCategoryId, true, '対象外テスト在庫管理あり');
        [, $otherUnmanagedItemCode] = $item($otherMajorCategoryId, $otherCategoryId, false, '対象外テスト在庫管理なし');

        $db->table('item_prices')->insert([
            'client_id' => $clientId,
            'creator_id' => 1,
            'item_id' => $managedItemId,
            'start_date' => '2026-01-01',
            'producer_unit_price' => 0,
            'producer_case_price' => 0,
            'producer_crate_price' => 0,
            'cost_unit_price' => 100,
            'type' => 'EXEMPT',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $db->table('real_stocks')->insert([
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'stock_allocation_id' => 0,
            'item_id' => $managedItemId,
            'current_quantity' => 7,
            'order_rank' => '',
            'reserved_quantity' => 0,
            'picking_quantity' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $trade = fn (string $category, string $processDate): int => (int) $db->table('trades')->insertGetId([
            'client_id' => $clientId,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'trade_category' => $category,
            'uuid' => (string) Str::uuid(),
            'serial_id' => random_int(900000000, 999999999),
            'entry_lot_number' => 0,
            'subtotal' => 0,
            'total' => 0,
            'process_date' => $processDate,
            'is_active' => true,
            'is_latest' => true,
            'trade_item_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $tradeItem = fn (int $tradeId, float $amount) => $db->table('trade_items')->insert([
            'client_id' => $clientId,
            'trade_id' => $tradeId,
            'item_id' => $unmanagedItemId,
            'item_name' => '部システムテスト（雑貨）',
            'stock_allocation_id' => 0,
            'order_quantity_type' => 'PIECE',
            'quantity' => 1,
            'quantity_type' => 'PIECE',
            'capacity_case' => 1,
            'capacity_carton' => 1,
            'price_category' => 'OTHER',
            'amount' => $amount,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 在庫管理なし商品の仕入 1,000円（6/10）と売上 500円（6/15。原価率80% → 売上原価 400円）
        $purchaseTradeId = $trade('PURCHASE', '2026-06-10');
        $db->table('purchases')->insert([
            'trade_id' => $purchaseTradeId,
            'client_id' => $clientId,
            'supplier_id' => 0,
            'warehouse_id' => $warehouseId,
            'delivered_date' => '2026-06-10',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $tradeItem($purchaseTradeId, 1000);

        $earningTradeId = $trade('EARNING', '2026-06-15');
        $db->table('earnings')->insert([
            'trade_id' => $earningTradeId,
            'client_id' => $clientId,
            'buyer_id' => 0,
            'warehouse_id' => $warehouseId,
            'delivered_date' => '2026-06-15',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $tradeItem($earningTradeId, 500);

        return [
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'major_category_id' => $majorCategoryId,
            'target_category_id' => $targetCategoryId,
            'other_category_id' => $otherCategoryId,
            'managed_item_id' => $managedItemId,
            'managed_item_code' => $managedItemCode,
            'unmanaged_item_id' => $unmanagedItemId,
            'unmanaged_item_code' => $unmanagedItemCode,
            'other_managed_item_id' => $otherManagedItemId,
            'other_managed_item_code' => $otherManagedItemCode,
            'other_unmanaged_item_code' => $otherUnmanagedItemCode,
        ];
    }
}
