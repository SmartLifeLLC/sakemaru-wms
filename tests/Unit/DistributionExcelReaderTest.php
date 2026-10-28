<?php

namespace Tests\Unit;

use App\Services\Distribution\DistributionExcelReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class DistributionExcelReaderTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    private function file(array $rows, ?array $second = null): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        if ($second) {
            $book->createSheet()->fromArray($second, null, 'A1', true);
        }
        $path = tempnam(sys_get_temp_dir(), 'distribution-excel-');
        $this->files[] = $path;
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private function parse(string $path, array $options = []): array
    {
        $destinations = [];
        foreach ([1 => '本店', 2 => '二の宮店', 4 => 'サンドーム前店', 8 => 'プラザ店', 9 => 'ヴィオ店', 91 => 'センター'] as $code => $name) {
            $destinations[] = ['id' => $code, 'code' => $code, 'name' => $name, 'allowed' => $code !== 91];
        }

        return (new DistributionExcelReader)->parse($path, [100001 => ['id' => 42, 'code' => 100001,
            'name' => '商品A', 'capacity_case' => 12, 'is_active' => true]], $destinations,
            array_replace(['quantity_unit' => 'piece', 'store_mode' => 'name', 'mapping' => []], $options));
    }

    public function test_first_sheet_header_aliases_blank_and_invalid_code_rows(): void
    {
        $result = $this->parse($this->file([
            ['数量リスト'], [null], ['自社ＣＤ', '二宮', 'ＳＤ', 'プラ', 'ビオ', '総合計'],
            [100001, '１２', null, 3, 0, 999], ['注釈', 100, 100], [null, 20],
        ], [['単品CD', '本店'], [100001, 999]]));
        self::assertSame(3, $result['header_row']);
        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['rows']);
        self::assertSame(['02' => 12, '04' => 0, '08' => 3, '09' => 0], $result['rows'][0]['allocations']);
        self::assertSame(15, $result['excel_total']);
        self::assertCount(2, $result['skipped']);
    }

    public function test_no_store_columns_stops_instead_of_selecting_second_sheet(): void
    {
        $result = $this->parse($this->file([['単品CD', '商品名'], [100001, '商品A']], [['単品CD', '本店'], [100001, 10]]));
        self::assertStringContainsString('先頭', implode('', $result['errors']));
        self::assertSame(0, $result['excel_total']);
    }

    public function test_case_uses_master_capacity_and_warns_about_excel_difference(): void
    {
        $result = $this->parse($this->file([['単品CD', '入数', '本店'], [100001, 10, 2]]), ['quantity_unit' => 'case']);
        self::assertSame(24, $result['excel_total']);
        self::assertStringContainsString('マスタ入数12', implode('', $result['warnings']));
    }

    public function test_preview_identifies_product_column_and_ignores_headerless_notes_but_keeps_quantities_unresolved(): void
    {
        $result = $this->parse($this->file([
            ['自社CD', '備考', '計', '本店', null, null],
            [100001, '参考', 2, 2, '80単位', 3],
        ]));
        self::assertSame('A', $result['code_column']);
        self::assertSame('自社CD', $result['code_header']);
        $columns = array_column($result['columns'], null, 'column');
        self::assertSame('ignored', $columns['B']['status']);
        self::assertSame('ignored', $columns['C']['status']);
        self::assertSame('matched', $columns['D']['status']);
        self::assertSame('ignored', $columns['E']['status']);
        self::assertSame('unresolved', $columns['F']['status']);
        self::assertStringContainsString('F列', implode('', $result['errors']));
        self::assertSame(['01' => 2], $result['rows'][0]['allocations']);
        $mapped = $this->parse($this->file([['単品CD', '本店', null], [100001, 2, '80単位']]), ['mapping' => ['C' => 2]]);
        self::assertSame('matched', $mapped['columns'][1]['status']);
        self::assertNotEmpty($mapped['errors'], 'Explicitly mapped text must still fail quantity validation.');
    }

    public function test_code_mode_source_column_duplicates_and_unknown_columns(): void
    {
        $result = $this->parse($this->file([['単品CD', '０１', 1, 91, '不明店'], [100001, 1, 2, 3, 4]]), ['store_mode' => 'code']);
        self::assertStringContainsString('同じ店舗', implode('', $result['errors']));
        self::assertStringContainsString('対象外の倉庫に数量', implode('', $result['errors']));
        self::assertStringContainsString('対応を確認', implode('', $result['errors']));
        $resolved = $this->parse($this->file([['単品CD', '01', 91, 'メモ'], [100001, 0, null, '参考']]), ['store_mode' => 'code', 'mapping' => ['D' => 'ignore']]);
        self::assertSame([], $resolved['errors']);
        self::assertCount(1, $resolved['rows']);
    }

    public function test_headerless_total_case_conversion_is_reference_but_other_formulas_need_review(): void
    {
        $result = $this->parse($this->file([
            ['単品CD', '本店', '計', null, null],
            [100001, 12, '=SUM(B2:B2)', '=C2/12', '=B2*2'],
        ]));
        $columns = array_column($result['columns'], null, 'column');
        self::assertSame('ignored', $columns['D']['status']);
        self::assertSame('unresolved', $columns['E']['status']);
        self::assertStringContainsString('E列', implode('', $result['errors']));
        self::assertSame(12, $result['excel_total']);
    }

    public function test_duplicate_product_and_invalid_quantities_are_not_silently_coerced(): void
    {
        $result = $this->parse($this->file([['単品CD', '本店', '二宮'], [100001, -1, 1.5], [100001, '12個', 100000000]]));
        self::assertStringContainsString('重複', implode('', $result['errors']));
        self::assertGreaterThanOrEqual(5, count($result['errors']));
        foreach (['', '０', '12.0', '1,200'] as $value) {
            self::assertNotNull(DistributionExcelReader::quantity($value));
        }
        foreach (['-1', '1.5', '12個', '1,20', '1e3'] as $value) {
            self::assertNull(DistributionExcelReader::quantity($value));
        }
    }

    public function test_intermediate_headers_must_keep_store_layout_and_whitespace_cannot_join_digits(): void
    {
        $result = $this->parse($this->file([['単品CD', '本店', '二宮'], [100001, 1, 2], ['自社CD', '二宮', '本店']]));
        self::assertStringContainsString('列構成', implode('', $result['errors']));
        self::assertNull(DistributionExcelReader::quantity('1 2'));
        self::assertSame(12, DistributionExcelReader::quantity(' １２ '));
        $codes = $this->parse($this->file([['単品CD', '本店'], ['100 001', 12]]));
        self::assertCount(0, $codes['rows']);
    }

    public function test_hidden_rows_warn_and_first_hidden_sheet_stops(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['単品CD', '本店'], [100001, 12]]);
        $book->getActiveSheet()->getRowDimension(2)->setVisible(false);
        $path = tempnam(sys_get_temp_dir(), 'distribution-hidden-');
        $this->files[] = $path;
        (new Xlsx($book))->save($path);
        $result = $this->parse($path);
        self::assertSame(12, $result['excel_total']);
        self::assertStringContainsString('非表示の商品行1件', implode('', $result['warnings']));
        $book->createSheet();
        $book->getSheet(0)->setSheetState('hidden');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('先頭シートを表示');
        $this->parse($path);
    }
}
