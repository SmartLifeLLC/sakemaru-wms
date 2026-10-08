<?php

namespace App\Services\SundryInventoryCount;

use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountItem;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * 棚卸し（量り売り）差異表（XLSX）。旧Access（量り売り棚卸）の出力をまとめて出す。
 *
 *  - 差異表 : 店舗へ送る表（Q_06_量り売り差異数）。実棚を入力した明細だけを出す。
 *  - 店舗計 : 差異・期間売上・ロスの合計（Q_08_量り売り差異数_店舗計）。
 *  - 全明細 : 未入力も含めた全明細（確認用）。
 */
class WeighedInventoryDifferenceWorkbookService
{
    private const AMOUNT_FORMAT = '#,##0;[Red]-#,##0';

    private const QUANTITY_FORMAT = '#,##0.###;[Red]-#,##0.###';

    private const PRICE_FORMAT = '#,##0.00';

    /**
     * @return non-empty-string
     */
    public function generate(WmsSundryInventoryCount $count): string
    {
        $spreadsheet = $this->build($count);

        $tempPath = tempnam(sys_get_temp_dir(), 'wms-weighed-inventory-');
        if ($tempPath === false) {
            throw new RuntimeException('一時ファイルを作成できません。');
        }

        try {
            (new Xlsx($spreadsheet))->save($tempPath);

            return (string) file_get_contents($tempPath);
        } finally {
            $spreadsheet->disconnectWorksheets();

            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function build(WmsSundryInventoryCount $count): Spreadsheet
    {
        $items = WmsSundryInventoryCountItem::query()
            ->where('sundry_inventory_count_id', $count->id)
            ->orderByRaw('display_order IS NULL')
            ->orderBy('display_order')
            ->orderBy('item_code')
            ->orderBy('id')
            ->get();

        $service = new SundryInventoryCountService;
        $spreadsheet = new Spreadsheet;

        $differenceSheet = $spreadsheet->getActiveSheet();
        $differenceSheet->setTitle('差異表');
        $this->writeDifference($differenceSheet, $count, $items->filter(
            fn (WmsSundryInventoryCountItem $item): bool => $item->counted_quantity !== null
        )->values());

        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('店舗計');
        $this->writeSummary($summarySheet, $count, $service->weighedSummary($count));

        $allSheet = $spreadsheet->createSheet();
        $allSheet->setTitle('全明細');
        $this->writeAll($allSheet, $count, $items, $service);

        $spreadsheet->getProperties()
            ->setTitle('棚卸し（量り売り）差異表')
            ->setSubject($count->count_no ?? '');
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * 店舗へ送る表。列の並びは旧Accessの Q_06 と同じ。
     *
     * @param  Collection<int, WmsSundryInventoryCountItem>  $items  実棚を入力した明細
     */
    private function writeDifference(Worksheet $sheet, WmsSundryInventoryCount $count, Collection $items): void
    {
        $sheet->fromArray([
            '棚卸し日', '店舗CD', '単品CD', '商品名', '理論在庫数', '実棚数(カメ)', '実棚数(QT)',
            '差異数', '差異金額', '仕入単価', '売上数量',
        ], null, 'A1');

        $countDate = $count->count_date?->format('Y/m/d');
        $row = 2;
        foreach ($items as $item) {
            $sheet->setCellValue("A{$row}", $countDate);
            $sheet->setCellValueExplicit("B{$row}", (string) $count->warehouse_code, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$row}", (string) $item->item_code, DataType::TYPE_STRING);
            $sheet->setCellValue("D{$row}", $this->cleanName($item->item_name));
            $sheet->setCellValue("E{$row}", (float) $item->system_quantity);
            $sheet->setCellValue("F{$row}", (float) ($item->counted_quantity_jar ?? 0));
            $sheet->setCellValue("G{$row}", (float) ($item->counted_quantity_reserve ?? 0));
            $sheet->setCellValue("H{$row}", (float) $item->difference_quantity);
            $sheet->setCellValue("I{$row}", (float) $item->difference_amount);
            $sheet->setCellValue("J{$row}", (float) $item->cost_price);
            $sheet->setCellValue("K{$row}", $item->period_sales_quantity);
            $row++;
        }

        $lastRow = max($row - 1, 1);
        if ($items->isNotEmpty()) {
            $sheet->setCellValue("D{$row}", '合計');
            foreach (['E', 'F', 'G', 'H', 'I', 'K'] as $column) {
                $sheet->setCellValue("{$column}{$row}", "=SUM({$column}2:{$column}{$lastRow})");
            }
            $sheet->getStyle("A{$row}:K{$row}")->getFont()->setBold(true);
            $lastRow = $row;
        }

        $this->styleHeader($sheet, 'A1:K1');
        $sheet->getStyle("E2:H{$lastRow}")->getNumberFormat()->setFormatCode(self::QUANTITY_FORMAT);
        $sheet->getStyle("I2:I{$lastRow}")->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);
        $sheet->getStyle("J2:J{$lastRow}")->getNumberFormat()->setFormatCode(self::PRICE_FORMAT);
        $sheet->getStyle("K2:K{$lastRow}")->getNumberFormat()->setFormatCode(self::QUANTITY_FORMAT);
        $sheet->freezePane('A2');
        foreach (['A' => 12, 'B' => 8, 'C' => 10, 'D' => 46, 'E' => 12, 'F' => 13, 'G' => 13, 'H' => 10, 'I' => 12, 'J' => 10, 'K' => 10] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * @param  array<string, float|int>  $summary
     */
    private function writeSummary(Worksheet $sheet, WmsSundryInventoryCount $count, array $summary): void
    {
        $lossPercent = rtrim(rtrim(number_format((float) $summary['loss_rate'] * 100, 2), '0'), '.');

        $sheet->setCellValue('A1', '棚卸し（量り売り）店舗計');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->fromArray([
            ['棚卸しNo', $count->count_no],
            ['店舗', trim(($count->warehouse_code ?? '').' '.($count->warehouse_name ?? ''))],
            ['棚卸し日', $count->count_date?->format('Y/m/d')],
            ['受払終了日', $count->theory_end_date?->format('Y/m/d')],
            ['期間売上の開始日', $count->sales_from_date?->format('Y/m/d')],
            ['ステータス', $count->status_label],
            ['明細数 / 入力済 / 未入力', "{$summary['detail_count']} / {$summary['counted_count']} / {$summary['uncounted_count']}"],
        ], null, 'A2');

        $headerRow = 10;
        $sheet->fromArray([
            '棚卸し日', '店舗CD', '差異数計', '差異金額計', '売上数量計', 'ロス金額', 'ロス数量', 'ロス申請後差異金額',
        ], null, "A{$headerRow}");

        $row = $headerRow + 1;
        $sheet->setCellValue("A{$row}", $count->count_date?->format('Y/m/d'));
        $sheet->setCellValueExplicit("B{$row}", (string) $count->warehouse_code, DataType::TYPE_STRING);
        $sheet->fromArray([
            $summary['difference_quantity'],
            $summary['difference_amount'],
            $summary['sales_quantity'],
            $summary['loss_amount'],
            $summary['loss_quantity'],
            $summary['difference_amount_after_loss'],
        ], null, "C{$row}", true);

        $sheet->fromArray([
            ['入力済みの明細だけを合計しています。未入力の明細は含めていません。'],
            ["ロス数量 = 期間売上数量 × {$lossPercent}%（商品ごとに四捨五入）。ロス金額 = ロス数量 × 仕入単価。"],
            ['ロス申請後差異金額 =（カメ + QT −（理論在庫数 − ロス数量））× 仕入単価 = 差異金額 + ロス金額。'],
        ], null, 'A'.($row + 2));

        $this->styleHeader($sheet, "A{$headerRow}:H{$headerRow}");
        $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode(self::QUANTITY_FORMAT);
        $sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);
        $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode(self::QUANTITY_FORMAT);
        $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);
        $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode(self::QUANTITY_FORMAT);
        $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);
        foreach (['A' => 26, 'B' => 30, 'C' => 12, 'D' => 14, 'E' => 12, 'F' => 12, 'G' => 12, 'H' => 20] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * @param  Collection<int, WmsSundryInventoryCountItem>  $items
     */
    private function writeAll(Worksheet $sheet, WmsSundryInventoryCount $count, Collection $items, SundryInventoryCountService $service): void
    {
        $sheet->fromArray([
            '店舗CD', '単品CD', '商品名', '仕入単価', '理論在庫数', '実棚数(カメ)', '実棚数(QT)', '実棚計',
            '差異数', '理論金額', '実棚金額', '差異金額', '売上数量', 'ロス数量', '入力', '追加', '入力者',
        ], null, 'A1');

        $row = 2;
        foreach ($items as $item) {
            $counted = $item->counted_quantity !== null;

            $sheet->setCellValueExplicit("A{$row}", (string) $count->warehouse_code, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$row}", (string) $item->item_code, DataType::TYPE_STRING);
            $sheet->setCellValue("C{$row}", $this->cleanName($item->item_name));
            $sheet->setCellValue("D{$row}", (float) $item->cost_price);
            $sheet->setCellValue("E{$row}", (float) $item->system_quantity);
            $sheet->setCellValue("F{$row}", $item->counted_quantity_jar);
            $sheet->setCellValue("G{$row}", $item->counted_quantity_reserve);
            $sheet->setCellValue("H{$row}", $item->counted_quantity);
            $sheet->setCellValue("I{$row}", $item->difference_quantity);
            $sheet->setCellValue("J{$row}", (float) $item->system_amount);
            $sheet->setCellValue("K{$row}", $item->counted_amount);
            $sheet->setCellValue("L{$row}", $item->difference_amount);
            $sheet->setCellValue("M{$row}", $item->period_sales_quantity);
            $sheet->setCellValue("N{$row}", $item->period_sales_quantity === null ? null : $service->weighedLossQuantity($item->period_sales_quantity));
            $sheet->setCellValue("O{$row}", $counted ? '入力済' : '未入力');
            $sheet->setCellValue("P{$row}", $item->is_additional ? '追加' : '');
            $sheet->setCellValue("Q{$row}", $item->counted_by_name);
            $row++;
        }

        $lastRow = max($row - 1, 1);
        $this->styleHeader($sheet, 'A1:Q1');
        $sheet->getStyle("D2:D{$lastRow}")->getNumberFormat()->setFormatCode(self::PRICE_FORMAT);
        $sheet->getStyle("E2:I{$lastRow}")->getNumberFormat()->setFormatCode(self::QUANTITY_FORMAT);
        $sheet->getStyle("J2:L{$lastRow}")->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);
        $sheet->getStyle("M2:N{$lastRow}")->getNumberFormat()->setFormatCode(self::QUANTITY_FORMAT);
        $sheet->setAutoFilter("A1:Q{$lastRow}");
        $sheet->freezePane('A2');
        foreach (['A' => 8, 'B' => 10, 'C' => 46, 'D' => 10, 'E' => 12, 'F' => 13, 'G' => 13, 'H' => 10, 'I' => 10, 'J' => 12, 'K' => 12, 'L' => 12, 'M' => 10, 'N' => 10, 'O' => 8, 'P' => 7, 'Q' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * 商品名の前後の空白（全角を含む）を落とす。
     */
    private function cleanName(mixed $name): string
    {
        return (string) preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', (string) ($name ?? ''));
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
    }
}
