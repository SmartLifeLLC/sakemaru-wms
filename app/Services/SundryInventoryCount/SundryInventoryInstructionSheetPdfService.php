<?php

namespace App\Services\SundryInventoryCount;

use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountAmount;
use App\Models\WmsSundryInventoryCountItem;
use Illuminate\Support\Collection;
use TCPDF;

/**
 * 棚卸し（雑貨）指示書PDF。
 *
 * 中分類ごとに改ページし、実棚数を手書きする欄を出す。
 * 在庫管理なし（金額）は、最後に中分類ごとの実棚金額の記入欄をまとめて出す。
 */
class SundryInventoryInstructionSheetPdfService
{
    private const FONT = 'kozgopromedium';

    private const MARGIN_LEFT = 10;

    private const MARGIN_TOP = 8;

    private const MARGIN_RIGHT = 10;

    private const MARGIN_BOTTOM = 12;

    private const PAGE_WIDTH = 210;

    private const PAGE_HEIGHT = 297;

    private const CONTENT_WIDTH = 190;

    private const ROW_HEIGHT = 9;

    private const LINE_HEIGHT = 4.2;

    private const COL_W_CODE = 24;

    private const COL_W_NAME = 104;

    private const COL_W_SYSTEM = 24;

    private const COL_W_ACTUAL = 38;

    /** 金額ページの列幅（大分類 / 中分類CD / 中分類名 / 実棚金額） */
    private const AMOUNT_COL_WIDTHS = [34, 22, 74, 60];

    private TCPDF $pdf;

    private float $currentY = 0;

    private bool $showSystemQuantity = true;

    /**
     * @param  array<int, string>|null  $categoryCodes  出力する中分類コード（null は全て）
     */
    public function generate(
        WmsSundryInventoryCount $count,
        ?array $categoryCodes = null,
        bool $showSystemQuantity = true,
        bool $includeAmountPage = true,
    ): string {
        $this->showSystemQuantity = $showSystemQuantity;
        $categoryCodes = $categoryCodes === null ? null : array_values(array_filter(array_map('strval', $categoryCodes), fn (string $code): bool => $code !== ''));

        $items = WmsSundryInventoryCountItem::query()
            ->where('sundry_inventory_count_id', $count->id)
            ->when($categoryCodes !== null && $categoryCodes !== [], fn ($query) => $query->whereIn('category2_code', $categoryCodes))
            ->orderBy('category2_code')
            ->orderBy('item_code')
            ->orderBy('id')
            ->get();

        $amounts = $includeAmountPage
            ? WmsSundryInventoryCountAmount::query()
                ->where('sundry_inventory_count_id', $count->id)
                ->orderBy('category1_code')
                ->orderBy('category2_code')
                ->orderBy('id')
                ->get()
            : collect();

        $this->initPdf();
        $header = [
            'count_date' => $count->count_date?->format('Y/m/d') ?? '',
            'warehouse_name' => trim(($count->warehouse_code ?? '').' '.($count->warehouse_name ?? '')),
            'count_no' => $count->count_no ?? '',
        ];

        foreach ($items->groupBy('category2_code') as $group) {
            $this->renderQuantityPages($header, $group);
        }

        if ($amounts->isNotEmpty()) {
            $this->renderAmountPage($header, $amounts);
        }

        if ($this->pdf->getNumPages() === 0) {
            $this->addPage($header, null);
            $this->pdf->SetFont(self::FONT, '', 12);
            $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY + 10);
            $this->pdf->Cell(self::CONTENT_WIDTH, 10, '対象データなし', 0, 0, 'C');
        }

        $this->renderPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    /**
     * 指示書の中分類選択肢。
     *
     * @return array<string, string> 中分類コード => "[code]name"
     */
    public function categoryOptions(WmsSundryInventoryCount $count): array
    {
        $options = [];

        $rows = WmsSundryInventoryCountItem::query()
            ->where('sundry_inventory_count_id', $count->id)
            ->distinct()
            ->get(['category2_code', 'category2_name']);

        foreach ($rows as $row) {
            $code = (string) $row->category2_code;
            if ($code === '') {
                continue;
            }

            $options[$code] = "[{$code}]".$row->category2_name;
        }

        ksort($options, SORT_NATURAL);

        return $options;
    }

