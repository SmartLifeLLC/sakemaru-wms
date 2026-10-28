<?php

namespace Tests\Unit\Services;

use App\Services\Statistics\WarehouseOrderReviewExport;
use App\Services\Statistics\WarehouseOrderReviewService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class WarehouseOrderReviewExportTest extends TestCase
{
    public function test_export_preserves_quantities_and_literal_text_with_seven_arrival_dates(): void
    {
        $rows = [];
        for ($day = 1; $day <= 8; $day++) {
            $rows[] = [
                '__item_id' => 10, '日付' => '2026-10-29', '発注先CD' => '0012', '発注先名' => '=supplier',
                '仕入先CD' => '123', '仕入先名' => '仕入先', '単品CD' => '00101', 'JANCD' => '0012345678901',
                '商品名' => '商品', '入数' => 24, '発注点' => 0, '在庫CS' => -1.5, '在庫バラ' => -36,
                '受注CS' => 2.5, '受注バラ' => 60, '移動CS' => 3, '移動バラ' => 72,
                '1週' => 123, '2週' => 456, '3週' => 789, '前月' => 1000,
                '入荷予定日' => sprintf('2026-11-%02d', $day), '入荷予定CS' => $day / 2,
                '備考' => "=memo\n二行目", '区分' => '削除', '1P' => 99,
            ];
        }
        // Two underlying arrivals on one day must be summed without duplicating the item.
        $rows[] = array_replace($rows[0], ['入荷予定CS' => 2]);
        $rows[] = ['__item_id' => 20, '単品CD' => '00202', '備考' => null, '在庫バラ' => 0];
        $book = (new WarehouseOrderReviewExport)->spreadsheet($rows, 91);
        $tmp = tempnam(sys_get_temp_dir(), 'order_review_test_');
        try {
            (new Xlsx($book))->save($tmp);
            $saved = IOFactory::load($tmp);
            $sheet = $saved->getActiveSheet();
            $this->assertSame('AC', $sheet->getHighestColumn());
            $this->assertSame(4, $sheet->getHighestRow());
            $this->assertSame('発注先CD', $sheet->getCell('B1')->getValue());
            $this->assertSame('仕入先CD', $sheet->getCell('D1')->getValue());
            $this->assertSame('備考', $sheet->getCell('AC1')->getValue());
            $this->assertSame('26/11/07', $sheet->getCell('AB2')->getValue());
            $this->assertSame(2.5, $sheet->getCell('V3')->getValue());
            $this->assertSame(3.5, $sheet->getCell('AB3')->getValue());
            $this->assertSame(-1.5, $sheet->getCell('L3')->getValue());
            $this->assertEquals(-36, $sheet->getCell('M3')->getValue());
            $this->assertEquals(0, $sheet->getCell('M4')->getValue());
            $this->assertEquals(123, $sheet->getCell('R3')->getValue());
            $this->assertEquals(1000, $sheet->getCell('U3')->getValue());
            $this->assertSame('0012345678901', $sheet->getCell('F3')->getValue());
            $this->assertSame('00101', $sheet->getCell('G3')->getValue());
            $this->assertSame('s', $sheet->getCell('C3')->getDataType());
            $this->assertSame("=memo\n二行目", $sheet->getCell('AC3')->getValue());
            $this->assertSame('s', $sheet->getCell('AC3')->getDataType());
            $this->assertSame('A2:AC4', $sheet->getAutoFilter()->getRange());
            $this->assertArrayHasKey('V1:AB1', $sheet->getMergeCells());
            $saved->disconnectWorksheets();
        } finally {
            $book->disconnectWorksheets();
            unlink($tmp);
        }
    }

    public function test_empty_result_still_has_revised_headers(): void
    {
        $book = (new WarehouseOrderReviewExport)->spreadsheet([], 92);
        $sheet = $book->getActiveSheet();
        $this->assertSame('92ケース', $sheet->getCell('L2')->getValue());
        $this->assertSame('備考', $sheet->getCell('AC1')->getValue());
        $this->assertSame(2, $sheet->getHighestRow());
        $book->disconnectWorksheets();
    }

    public function test_sort_keeps_contractor_supplier_priority_then_notes_then_item(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 3).'/resources/sql/warehouse-order-review.sql');
        $order = substr($sql, strripos($sql, 'ORDER BY'));
        $fixture = <<<'SQL'
WITH fixture AS (
    SELECT 10 AS code, 1 AS contractor, 1 AS supplier, 'B' AS note
    UNION ALL SELECT 20, 1, 1, NULL
    UNION ALL SELECT 30, 1, 1, 'A'
    UNION ALL SELECT 40, 1, 1, ''
    UNION ALL SELECT 50, 1, 1, 'A'
    UNION ALL SELECT 60, 1, 2, 'A'
    UNION ALL SELECT 70, 2, 1, 'A'
    UNION ALL SELECT 80, 1, 1, '   '
)
SELECT i.code FROM fixture i
JOIN fixture ic ON ic.code = i.code
JOIN (SELECT 1 AS code UNION ALL SELECT 2) c ON c.code = i.contractor
JOIN (SELECT 1 AS code UNION ALL SELECT 2) sub_partner ON sub_partner.code = i.supplier
CROSS JOIN (SELECT NULL AS code) item_supplier_partner
CROSS JOIN (SELECT NULL AS `入荷予定日`) arr
SQL;
        $pdo = new \PDO('sqlite::memory:');
        $this->assertSame([30, 50, 10, 20, 40, 80, 60, 70], $pdo->query($fixture.' '.$order)->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function test_invalid_tenant_is_rejected_before_querying(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WarehouseOrderReviewService)->rows(0, '2026-10-29', 91);
    }
}
