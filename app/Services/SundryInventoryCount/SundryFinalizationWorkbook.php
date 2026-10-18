<?php

namespace App\Services\SundryInventoryCount;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SundryFinalizationWorkbook
{
    public function build(object $record): Spreadsheet
    {
        $data = json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $book = new Spreadsheet;
        $summary = $book->getActiveSheet()->setTitle('確定差異');
        $summary->fromArray([
            ['棚卸しNo', $data['count_no']], ['棚卸日', $data['count_date']], ['適用日', $data['process_date']],
            ['実棚変更伝票ID', $record->inventory_adjustment_id], ['適用状況', $record->status === 'applied' ? '適用済み' : '未適用'],
            ['管理品差異金額', round(collect($data['lines'])->where('kind', 'managed')->sum('amount'), 2)],
            ['非管理品差異金額', round(collect($data['lines'])->where('kind', 'amount')->sum('amount'), 2)],
            ['棚卸差異金額合計', '=SUM(B6:B7)+B9'],
            ['部門の不明差異金額', round(collect($data['lines'])->where('kind', 'book')->sum('amount'), 2)],
        ], null, 'A1', true);
        $summary->getColumnDimension('A')->setWidth(25);
        $summary->getColumnDimension('B')->setWidth(35);
        $summary->getStyle('B6:B9')->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
        if (collect($data['lines'])->contains(fn ($line) => isset($line['book_reconciliation']))) {
            $summary->setCellValue('A10', '差異金額について');
            $summary->setCellValue('B10', '棚卸不明差異には承認済み帳簿差額を含みます。部門差額は商品数量を変更しません。');
            $summary->getStyle('B10')->getAlignment()->setWrapText(true);
            $summary->getRowDimension(10)->setRowHeight(60);
        }
        foreach (['managed' => '管理品数量', 'amount' => '非管理品金額'] as $kind => $title) {
            $sheet = $book->createSheet()->setTitle($title);
            $hasRounding = $kind === 'managed' && collect($data['lines'])->where('kind', $kind)
                ->contains(fn ($line) => ($line['rounding_adjustment'] ?? 0) != 0);
            $headers = ['中分類CD', '中分類名', '商品CD', '商品名', '棚卸日理論', '棚卸日実棚', '適用日理論', '適用日実棚相当', '数量差', '適用月原価', '棚卸差異金額'];
            if ($hasRounding) {
                $headers[] = '内数：端数配賦';
            }
            if ($kind === 'amount') {
                $headers = ['中分類CD', '中分類名', '棚卸日帳簿金額', '棚卸日実棚金額', '適用日帳簿金額', '適用日実棚金額相当', '棚卸差異金額'];
            }
            $sheet->fromArray($headers, null, 'A1');
            $row = 2;
            foreach (collect($data['lines'])->where('kind', $kind) as $line) {
                $values = $kind === 'managed'
                    ? [$line['category2_code'], $line['category2_name'], $line['item_code'], $line['item_name'], $line['count_theory'], $line['count_actual'],
                        $line['quantity_before'], $line['quantity_after'], $line['difference_quantity'], $line['unit_price'], $line['amount']]
                    : [$line['category2_code'], $line['category2_name'], $line['count_theory'], $line['count_actual'], $line['book_before'], $line['book_after'], $line['amount']];
                if ($hasRounding) {
                    $values[] = $line['rounding_adjustment'] ?? 0;
                }
                $sheet->fromArray($values, null, "A{$row}", true);
                foreach ($kind === 'managed' ? ['A', 'B', 'C', 'D'] : ['A', 'B'] as $index => $col) {
                    $sheet->setCellValueExplicit($col.$row, (string) $values[$index], DataType::TYPE_STRING);
                }
                $row++;
            }
            $last = $kind === 'managed' ? 'K' : 'G';
            $sheet->setCellValue('A'.$row, '合計');
            $sheet->setCellValue($last.$row, $row > 2 ? "=SUM({$last}2:{$last}".($row - 1).')' : 0);
            if ($hasRounding) {
                $sheet->setCellValue('L'.$row, '=SUM(L2:L'.($row - 1).')');
                $last = 'L';
            }
            $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
            $sheet->getStyle("A1:{$last}1")->getFill()->setFillType('solid')->getStartColor()->setRGB('E4EFEB');
            $sheet->getStyle("C2:{$last}{$row}")->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
            if ($kind === 'managed') {
                $sheet->getStyle('E2:I'.$row)->getNumberFormat()->setFormatCode('#,##0.###;[Red]-#,##0.###');
            }
            foreach (range('A', $last) as $col) {
                $sheet->getColumnDimension($col)->setWidth(in_array($col, ['B', 'D'], true) ? 36 : 20);
            }
            $sheet->freezePane('C2')->setAutoFilter("A1:{$last}".max(1, $row - 1));
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1)->setPrintArea("A1:{$last}{$row}")
                ->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
        }
        $unknown = $book->createSheet()->setTitle('部門不明差異');
        $unknown->fromArray(['大分類CD', '大分類名', '照合日', '報告書残高', '管理品帳簿額', '非管理品帳簿額', '既知の承認差額', '棚卸不明差異金額'], null, 'A1');
        $row = 2;
        foreach (collect($data['lines'])->where('kind', 'book') as $line) {
            $ref = $line['book_reconciliation']['report_reconciliation'];
            $unknown->fromArray([$line['category1_code'], $line['category1_name'], $ref['date'],
                $ref['reference']['report_amount'] ?? null, $ref['reference']['managed_amount'] ?? null,
                $ref['unmanaged_book_amount'], $ref['approved_gap'], $line['amount']], null, 'A'.$row, true);
            $unknown->setCellValueExplicit('A'.$row, (string) $line['category1_code'], DataType::TYPE_STRING);
            $unknown->setCellValueExplicit('B'.$row, (string) $line['category1_name'], DataType::TYPE_STRING);
            $row++;
        }
        $unknown->setCellValue('A'.$row, '合計');
        $unknown->setCellValue('H'.$row, $row > 2 ? '=SUM(H2:H'.($row - 1).')' : 0);
        $unknown->getStyle('D2:H'.$row)->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
        foreach (range('A', 'H') as $col) {
            $unknown->getColumnDimension($col)->setWidth($col === 'B' ? 30 : 21);
        }
        $unknown->getStyle('A1:H1')->getFont()->setBold(true);
        $unknown->freezePane('D2')->setAutoFilter('A1:H'.max(1, $row - 1));
        $unknown->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1)->setPrintArea('A1:H'.$row)
            ->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
        return $book;
    }

    public function generate(object $record): string
    {
        $book = $this->build($record);
        $path = tempnam(sys_get_temp_dir(), 'sundry-final-');
        if ($path === false) {
            throw new \RuntimeException('一時ファイルを作成できません。');
        }
        try {
            (new Xlsx($book))->save($path);
            return file_get_contents($path);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }
}
