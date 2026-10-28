<?php

namespace App\Services\Distribution;

use TCPDF;

/** Distribution quantities only. Printing never creates stock transfers or reserves inventory. */
class DistributionMatrixPdfService
{
    public function render(string $name, array $rows, array $destinations, string $unit): string
    {
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle('分配作業書 '.$name);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);
        $pages = [];
        foreach (array_chunk($destinations, 11) as $columns) {
            foreach (array_chunk($rows, 12) as $items) {
                $pages[] = [$columns, $items];
            }
        }
        foreach ($pages as $page => [$columns, $items]) {
            $pdf->AddPage();
            $pdf->SetFont('kozminproregular', '', 13);
            $pdf->MultiCell(230, 10, '分配作業書：'.$name, 0, 'L', false, 1, 10, 9, true, 0, false, true, 10, 'M', true);
            $pdf->SetFont('kozminproregular', '', 9);
            $pdf->Text(10, 21, '数量単位：'.($unit === 'case' ? 'ケース（端数はバラ）' : 'バラ').'　／　分配数量');
            $pdf->Text(255, 21, ($page + 1).' / '.count($pages).'頁');
            $widths = [22, 76, 13, 18, ...array_fill(0, count($columns), 148 / max(1, count($columns)))];
            $headers = ['商品CD', '商品名', '入数', '合計', ...array_map(fn ($d) => $d['code']."\n".$d['name'], $columns)];
            $this->line($pdf, $headers, $widths, 29, 14, true);
            $y = 43;
            foreach ($items as $item) {
                $capacity = max(1, (int) ($item['unitsPerCase'] ?? 1));
                $quantities = array_map(fn ($d) => (int) ($item['alloc_'.str_pad((string) $d['code'], 2, '0', STR_PAD_LEFT)] ?? 0), $columns);
                $total = array_sum(array_filter($item, fn ($k) => str_starts_with((string) $k, 'alloc_'), ARRAY_FILTER_USE_KEY));
                $format = fn ($q) => $unit === 'case' ? (string) intdiv($q, $capacity).($q % $capacity ? "\n".($q % $capacity).'バラ' : '') : number_format($q);
                $cells = [(string) ($item['productCode'] ?? ''), (string) ($item['name'] ?? ''), (string) $capacity,
                    $format($total), ...array_map($format, $quantities)];
                $this->line($pdf, $cells, $widths, $y, 12, false);
                $y += 12;
            }
            $pdf->SetFont('kozminproregular', '', 8);
            $pdf->Text(10, 194, '合計は全店舗・倉庫の分配数。店舗が多い場合は横方向にもページを分けています。');
        }

        return $pdf->Output('', 'S');
    }

    private function line(TCPDF $pdf, array $cells, array $widths, float $y, float $height, bool $header): void
    {
        $x = 10;
        $pdf->SetFillColor($header ? 225 : 255);
        foreach ($cells as $index => $text) {
            $pdf->SetFont('kozminproregular', '', $header ? 8 : ($index >= 3 && ! str_contains($text, "\n") ? 11 : 9));
            $pdf->MultiCell($widths[$index], $height, $text, 1, $index === 1 && ! $header ? 'L' : 'C', true, 0,
                $x, $y, true, 0, false, true, $height, 'M', true);
            $x += $widths[$index];
        }
    }
}
