<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\ItemCategory;
use App\Models\Sakemaru\Location;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class InventoryDiffListWorkbookService
{
    /**
     * @return non-empty-string
     */
    public function generate(WmsInventoryCount $inventoryCount, int $round, ?string $reportDate = null): string
    {
        $round = min(max($round, 1), 3);
        $reportDate = CarbonImmutable::parse($reportDate ?? now()->toDateString())->toDateString();
        $items = (new InventoryDiffListPdfService)->diffItemsForRound($inventoryCount, $round);
        $janCodes = $items->isEmpty()
            ? []
            : (new InventoryJanCodeResolver)->forItems($items);
        $sameDayOrders = $this->sameDayOrders($inventoryCount, $reportDate);

        $spreadsheet = new Spreadsheet;
        $recountSheet = $spreadsheet->getActiveSheet();
        $recountSheet->setTitle('再棚当たり表');
        $this->writeRecountRows($recountSheet, $items, $sameDayOrders);

        $sourceSheet = $spreadsheet->createSheet();
        $sourceSheet->setTitle('差異表原本');
        $this->writeSourceRows($sourceSheet, $this->sourceRows($inventoryCount, $items, $janCodes));

        $orderSheet = $spreadsheet->createSheet();
        $orderSheet->setTitle('当日受注');
        $this->writeOrderRows($orderSheet, $sameDayOrders, $items->pluck('item_id')->map(fn ($id): int => (int) $id)->all());

        $spreadsheet->getProperties()
            ->setTitle("{$round}回目 再棚当たり表")
            ->setSubject($inventoryCount->count_no ?? '')
            ->setDescription("当日受注基準日: {$reportDate}");
        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'wms-inventory-recount-');
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

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  Collection<int, object>  $sameDayOrders
     */
    private function writeRecountRows(Worksheet $sheet, Collection $items, Collection $sameDayOrders): void
    {
        $sheet->fromArray(['単品CD', 'アイテム名称', 'ロケ', '理論在庫', '実棚数量', '差異'], null, 'A1');
        $sheet->setCellValue('H1', '当日');
        $sheet->setCellValue('I1', '入力');
        $sheet->fromArray(['当日受注', '理論在庫', '実棚数量', '差異'], null, 'M1');
        $lastOrderRow = max($sameDayOrders->count() + 1, 2);

        foreach ($items->values() as $index => $item) {
            $row = $index + 2;
            $systemQuantity = (float) ($item->getAttribute('pdf_system_quantity') ?? 0);
            $actualQuantity = (float) ($item->getAttribute('pdf_actual_quantity') ?? 0);

            $sheet->setCellValueExplicit("A{$row}", (string) ($item->item_code ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("B{$row}", $item->item_name ?? '');
            $sheet->setCellValue("C{$row}", InventoryCountLocationResolver::locationNo($item));
            $sheet->setCellValue("D{$row}", "=N{$row}-M{$row}");
            $sheet->setCellValue("E{$row}", "=O{$row}-M{$row}");
            $sheet->setCellValue("F{$row}", "=E{$row}-D{$row}");
            $sheet->setCellValue("H{$row}", "=IF(M{$row}=0,\"\",M{$row})");
            $sheet->setCellValue("I{$row}", $item->input_count ?? 0);
            $sheet->setCellValue("M{$row}", "=SUMIF('当日受注'!\$A\$2:\$A\${$lastOrderRow},A{$row},'当日受注'!\$G\$2:\$G\${$lastOrderRow})");
            $sheet->setCellValue("N{$row}", $systemQuantity);
            $sheet->setCellValue("O{$row}", $actualQuantity);
            $sheet->setCellValue("P{$row}", "=O{$row}-N{$row}");
        }

        $lastRow = max($items->count() + 1, 1);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:F{$lastRow}");
        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(58);
        $sheet->getColumnDimension('C')->setWidth(15);
        foreach (['D', 'E', 'F'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(14);
        }
        foreach (['G', 'J', 'K', 'L'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(2);
        }
        foreach (['H', 'I'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(10);
        }
        foreach (['M', 'N', 'O', 'P'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(14);
        }
        $sheet->getRowDimension(1)->setRowHeight(22);
        for ($row = 2; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(24);
        }
        $this->styleHeader($sheet, 'A1:F1');
        $this->styleHeader($sheet, 'H1:I1');
        $this->styleHeader($sheet, 'M1:P1');
        $this->styleGrid($sheet, "A1:F{$lastRow}");
        $this->styleGrid($sheet, "H1:I{$lastRow}");
        $this->styleGrid($sheet, "M1:P{$lastRow}");
        $sheet->getStyle("D2:E{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.####;[Red]-#,##0.####;0');
        $sheet->getStyle("F2:F{$lastRow}")->getNumberFormat()->setFormatCode('+#,##0.####;[Red]-#,##0.####;0');
        $sheet->getStyle("H2:I{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.####;[Red]-#,##0.####;0');
        $sheet->getStyle("M2:O{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.####;[Red]-#,##0.####;0');
        $sheet->getStyle("M2:M{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.####;[Red]-#,##0.####;""');
        $sheet->getStyle("P2:P{$lastRow}")->getNumberFormat()->setFormatCode('+#,##0.####;[Red]-#,##0.####;0');
        $sheet->getStyle("A2:C{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("B2:B{$lastRow}")->getAlignment()->setWrapText(true);
        $this->configurePrint($sheet, 'I', $lastRow);
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  array<int, string>  $janCodes
     * @return array<int, array<string, mixed>>
     */
    private function sourceRows(WmsInventoryCount $inventoryCount, Collection $items, array $janCodes): array
    {
        return $items
            ->map(fn (WmsInventoryCountItem $item): array => [
                '棚卸しNo' => $inventoryCount->count_no ?? '',
                '棚卸日' => $inventoryCount->count_date?->format('Y/m/d') ?? '',
                '倉庫CD' => $inventoryCount->warehouse_code ?? '',
                '倉庫名' => $inventoryCount->warehouse_name ?? '',
                'JANコード' => $janCodes[(int) $item->item_id] ?? '',
                'アイテムコード' => $item->item_code ?? '',
                'アイテム名称' => $item->item_name ?? '',
                '部門CD' => $this->majorCategoryCode($item),
                '部門名' => $this->majorCategoryName($item),
                '中分類CD' => $this->middleCategoryCode($item),
                '中分類名' => $this->middleCategoryName($item),
                '棚番' => $this->shelfPrefix($item),
                'ロケ' => InventoryCountLocationResolver::locationNo($item),
                'ロットNO' => $item->lot_no ?? '',
                '賞味期限' => $item->expiration_date?->format('Y/m/d') ?? '',
                '入力' => $item->input_count ?? 0,
                '終了理論' => $item->getAttribute('pdf_system_quantity'),
                '実数量' => $item->getAttribute('pdf_actual_quantity'),
                '終了差異' => $item->getAttribute('pdf_end_difference_quantity'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeSourceRows(Worksheet $sheet, array $rows): void
    {
        $columns = [
            '棚卸しNo', '棚卸日', '倉庫CD', '倉庫名', 'JANコード', 'アイテムコード', 'アイテム名称',
            '部門CD', '部門名', '中分類CD', '中分類名', '棚番', 'ロケ', 'ロットNO', '賞味期限',
            '入力', '終了理論', '実数量', '終了差異',
        ];
        $sheet->fromArray($columns, null, 'A1');

        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 2;
            foreach ($columns as $columnIndex => $label) {
                $column = $columnIndex + 1;
                $value = $row[$label] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                if (! in_array($label, ['入力', '終了理論', '実数量', '終了差異'], true)) {
                    $sheet->setCellValueExplicit([$column, $excelRow], (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$column, $excelRow], $value);
                }
            }
        }

        $lastRow = max(count($rows) + 1, 1);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:S{$lastRow}");
        foreach ([
            'A' => 20, 'B' => 12, 'C' => 10, 'D' => 16, 'E' => 18, 'F' => 14, 'G' => 54,
            'H' => 10, 'I' => 18, 'J' => 10, 'K' => 20, 'L' => 10, 'M' => 14, 'N' => 18,
            'O' => 13, 'P' => 10, 'Q' => 12, 'R' => 12, 'S' => 12,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $this->styleHeader($sheet, 'A1:S1');
        $this->styleGrid($sheet, "A1:S{$lastRow}");
        $sheet->getStyle("A2:O{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("P2:S{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("P2:R{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.####;[Red]-#,##0.####;0');
        $sheet->getStyle("S2:S{$lastRow}")->getNumberFormat()->setFormatCode('+#,##0.####;[Red]-#,##0.####;0');
        $this->configurePrint($sheet, 'S', $lastRow);
    }

    /**
     * @param  Collection<int, object>  $sameDayOrders
     * @param  array<int, int>  $diffItemIds
     */
    private function writeOrderRows(Worksheet $sheet, Collection $sameDayOrders, array $diffItemIds): void
    {
        $headers = ['単品CD', '表示正式名称', '棚番', '入数', '数量ケース', '数量バラ', '総バラ数', '在庫数量', '引当可能数', 'サブ'];
        $sheet->fromArray($headers, null, 'A1');
        $diffItemMap = array_fill_keys($diffItemIds, true);

        foreach ($sameDayOrders->values() as $index => $order) {
            $row = $index + 2;
            $capacityCase = max((int) ($order->capacity_case ?? 1), 1);
            $total = (float) $order->ordered_quantity;
            $caseQuantity = $capacityCase > 1 ? intdiv((int) $total, $capacityCase) : 0;
            $pieceQuantity = $total - ($caseQuantity * $capacityCase);
            $locationNo = InventoryCountLocationResolver::normalizeLabel(
                Location::formatCode($order->code1, $order->code2, $order->code3),
            );

            $sheet->setCellValueExplicit("A{$row}", (string) ($order->item_code ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("B{$row}", $order->item_name ?? '');
            $sheet->setCellValue("C{$row}", $locationNo);
            $sheet->setCellValue("D{$row}", $capacityCase);
            $sheet->setCellValue("E{$row}", $caseQuantity === 0 ? null : $caseQuantity);
            $sheet->setCellValue("F{$row}", $pieceQuantity == 0.0 ? null : $pieceQuantity);
            $sheet->setCellValue("G{$row}", "=D{$row}*E{$row}+F{$row}");
            $sheet->setCellValue("H{$row}", $order->current_quantity ?? 0);
            $sheet->setCellValue("I{$row}", $order->available_quantity ?? 0);
            $sheet->setCellValue("J{$row}", isset($diffItemMap[(int) $order->item_id]) ? 1 : 0);
        }

        $lastRow = max($sameDayOrders->count() + 1, 1);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:J{$lastRow}");
        foreach (['A' => 14, 'B' => 56, 'C' => 15, 'D' => 10, 'E' => 12, 'F' => 12, 'G' => 12, 'H' => 12, 'I' => 14, 'J' => 8] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $this->styleHeader($sheet, 'A1:J1');
        $this->styleGrid($sheet, "A1:J{$lastRow}");
        $sheet->getStyle("D2:J{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.####;[Red]-#,##0.####;0');
        $this->configurePrint($sheet, 'J', $lastRow);
    }

    private function configurePrint(Worksheet $sheet, string $lastColumn, int $lastRow): void
    {
        $sheet->getPageSetup()
            ->setPrintArea("A1:{$lastColumn}{$lastRow}")
            ->setRowsToRepeatAtTopByStartAndEnd(1, 1)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
    }

    /**
     * @return Collection<int, object>
     */
    private function sameDayOrders(WmsInventoryCount $inventoryCount, string $reportDate): Collection
    {
        $pieceQty = "COALESCE(NULLIF(ti.total_piece_quantity, 0), CASE ti.quantity_type WHEN 'CASE' THEN ti.quantity * COALESCE(NULLIF(ti.capacity_case, 0), 1) WHEN 'CARTON' THEN ti.quantity * COALESCE(NULLIF(ti.capacity_carton, 0), 1) ELSE ti.quantity END)";
        $returnCondition = "COALESCE(t.is_returned, 0) = 1 OR COALESCE(t.trade_direction, 'NORMAL') = 'RETURN' OR ({$pieceQty}) < 0";

        $orders = DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('trades as t', 't.id', '=', 'ti.trade_id')
            ->join('earnings as e', 'e.trade_id', '=', 't.id')
            ->join('items as i', 'i.id', '=', 'ti.item_id')
            ->leftJoin('item_incoming_default_locations as idl', function ($join) use ($inventoryCount): void {
                $join->on('idl.item_id', '=', 'i.id')
                    ->where('idl.warehouse_id', '=', $inventoryCount->warehouse_id);
            })
            ->leftJoin('locations as l', 'l.id', '=', 'idl.location_id')
            ->where('t.client_id', $inventoryCount->client_id)
            ->where('e.warehouse_id', $inventoryCount->warehouse_id)
            ->where('t.trade_category', 'EARNING')
            ->where('t.is_active', true)
            ->where('t.is_latest', true)
            ->where('e.is_active', true)
            ->where('ti.is_active', true)
            ->whereRaw('COALESCE(e.delivered_date, t.process_date) = ?', [$reportDate])
            ->groupBy(
                'i.id', 'i.code', 'i.name', 'i.capacity_case',
                'l.code1', 'l.code2', 'l.code3',
            )
            ->selectRaw("i.id AS item_id, i.code AS item_code, i.name AS item_name, i.capacity_case, l.code1, l.code2, l.code3, SUM(CASE WHEN {$returnCondition} THEN -ABS({$pieceQty}) ELSE ABS({$pieceQty}) END) AS ordered_quantity")
            ->havingRaw('ordered_quantity != 0')
            ->orderBy('i.code')
            ->get();

        $stocks = DB::connection('sakemaru')
            ->table('real_stocks')
            ->where('client_id', $inventoryCount->client_id)
            ->where('warehouse_id', $inventoryCount->warehouse_id)
            ->whereIn('item_id', $orders->pluck('item_id'))
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(COALESCE(current_quantity, 0)) AS current_quantity, SUM(COALESCE(available_quantity, 0)) AS available_quantity')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->item_id);

        $orders->each(function (object $order) use ($stocks): void {
            $stock = $stocks->get((int) $order->item_id);
            $order->current_quantity = $stock?->current_quantity ?? 0;
            $order->available_quantity = $stock?->available_quantity ?? 0;
        });

        return $orders->keyBy(fn (object $row): int => (int) $row->item_id);
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['name' => 'Arial Unicode MS', 'bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9D9D9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function styleGrid(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['name' => 'Arial Unicode MS'],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']],
            ],
        ]);
    }

    private function shelfPrefix(WmsInventoryCountItem $item): string
    {
        $locationNo = InventoryCountLocationResolver::locationNo($item);

        return $locationNo === InventoryCountLocationResolver::NO_LOCATION_LABEL
            ? $locationNo
            : mb_substr($locationNo, 0, 2);
    }

    private function majorCategory(WmsInventoryCountItem $item): ?ItemCategory
    {
        $category = $item->item?->item_category1;

        return $category !== null && (int) ($category->depth ?? 0) === 1 ? $category : null;
    }

    private function majorCategoryCode(WmsInventoryCountItem $item): string
    {
        return (string) ($this->majorCategory($item)?->code ?? '');
    }

    private function majorCategoryName(WmsInventoryCountItem $item): string
    {
        return (string) ($this->majorCategory($item)?->name ?? '');
    }

    private function middleCategory(WmsInventoryCountItem $item): ?ItemCategory
    {
        $category = $item->item?->item_category2;

        return $category !== null && (int) ($category->depth ?? 0) === 2 ? $category : null;
    }

    private function middleCategoryCode(WmsInventoryCountItem $item): string
    {
        return (string) ($this->middleCategory($item)?->code ?? '');
    }

    private function middleCategoryName(WmsInventoryCountItem $item): string
    {
        return (string) ($this->middleCategory($item)?->name ?? '');
    }
}