    /**
     * @param  array<string, string>  $header
     * @param  Collection<int, WmsSundryInventoryCountItem>  $items
     */
    private function renderQuantityPages(array $header, Collection $items): void
    {
        $first = $items->first();
        $department = $this->departmentLabel((string) $first->category2_code, (string) $first->category2_name);

        $this->addPage($header, $department);
        $this->renderQuantityColumnHeaders();

        foreach ($items as $item) {
            $name = $this->itemDisplayName($item);
            $this->pdf->SetFont(self::FONT, '', 9);
            $lines = max(1, $this->pdf->getNumLines($name, self::COL_W_NAME - 2));
            $rowHeight = max(self::ROW_HEIGHT, $lines * self::LINE_HEIGHT + 2);

            if ($this->currentY + $rowHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
                $this->addPage($header, $department);
                $this->renderQuantityColumnHeaders();
            }

            $x = self::MARGIN_LEFT;
            $y = $this->currentY;

            $this->pdf->SetLineWidth(0.2);
            $this->pdf->Rect($x, $y, self::CONTENT_WIDTH, $rowHeight);

            $this->pdf->SetFont(self::FONT, '', 10);
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell(self::COL_W_CODE, $rowHeight, (string) $item->item_code, 0, 0, 'C');
            $x += self::COL_W_CODE;
            $this->pdf->Line($x, $y, $x, $y + $rowHeight);

            $this->pdf->SetFont(self::FONT, '', 9);
            $nameY = $y + max(1, ($rowHeight - $lines * self::LINE_HEIGHT) / 2);
            $this->pdf->MultiCell(self::COL_W_NAME - 2, self::LINE_HEIGHT, $name, 0, 'L', false, 0, $x + 1, $nameY);
            $x += self::COL_W_NAME;
            $this->pdf->Line($x, $y, $x, $y + $rowHeight);

            $this->pdf->SetFont(self::FONT, '', 10);
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell(
                self::COL_W_SYSTEM - 2,
                $rowHeight,
                $this->showSystemQuantity ? $this->formatQuantity((float) $item->system_quantity) : '',
                0,
                0,
                'R',
            );
            $x += self::COL_W_SYSTEM;
            $this->pdf->Line($x, $y, $x, $y + $rowHeight);

            $this->currentY = $y + $rowHeight;
        }
    }

    private function renderQuantityColumnHeaders(): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $height = 7;

        $this->pdf->SetFont(self::FONT, '', 9);
        $this->pdf->SetLineWidth(0.2);
        $this->pdf->SetFillColor(235, 235, 235);

        foreach ([
            ['商品CD', self::COL_W_CODE],
            ['商品名', self::COL_W_NAME],
            ['理論数', self::COL_W_SYSTEM],
            ['実棚数', self::COL_W_ACTUAL],
        ] as [$label, $width]) {
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($width, $height, $label, 1, 0, 'C', true);
            $x += $width;
        }

