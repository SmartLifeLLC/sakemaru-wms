<?php

namespace App\Services\SundryInventoryCount;

use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountAmount;
use App\Models\WmsSundryInventoryCountItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * 棚卸し（雑貨）差異表（XLSX）。
 *
 * 旧Accessで出していた一覧をまとめて出力する:
 *  - 集計        : 理論金額・実棚金額・差異金額（中分類別。在庫管理あり＝数量、在庫管理なし＝金額）
 *  - 数量明細    : 在庫管理あり商品の理論数・実棚数・差異と金額
 *  - 金額明細    : 在庫管理なし（中分類別）の前残・金額受払・差異と、在庫金額報告書（大分類）との突合
 *  - 非管理品仕入: 在庫管理なし商品の仕入明細（基準日の翌日〜受払終了日）
 */
class SundryInventoryDifferenceWorkbookService
{
    private const AMOUNT_FORMAT = '#,##0;[Red]-#,##0';

    private const QUANTITY_FORMAT = '#,##0.###;[Red]-#,##0.###';

    /**
     * @return non-empty-string
     */
    public function generate(WmsSundryInventoryCount $count): string
    {
        $spreadsheet = $this->build($count);

        $tempPath = tempnam(sys_get_temp_dir(), 'wms-sundry-inventory-');
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
        $spreadsheet = new Spreadsheet;

        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('集計');
        $this->writeSummary($summarySheet, $count);

        $itemSheet = $spreadsheet->createSheet();
        $itemSheet->setTitle('数量明細');
        $this->writeItems($itemSheet, $count);

        $amounts = WmsSundryInventoryCountAmount::query()
            ->where('sundry_inventory_count_id', $count->id)
            ->orderBy('category1_code')
            ->orderBy('category2_code')
            ->orderBy('id')
            ->get();

        $amountSheet = $spreadsheet->createSheet();
        $amountSheet->setTitle('金額明細');
        $this->writeAmounts($amountSheet, $count, $amounts);

        $purchaseSheet = $spreadsheet->createSheet();
        $purchaseSheet->setTitle('非管理品仕入');
        $this->writePurchases($purchaseSheet, $count, $amounts);

        $spreadsheet->getProperties()
            ->setTitle('棚卸し（雑貨）差異表')
            ->setSubject($count->count_no ?? '');
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function writeSummary(Worksheet $sheet, WmsSundryInventoryCount $count): void
    {
        $summary = (new SundryInventoryCountService)->summary($count);

        $sheet->setCellValue('A1', '棚卸し（雑貨）差異表');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->fromArray([
            ['棚卸しNo', $count->count_no],
            ['倉庫', trim(($count->warehouse_code ?? '').' '.($count->warehouse_name ?? ''))],
            ['棚卸し日', $count->count_date?->format('Y/m/d')],
            ['受払終了日', $count->theory_end_date?->format('Y/m/d')],
            ['ステータス', $count->status_label],
        ], null, 'A2');

        $headerRow = 8;
        $sheet->fromArray(['区分', '分類CD', '分類名', '明細数', '未入力', '理論金額', '実棚金額', '差異金額'], null, "A{$headerRow}");

        $lines = [];
        foreach ($summary['managed'] as $line) {
            $lines[] = ['在庫管理あり（中分類・数量）', $line, false];
        }
        $lines[] = ['', $summary['totals']['managed'], true];
        foreach ($summary['unmanaged'] as $line) {
            $lines[] = ['在庫管理なし（中分類・金額）', $line, false];
        }
        $lines[] = ['', $summary['totals']['unmanaged'], true];
        $lines[] = ['', $summary['totals']['total'], true];

        $row = $headerRow + 1;
        foreach ($lines as [$kind, $line, $isTotal]) {
            $sheet->setCellValue("A{$row}", $kind);
            $sheet->setCellValueExplicit("B{$row}", (string) $line['code'], DataType::TYPE_STRING);
            $sheet->fromArray([
                $line['name'],
                $line['detail_count'],
                $line['uncounted_count'],
                $line['system'],
                $line['counted'],
                $line['difference'],
            ], null, "C{$row}", true);

            if ($isTotal) {
                $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
            }
            $row++;
        }

        $lastRow = $row - 1;
        $sheet->setCellValue('A'.($lastRow + 2), '未入力の明細は実棚金額・差異金額に含めていません。');
        $this->styleHeader($sheet, "A{$headerRow}:H{$headerRow}");
        $sheet->getStyle("F{$headerRow}:H{$lastRow}")->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);
        foreach (['A' => 30, 'B' => 10, 'C' => 22, 'D' => 10, 'E' => 10, 'F' => 16, 'G' => 16, 'H' => 16] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A'.($headerRow + 1));
    }

    private function writeItems(Worksheet $sheet, WmsSundryInventoryCount $count): void
    {
        $sheet->fromArray([
            '中分類CD', '中分類名', '商品CD', '商品名', '原価', '理論数', '実棚数', '差異数',
            '理論金額', '実棚金額', '差異金額', '追加', '入力者',
        ], null, 'A1');

        $row = 2;
        WmsSundryInventoryCountItem::query()
            ->where('sundry_inventory_count_id', $count->id)
            ->orderBy('category2_code')
            ->orderBy('item_code')
            ->orderBy('id')
            ->chunk(1000, function ($items) use ($sheet, &$row): void {
                foreach ($items as $item) {
                    $sheet->setCellValueExplicit("A{$row}", (string) $item->category2_code, DataType::TYPE_STRING);
                    $sheet->setCellValue("B{$row}", $item->category2_name);
                    $sheet->setCellValueExplicit("C{$row}", (string) $item->item_code, DataType::TYPE_STRING);
                    $sheet->setCellValue("D{$row}", trim((string) $item->item_name));
                    $sheet->setCellValue("E{$row}", (float) $item->cost_price);
                    $sheet->setCellValue("F{$row}", (float) $item->system_quantity);
                    $sheet->setCellValue("G{$row}", $item->counted_quantity);
                    $sheet->setCellValue("H{$row}", $item->difference_quantity);
                    $sheet->setCellValue("I{$row}", (float) $item->system_amount);
                    $sheet->setCellValue("J{$row}", $item->counted_amount);
                    $sheet->setCellValue("K{$row}", $item->difference_amount);
                    $sheet->setCellValue("L{$row}", $item->is_additional ? '追加' : '');
                    $sheet->setCellValue("M{$row}", $item->counted_by_name);
                    $row++;
                }
            });

        $lastRow = max($row - 1, 1);
        $this->styleHeader($sheet, 'A1:M1');
        $sheet->getStyle("E2:E{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("F2:H{$lastRow}")->getNumberFormat()->setFormatCode(self::QUANTITY_FORMAT);
        $sheet->getStyle("I2:K{$lastRow}")->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);
        $sheet->setAutoFilter("A1:M{$lastRow}");
        $sheet->freezePane('A2');
        foreach (['A' => 10, 'B' => 14, 'C' => 12, 'D' => 52, 'E' => 12, 'F' => 11, 'G' => 11, 'H' => 11, 'I' => 14, 'J' => 14, 'K' => 14, 'L' => 7, 'M' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * @param  Collection<int, WmsSundryInventoryCountAmount>  $amounts
     */
    private function writeAmounts(Worksheet $sheet, WmsSundryInventoryCount $count, Collection $amounts): void
    {
        $sheet->fromArray([
            '大分類CD', '大分類名', '中分類CD', '中分類名', '基準日', '前残金額', '前残の出所',
            '仕入', '移入', '移出', '売上原価', '調整', '理論金額', '実棚金額', '差異金額', '追加', '入力者',
        ], null, 'A1');

        $row = 2;
        foreach ($amounts as $amount) {
            $sheet->setCellValueExplicit("A{$row}", (string) $amount->category1_code, DataType::TYPE_STRING);
            $sheet->setCellValue("B{$row}", $amount->category1_name);
            $sheet->setCellValueExplicit("C{$row}", (string) $amount->category2_code, DataType::TYPE_STRING);
            $sheet->setCellValue("D{$row}", $amount->category2_name);
            $sheet->setCellValue("E{$row}", $amount->opening_date?->format('Y/m/d'));
            $sheet->setCellValue("F{$row}", (float) $amount->opening_amount);
            $sheet->setCellValue("G{$row}", $amount->opening_source_label);
            $sheet->setCellValue("H{$row}", (float) $amount->purchase_amount);
            $sheet->setCellValue("I{$row}", (float) $amount->transfer_in_amount);
            $sheet->setCellValue("J{$row}", (float) $amount->transfer_out_amount);
            $sheet->setCellValue("K{$row}", (float) $amount->sales_cost_amount);
            $sheet->setCellValue("L{$row}", (float) $amount->adjustment_amount);
            $sheet->setCellValue("M{$row}", (float) $amount->system_amount);
            $sheet->setCellValue("N{$row}", $amount->counted_amount);
            $sheet->setCellValue("O{$row}", $amount->difference_amount);
            $sheet->setCellValue("P{$row}", $amount->is_additional ? '追加' : '');
            $sheet->setCellValue("Q{$row}", $amount->counted_by_name);
            $row++;
        }

        $lastRow = max($row - 1, 1);
        $this->styleHeader($sheet, 'A1:Q1');
        $sheet->getStyle("F2:F{$lastRow}")->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);
        $sheet->getStyle("H2:O{$lastRow}")->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);

        $row = $lastRow + 2;
        $sheet->fromArray([
            ['理論金額 = 前残金額 + 仕入 + 移入 − 移出 − 売上原価 + 調整（基準日の翌日〜受払終了日）。売上原価 = 売価 × 分類原価率（小→中→大分類）。'],
            ['前残金額 = 前回確定した雑貨棚卸の実棚金額。無い場合は旧システム（Ｔ３在庫）の最終残高（2026/05/05 時点。新システムの受払は 5/6 から）。'],
        ], null, "A{$row}");

        $row = $this->writeReportReferences($sheet, $count, $row + 3);

        $sheet->freezePane('A2');
        foreach (['A' => 10, 'B' => 16, 'C' => 10, 'D' => 18, 'E' => 12, 'F' => 14, 'G' => 18, 'H' => 13, 'I' => 13, 'J' => 13, 'K' => 13, 'L' => 13, 'M' => 14, 'N' => 14, 'O' => 14, 'P' => 7, 'Q' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * 在庫金額報告書（大分類別）から求めた理論金額と、中分類明細の合計を並べる。
     *
     * @return int 次の空き行
     */
    private function writeReportReferences(Worksheet $sheet, WmsSundryInventoryCount $count, int $row): int
    {
        $references = (new SundryInventoryCountService)->reportReferences($count);
        if ($references === []) {
            return $row;
        }

        $sheet->setCellValue("A{$row}", '在庫金額報告書との突合（大分類別・参考）');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $headerRow = $row;
        $sheet->fromArray([
            '大分類CD', '大分類名', '報告書 対象月', '報告書 月末金額', 'うち在庫管理あり', '非管理 月末残高',
            '月末以降の受払', '報告書ベース理論金額', '中分類明細 理論金額計', '差',
        ], null, "A{$row}");
        $this->styleHeader($sheet, "A{$row}:J{$row}");
        $row++;

        foreach ($references as $reference) {
            $sheet->setCellValueExplicit("A{$row}", (string) $reference['category1_code'], DataType::TYPE_STRING);
            $sheet->fromArray([
                $reference['category1_name'],
                $reference['report_month'] ?? '報告書なし',
                $reference['report_amount'],
                $reference['managed_amount'],
                $reference['opening_amount'],
                $reference['flow_amount'],
                $reference['system_amount'],
                $reference['detail_system_amount'],
                $reference['difference_amount'],
            ], null, "B{$row}", true);
            $row++;
        }

        $sheet->getStyle('D'.($headerRow + 1).':J'.($row - 1))->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);
        $sheet->setCellValue("A{$row}", '非管理 月末残高 = 報告書の月末金額 − 在庫管理あり商品の月末評価額。差 = 中分類明細 理論金額計 − 報告書ベース理論金額。');
        $sheet->setCellValue('A'.($row + 1), '在庫金額報告書の在庫管理なし商品は、旧システム期間（5/1〜5/5）の売上原価・移動を含みません。その分は差に出ます。');

        return $row + 2;
    }

    /**
     * @param  Collection<int, WmsSundryInventoryCountAmount>  $amounts
     */
    private function writePurchases(Worksheet $sheet, WmsSundryInventoryCount $count, Collection $amounts): void
    {
        $sheet->fromArray([
            '伝票日付', '伝票番号', '仕入先CD', '仕入先名', '中分類CD', '商品CD', '商品名', '数量', '数量区分', '総バラ数', '金額', '備考',
        ], null, 'A1');

        $calculator = new SundryInventoryTheoryCalculator;
        $endDate = $count->theory_end_date?->toDateString() ?? $count->count_date->toDateString();
        $row = 2;

        $amounts
            ->filter(fn (WmsSundryInventoryCountAmount $amount): bool => $amount->opening_date !== null)
            ->groupBy(fn (WmsSundryInventoryCountAmount $amount): string => $amount->opening_date->toDateString())
            ->sortKeys()
            ->each(function (Collection $group, string $openingDate) use ($calculator, $count, $endDate, $sheet, &$row): void {
                $lines = $calculator->unmanagedPurchaseLines(
                    (int) $count->client_id,
                    (int) $count->warehouse_id,
                    $group->pluck('category2_id')->map(fn ($id): int => (int) $id)->all(),
                    CarbonImmutable::parse($openingDate)->addDay()->toDateString(),
                    $endDate,
                    2,
                );

                foreach ($lines as $line) {
                    $sheet->setCellValue("A{$row}", CarbonImmutable::parse($line->process_date)->format('Y/m/d'));
                    $sheet->setCellValueExplicit("B{$row}", (string) ($line->slip_number ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("C{$row}", (string) ($line->supplier_code ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValue("D{$row}", $line->supplier_name);
                    $sheet->setCellValueExplicit("E{$row}", (string) ($line->category2_code ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("F{$row}", (string) ($line->item_code ?? ''), DataType::TYPE_STRING);
                    $sheet->setCellValue("G{$row}", trim((string) $line->item_name));
                    $sheet->setCellValue("H{$row}", (float) ($line->quantity ?? 0));
                    $sheet->setCellValue("I{$row}", $line->quantity_type);
                    $sheet->setCellValue("J{$row}", (float) ($line->total_piece_quantity ?? 0));
                    $sheet->setCellValue("K{$row}", (float) ($line->amount ?? 0));
                    $sheet->setCellValue("L{$row}", $line->note);
                    $row++;
                }
            });

        $lastRow = max($row - 1, 1);
        $this->styleHeader($sheet, 'A1:L1');
        $sheet->getStyle("H2:J{$lastRow}")->getNumberFormat()->setFormatCode(self::QUANTITY_FORMAT);
        $sheet->getStyle("K2:K{$lastRow}")->getNumberFormat()->setFormatCode(self::AMOUNT_FORMAT);
        $sheet->setAutoFilter("A1:L{$lastRow}");
        $sheet->freezePane('A2');
        foreach (['A' => 12, 'B' => 14, 'C' => 10, 'D' => 30, 'E' => 10, 'F' => 12, 'G' => 44, 'H' => 9, 'I' => 9, 'J' => 10, 'K' => 13, 'L' => 40] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
    }
}
