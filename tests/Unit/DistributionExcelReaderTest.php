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

    private function file(array $rows, ?array $second = null, array $merges = []): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows, null, 'A1', true);
        foreach ($merges as $range) {
            $book->getActiveSheet()->mergeCells($range);
        }
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
        foreach ([1 => '本店', 2 => '二の宮店', 3 => '坂井店', 4 => 'サンドーム前店', 7 => '光陽店', 8 => 'プラザ店', 9 => 'ヴィオ店', 10 => '敦賀店', 11 => '越前店', 21 => '江守店', 22 => '小浜店', 91 => 'センター'] as $code => $name) {
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

    public function test_merged_store_quantity_skips_the_whole_row_once_before_quantity_and_duplicate_checks(): void
    {
        $result = $this->parse($this->file([
            ['単品CD', '本店', '二宮', 'ＳＤ'],
            [100001, -1, '各店発注', null],
            [100001, 7, 8, 9],
        ], null, ['C2:D2']));
        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['skipped']);
        self::assertSame(['row' => 2, 'code' => '100001', 'name' => '商品A',
            'reason_code' => 'merged_store_quantity', 'reason' => '店舗数量が結合されています'], $result['skipped'][0]);
        self::assertCount(1, $result['rows']);
        self::assertSame(3, $result['rows'][0]['excel_row']);
        self::assertSame(['01' => 7, '02' => 8, '04' => 9], $result['rows'][0]['allocations']);
        self::assertSame(24, $result['excel_total']);
    }

    public function test_vertical_store_merge_skips_every_affected_product_row_in_case_mode(): void
    {
        $result = $this->parse($this->file([
            ['単品CD', '本店', '二宮'], [100001, 2, 4], [100001, null, 6], [100001, 1, 2],
        ], null, ['B2:B3']), ['quantity_unit' => 'case']);
        self::assertSame([], $result['errors']);
        self::assertSame([2, 3], array_column($result['skipped'], 'row'));
        self::assertCount(1, $result['rows']);
        self::assertSame(4, $result['rows'][0]['excel_row']);
        self::assertSame(36, $result['excel_total']);
    }

    public function test_reference_merges_do_not_skip_valid_store_quantities_and_all_merged_rows_import_nothing(): void
    {
        $reference = $this->parse($this->file([
            ['単品CD', '本店', '商品名', '備考'], [100001, 5, '説明', null],
        ], null, ['C2:D2']));
        self::assertSame([], $reference['errors']);
        self::assertSame([], $reference['skipped']);
        self::assertSame(5, $reference['excel_total']);
        $merged = $this->parse($this->file([
            ['単品CD', '本店', '二宮'], [100001, '売切り終了', null],
        ], null, ['B2:C2']));
        self::assertSame([], $merged['errors']);
        self::assertSame([], $merged['rows']);
        self::assertCount(1, $merged['skipped']);
        self::assertSame(0, $merged['excel_total']);
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

    public function test_purchase_header_is_a_reference_even_when_only_some_stores_are_present(): void
    {
        $result = $this->parse($this->file([
            ['単品CD', '仕入', '本店'], [100001, '本部', 5],
        ]));
        self::assertSame([], $result['errors']);
        self::assertSame('ignored', $result['columns'][0]['status']);
        self::assertSame(['01' => 5], $result['rows'][0]['allocations']);
    }

    public function test_all_eleven_stores_allow_unrecognized_reference_columns_without_mapping(): void
    {
        $headers = ['単品CD', '仕入', '担当者メモ', null, '本店', '二宮', '坂井', 'SD前', '光陽', 'プラ', 'ビオ', '敦賀', '越前', '江守', '小浜', '独自集計'];
        $values = [100001, '仕入元', '参考', 9999, ...array_fill(0, 11, 1), '=SUM(E2:O2)'];
        $result = $this->parse($this->file([$headers, $values]));
        self::assertSame([], $result['errors']);
        self::assertSame(11, $result['excel_total']);
        self::assertCount(11, $result['rows'][0]['allocations']);
        $columns = array_column($result['columns'], null, 'column');
        foreach (['B', 'C', 'D', 'P'] as $letter) {
            self::assertSame('ignored', $columns[$letter]['status']);
        }
        $missingStore = $headers;
        $missingStore[14] = '不明店';
        $partial = $this->parse($this->file([$missingStore, $values]));
        self::assertStringContainsString('O列', implode('', $partial['errors']));
        self::assertStringContainsString('D列', implode('', $partial['errors']));
        $mapped = $this->parse($this->file([$headers, $values]), ['mapping' => ['D' => 999]]);
        self::assertStringContainsString('D列', implode('', $mapped['errors']));
        $duplicate = $this->parse($this->file([[...$headers, '本店'], [...$values, 2]]));
        self::assertStringContainsString('同じ店舗', implode('', $duplicate['errors']));
        $invalidQuantity = $values;
        $invalidQuantity[4] = -1;
        $invalid = $this->parse($this->file([$headers, $invalidQuantity]));
        self::assertStringContainsString('E2', implode('', $invalid['errors']));
    }

    public function test_all_eleven_store_codes_also_ignore_extra_reference_columns(): void
    {
        $codes = [1, 2, 3, 4, 7, 8, 9, 10, 11, 21, 22];
        $result = $this->parse($this->file([
            ['単品CD', '独自欄', ...$codes], [100001, 9999, ...array_fill(0, 11, 2)],
        ]), ['store_mode' => 'code']);
        self::assertSame([], $result['errors']);
        self::assertSame('ignored', $result['columns'][0]['status']);
        self::assertSame(22, $result['excel_total']);
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
