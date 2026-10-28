<?php

namespace App\Services\Statistics;

use App\Enums\QuantityType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class WarehouseOrderReviewExport
{
    private const BASE_KEYS = ['日付', '発注先CD', '発注先名', '仕入先CD', '仕入先名', 'JANCD', '単品CD', '商品名', '入数', '棚番', '発注点', '在庫CS', '在庫バラ', '受注CS', '受注バラ', '移動CS', '移動バラ', '1週', '2週', '3週', '前月'];

    /** @param array<array<string, mixed>> $rows */
    public function spreadsheet(array $rows, int $warehouseCode): Spreadsheet
    {
        $dates = array_values(array_unique(array_filter(array_column($rows, '入荷予定日'))));
        sort($dates);
        $dates = array_slice($dates, 0, 7);
        $headers = self::BASE_KEYS;
        $caseLabel = QuantityType::CASE->name();
        $pieceLabel = QuantityType::PIECE->name();
        $headers[11] = $warehouseCode.$caseLabel;
        $headers[12] = $warehouseCode.$pieceLabel;
        $headers[13] = '受注'.$caseLabel;
        $headers[14] = '受注'.$pieceLabel;
        $headers[15] = '移動'.$caseLabel;
        $headers[16] = '移動'.$pieceLabel;
        $headers = array_merge($headers, array_pad(array_map(fn ($date) => date('y/m/d', strtotime($date)), $dates), 7, ''), ['備考']);

        $groups = array_fill(0, 29, '');
        foreach (array_merge(range(0, 10), [28]) as $index) {
            $groups[$index] = $headers[$index];
            $headers[$index] = '';
        }
        foreach ([11 => '在庫情報', 13 => '当日営業出荷数', 15 => '当日店舗出荷数', 17 => "販売実績（総{$pieceLabel}）", 21 => "入荷予定数（{$caseLabel}）"] as $index => $label) {
            $groups[$index] = $label;
        }

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('倉庫別発注検討データ');
        $sheet->fromArray([$groups, $headers], null, 'A1');
        $grouped = [];
        foreach ($rows as $row) {
            $key = (string) $row['__item_id'];
            $grouped[$key] ??= ['base' => $row, 'arrivals' => []];
            $date = $row['入荷予定日'] ?? null;
            if ($date !== null && $date !== '') {
                $grouped[$key]['arrivals'][$date] = ($grouped[$key]['arrivals'][$date] ?? 0) + (float) ($row['入荷予定CS'] ?? 0);
            }
        }
        $line = 3;
        $fractionalCells = [];
        foreach ($grouped as $group) {
            $values = array_map(fn ($key) => $group['base'][$key] ?? null, self::BASE_KEYS);
            $values = array_merge($values, array_pad(array_map(fn ($date) => $group['arrivals'][$date] ?? null, $dates), 7, null), [(string) ($group['base']['備考'] ?? '')]);
            foreach ($values as $index => $value) {
                $cell = Coordinate::stringFromColumnIndex($index + 1).$line;
                if ($index === 0 && $value) {
                    $sheet->setCellValueExplicit($cell, Date::PHPToExcel(new \DateTimeImmutable($value)), DataType::TYPE_NUMERIC);
                } elseif (in_array($index, [1, 2, 3, 4, 5, 6, 7, 9, 28], true)) {
                    // Master text (including formula-like notes and leading-zero JANs) stays literal.
                    $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
                } elseif ($value !== null && $value !== '') {
                    $sheet->setCellValueExplicit($cell, (float) $value, DataType::TYPE_NUMERIC);
                    if (abs((float) $value - round((float) $value)) > 0.000001) {
                        $fractionalCells[] = $cell;
                    }
                }
            }
            $line++;
        }
        $last = max(2, $line - 1);
        foreach (['L1:M1', 'N1:O1', 'P1:Q1', 'R1:U1', 'V1:AB1'] as $range) {
            $sheet->mergeCells($range);
        }
        foreach (array_merge(range('A', 'K'), ['AC']) as $column) {
            $sheet->mergeCells($column.'1:'.$column.'2');
        }
        $book->getDefaultStyle()->getFont()->setName('Yu Gothic')->setSize(10);
        $sheet->setShowGridlines(false);
        $sheet->freezePane('A3');
        $sheet->setAutoFilter('A2:AC'.$last);
        $sheet->getStyle('A1:AC2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getRowDimension(2)->setRowHeight(24);
        foreach (range(1, 29) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(match ($index) {
                3, 5 => 22, 6 => 18, 8 => 48, 10 => 18, 29 => 40, default => 12,
            });
        }
        if ($last >= 3) {
            $sheet->getStyle('A3:A'.$last)->getNumberFormat()->setFormatCode('yyyy/mm/dd');
            $sheet->getStyle('I3:AB'.$last)->getNumberFormat()->setFormatCode('#,##0');
            foreach ($fractionalCells as $cell) {
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.0');
            }
            foreach (['B3:H', 'J3:J', 'AC3:AC'] as $range) {
                $sheet->getStyle($range.$last)->getNumberFormat()->setFormatCode('@');
                $sheet->getStyle($range.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            }
            $sheet->getStyle('AC3:AC'.$last)->getAlignment()->setWrapText(true);
            $sheet->getStyle('A3:AC'.$last)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }

        return $book;
    }
}