        $this->currentY = $y + $height;
    }

    /**
     * @param  array<string, string>  $header
     * @param  Collection<int, WmsSundryInventoryCountAmount>  $amounts
     */
    private function renderAmountPage(array $header, Collection $amounts): void
    {
        $title = '在庫管理なし（金額で棚卸し）';
        $this->addPage($header, $title);
        $this->renderAmountIntro();
        $this->renderAmountColumnHeaders();

        $rowHeight = 12;

        foreach ($amounts as $amount) {
            if ($this->currentY + $rowHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
                $this->addPage($header, $title);
                $this->renderAmountColumnHeaders();
            }

            $x = self::MARGIN_LEFT;
            $y = $this->currentY;

            $this->pdf->SetLineWidth(0.2);
            $this->pdf->SetFont(self::FONT, '', 9);
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell(self::AMOUNT_COL_WIDTHS[0], $rowHeight, $this->shorten((string) $amount->category1_name, self::AMOUNT_COL_WIDTHS[0] - 2), 1, 0, 'L');
            $x += self::AMOUNT_COL_WIDTHS[0];

            $this->pdf->SetFont(self::FONT, '', 11);
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell(self::AMOUNT_COL_WIDTHS[1], $rowHeight, (string) $amount->category2_code, 1, 0, 'C');
            $x += self::AMOUNT_COL_WIDTHS[1];

            $name = (string) $amount->category2_name.($amount->is_additional ? '（追加）' : '');
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell(self::AMOUNT_COL_WIDTHS[2], $rowHeight, $this->shorten($name, self::AMOUNT_COL_WIDTHS[2] - 2), 1, 0, 'L');
            $x += self::AMOUNT_COL_WIDTHS[2];

            $this->pdf->Rect($x, $y, self::AMOUNT_COL_WIDTHS[3], $rowHeight);
            $this->currentY = $y + $rowHeight;
        }
    }

    private function renderAmountIntro(): void
    {
        $this->pdf->SetFont(self::FONT, '', 9);
        $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
        $this->pdf->MultiCell(
            self::CONTENT_WIDTH,
            self::LINE_HEIGHT,
            '在庫管理していない商品は、中分類ごとに実棚の金額（原価）を合計して記入してください。明細は別紙のリストを添付してください。',
            0,
            'L',
        );
        $this->currentY = $this->pdf->GetY() + 2;
    }

    private function renderAmountColumnHeaders(): void
    {
        $x = self::MARGIN_LEFT;
        $this->pdf->SetFont(self::FONT, '', 9);
        $this->pdf->SetLineWidth(0.2);
        $this->pdf->SetFillColor(235, 235, 235);

        foreach (['大分類', '中分類CD', '中分類名', '実棚金額（円）'] as $index => $label) {
            $this->pdf->SetXY($x, $this->currentY);
            $this->pdf->Cell(self::AMOUNT_COL_WIDTHS[$index], 7, $label, 1, 0, 'C', true);
            $x += self::AMOUNT_COL_WIDTHS[$index];
        }

        $this->currentY += 7;
    }

    /**
     * セル幅に収まるよう末尾を省略する。
     */
    private function shorten(string $text, float $width): string
    {
        if ($this->pdf->GetStringWidth($text) <= $width) {
            return $text;
        }

        while ($text !== '' && $this->pdf->GetStringWidth($text.'…') > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return $text.'…';
    }

    private function initPdf(): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('Smart WMS');
        $this->pdf->SetAuthor('Smart WMS');
        $this->pdf->SetTitle('棚卸し指示書（雑貨）');
        $this->pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetFont(self::FONT, '', 9);
    }

    /**
     * @param  array<string, string>  $header
     */
    private function addPage(array $header, ?string $department): void
    {
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN_TOP;

        $this->pdf->SetFont(self::FONT, 'B', 16);
        $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
        $this->pdf->Cell(110, 8, '棚卸し指示書（雑貨）', 0, 0, 'L');

        $this->pdf->SetFont(self::FONT, '', 8);
        $this->pdf->SetXY(self::MARGIN_LEFT + self::CONTENT_WIDTH - 60, $this->currentY);
        $this->pdf->Cell(60, 4, now()->format('Y/m/d H:i:s'), 0, 0, 'R');

        $this->pdf->SetFont(self::FONT, '', 10);
        $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY + 9);
        $this->pdf->Cell(40, 5, '棚卸日 '.$header['count_date'], 0, 0, 'L');
        $this->pdf->SetXY(self::MARGIN_LEFT + 42, $this->currentY + 9);
        $this->pdf->Cell(60, 5, $header['warehouse_name'], 0, 0, 'L');
        $this->pdf->SetXY(self::MARGIN_LEFT + 104, $this->currentY + 9);
        $this->pdf->Cell(86, 5, $department !== null ? '部門: '.$department : '', 0, 0, 'L');

        $this->pdf->SetFont(self::FONT, '', 9);
        $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY + 15);
        $this->pdf->Cell(100, 5, '担当者:                              ', 0, 0, 'L');
        $this->pdf->Line(self::MARGIN_LEFT + 14, $this->currentY + 20, self::MARGIN_LEFT + 70, $this->currentY + 20);

        $this->currentY += 23;
    }

    private function renderPageNumbers(): void
    {
        $total = $this->pdf->getNumPages();
        $this->pdf->SetFont(self::FONT, '', 9);

        for ($page = 1; $page <= $total; $page++) {
            $this->pdf->setPage($page);
            $this->pdf->SetXY(self::PAGE_WIDTH - self::MARGIN_RIGHT - 30, self::MARGIN_TOP + 4);
            $this->pdf->Cell(30, 5, "{$page} ／ {$total}", 0, 0, 'R');
        }
    }

    private function departmentLabel(string $code, string $name): string
    {
        if ($code === '') {
            return '中分類なし';
        }

        return "[{$code}] {$name}";
    }

    private function itemDisplayName(WmsSundryInventoryCountItem $item): string
    {
        return trim((string) $item->item_name).($item->is_additional ? '（追加）' : '');
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3), '0'), '.');
    }
}
