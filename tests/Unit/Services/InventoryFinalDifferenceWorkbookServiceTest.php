<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Services\InventoryCount\InventoryFinalDifferenceWorkbookService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class InventoryFinalDifferenceWorkbookServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    protected function setUp(): void
    {
        parent::setUp();
        if (! DB::connection('sakemaru')->table('clients')->where('id', 1)->exists()) {
            DB::connection('sakemaru')->table('clients')->insert(['id' => 1, 'code' => 1, 'name' => 'Final report test']);
        }
    }

    public function test_exports_frozen_source_quantities_and_signed_amounts_across_all_queues(): void
    {
        $count = $this->createCount();
        $this->queue($count, [$this->row(2, '000002', -7.01, [[7, 0]])]);
        $this->queue($count, [$this->row(1, '000001', 12.34, [[8, 5], [12, 10]])]);
        $original = DB::connection('sakemaru')->table('inventory_adjustment_queue')->where('wms_inventory_count_id', $count->id)->get()->toArray();
        $book = $this->workbook($count);
        try {
            $sheet = $book->getActiveSheet();
            $this->assertSame('最終差異報告書', $sheet->getTitle());
            $this->assertSame(['商品CD', '商品名', '理論在庫', '実棚数', '終了差異金額'], $sheet->rangeToArray('A5:E5')[0]);
            $this->assertSame('000001', $sheet->getCell('A6')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A6')->getDataType());
            $this->assertSame('=商品名', $sheet->getCell('B6')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B6')->getDataType());
            $this->assertSame(20, $sheet->getCell('C6')->getValue());
            $this->assertSame(15, $sheet->getCell('D6')->getValue());
            $this->assertEquals(12.34, $sheet->getCell('E6')->getValue());
            $this->assertSame(0, $sheet->getCell('D7')->getValue());
            $this->assertEquals(-7.01, $sheet->getCell('E7')->getValue());
            $this->assertSame('合計', $sheet->getCell('A8')->getValue());
            $this->assertEquals(27, $sheet->getCell('C8')->getCalculatedValue());
            $this->assertEquals(15, $sheet->getCell('D8')->getCalculatedValue());
            $this->assertEqualsWithDelta(5.33, $sheet->getCell('E8')->getCalculatedValue(), 0.000001);
            $this->assertSame('=SUM(E6:E7)', $sheet->getCell('E8')->getValue());
            $this->assertSame('A1:E8', $sheet->getPageSetup()->getPrintArea());
            $this->assertSame([1, 5], $sheet->getPageSetup()->getRowsToRepeatAtTop());
            $this->assertSame('C6', $sheet->getFreezePane());
            $this->assertSame('A5:E7', $sheet->getAutoFilter()->getRange());
            $this->assertStringContainsString('2回目確定', $sheet->getCell('A3')->getValue());
            $this->assertEquals($original, DB::connection('sakemaru')->table('inventory_adjustment_queue')->where('wms_inventory_count_id', $count->id)->get()->toArray());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_zero_difference_finalization_has_a_zero_total_without_queues(): void
    {
        $book = $this->workbook($this->createCount());
        try {
            $sheet = $book->getActiveSheet();
            $this->assertSame('合計', $sheet->getCell('A6')->getValue());
            foreach (['C6', 'D6', 'E6'] as $cell) {
                $this->assertEquals(0, $sheet->getCell($cell)->getCalculatedValue());
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_unfinalized_count_is_rejected(): void
    {
        $count = $this->createCount();
        $count->update(['status' => WmsInventoryCount::STATUS_CHECKED]);
        $this->expectException(ValidationException::class);
        (new InventoryFinalDifferenceWorkbookService)->generate($count);
    }

    public function test_missing_queue_does_not_silently_reduce_totals(): void
    {
        $count = $this->createCount();
        $this->queue($count, [$this->row(1, '1', 1, [[1, 2]])]);
        $count->update(['inventory_adjustment_queue_count' => 2]);
        $this->expectException(ValidationException::class);
        (new InventoryFinalDifferenceWorkbookService)->generate($count);
    }

    public function test_missing_source_does_not_use_adjustment_date_balances(): void
    {
        $count = $this->createCount();
        $row = $this->row(1, '1', 1, [[1, 2]]);
        unset($row['source_count_items']);
        $this->queue($count, [$row]);
        $this->expectException(ValidationException::class);
        (new InventoryFinalDifferenceWorkbookService)->generate($count);
    }

    public function test_duplicate_products_across_queues_are_rejected(): void
    {
        $count = $this->createCount();
        $row = $this->row(1, '1', 1, [[1, 2]]);
        $this->queue($count, [$row]);
        $this->queue($count, [$row]);
        $this->expectException(ValidationException::class);
        (new InventoryFinalDifferenceWorkbookService)->generate($count);
    }

    public function test_inconsistent_source_difference_is_rejected(): void
    {
        $count = $this->createCount();
        $row = $this->row(1, '1', 1, [[1, 2]]);
        $row['inventory_adjustment_quantity'] = 20;
        $this->queue($count, [$row]);
        $this->expectException(ValidationException::class);
        (new InventoryFinalDifferenceWorkbookService)->generate($count);
    }

    private function createCount(): WmsInventoryCount
    {
        return WmsInventoryCount::create([
            'count_no' => 'FINAL-EXPORT-'.Str::random(8), 'client_id' => 1, 'warehouse_id' => 22,
            'warehouse_code' => '22', 'warehouse_name' => 'Test', 'count_date' => '2026-08-20',
            'status' => WmsInventoryCount::STATUS_CONFIRMED, 'confirmed_at' => now(),
            'inventory_adjustment_date' => '2026-09-01', 'inventory_adjustment_count_round' => 2,
            'inventory_adjustment_queue_ids' => [], 'inventory_adjustment_queue_count' => 0,
        ]);
    }

    private function row(int $id, string $code, float $amount, array $quantities): array
    {
        $sources = array_map(fn ($q) => ['stock_quantity_before' => $q[0], 'stock_quantity_after' => $q[1]], $quantities);

        return ['item_id' => $id, 'item_code' => $code, 'item_name' => '=商品名', 'amount' => $amount,
            'stock_quantity_before' => 999, 'stock_quantity_after' => 998, 'count_round' => 2,
            'inventory_adjustment_quantity' => array_sum(array_column($sources, 'stock_quantity_after')) - array_sum(array_column($sources, 'stock_quantity_before')),
            'source_count_items' => $sources];
    }

    private function queue(WmsInventoryCount $count, array $rows): void
    {
        $id = DB::connection('sakemaru')->table('inventory_adjustment_queue')->insertGetId([
            'client_id' => 1, 'warehouse_code' => '22', 'wms_inventory_count_id' => $count->id,
            'request_id' => (string) Str::uuid(), 'process_date' => '2026-09-01', 'adjustment_date' => '2026-09-01',
            'items' => json_encode($rows), 'status' => 'BEFORE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids = [...$count->inventory_adjustment_queue_ids, $id];
        $count->update(['inventory_adjustment_queue_ids' => $ids, 'inventory_adjustment_queue_count' => count($ids)]);
    }

    private function workbook(WmsInventoryCount $count): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'final-diff-test-');
        try {
            file_put_contents($path, (new InventoryFinalDifferenceWorkbookService)->generate($count));

            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }
}
