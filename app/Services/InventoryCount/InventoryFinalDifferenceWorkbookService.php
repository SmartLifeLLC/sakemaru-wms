<?php

namespace App\Services\InventoryCount;

use App\Models\WmsInventoryCount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class InventoryFinalDifferenceWorkbookService
{
    public function generate(WmsInventoryCount $count): string
    {
        $count = $count->fresh();
        if ($count->status !== WmsInventoryCount::STATUS_CONFIRMED || ! in_array($count->inventory_adjustment_count_round, [2, 3], true)) {
            $this->invalid('棚卸し最終確定後に出力できます。');
        }

        $rows = $this->rows($count);
        $book = new Spreadsheet;
        $path = tempnam(sys_get_temp_dir(), 'wms-final-difference-');
        if ($path === false) {
            throw new RuntimeException('一時ファイルを作成できません。');
        }

        try {
            $sheet = $book->getActiveSheet()->setTitle('最終差異報告書');
            $book->getDefaultStyle()->getFont()->setName('Yu Gothic')->setSize(10);
            $metadata = [
                '最終差異報告書',
                "[{$count->warehouse_code}] {$count->warehouse_name} / {$count->count_no}",
                "棚卸日: {$count->count_date->toDateString()} / 伝票日付: {$count->inventory_adjustment_date?->toDateString()} / {$count->inventory_adjustment_count_round}回目確定",
                '最終確定日時: '.$count->confirmed_at?->format('Y-m-d H:i:s'),
            ];
            foreach ($metadata as $i => $text) {
                $sheet->mergeCells('A'.($i + 1).':E'.($i + 1));
                $sheet->setCellValueExplicit('A'.($i + 1), $text, DataType::TYPE_STRING);
            }
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getRowDimension(1)->setRowHeight(28);
            $sheet->fromArray(['商品CD', '商品名', '理論在庫', '実棚数', '終了差異金額'], null, 'A5');
            $line = 6;
            foreach ($rows as $row) {
                $sheet->setCellValueExplicit('A'.$line, $row['code'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B'.$line, $row['name'], DataType::TYPE_STRING);
                $sheet->setCellValue('C'.$line, $row['theory']);
                $sheet->setCellValue('D'.$line, $row['actual']);
                $sheet->setCellValue('E'.$line, $row['amount']);
                $sheet->getRowDimension($line)->setRowHeight(max(32, ceil(mb_strwidth($row['name']) / 60) * 15 + 8));
                $line++;
            }
            $sheet->setCellValue('A'.$line, '合計');
            foreach (['C', 'D', 'E'] as $column) {
                $sheet->setCellValue($column.$line, $rows === [] ? 0 : '=SUM('.$column.'6:'.$column.($line - 1).')');
            }
            foreach (['A' => 14, 'B' => 64, 'C' => 14, 'D' => 14, 'E' => 22] as $column => $width) {
                $sheet->getColumnDimension($column)->setWidth($width);
            }
            $sheet->getStyle("B6:B{$line}")->getAlignment()->setWrapText(true);
            $sheet->getStyle("A5:E{$line}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("C6:D{$line}")->getNumberFormat()->setFormatCode('#,##0;[Red]-#,##0;0');
            $sheet->getStyle("E6:E{$line}")->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00;0.00');
            foreach (['A5:E5', "A{$line}:E{$line}"] as $range) {
                $sheet->getStyle($range)->getFont()->setBold(true);
                $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5EEF8');
            }
            $sheet->freezePane('C6');
            $sheet->setAutoFilter('A5:E'.max(5, $line - 1));
            $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
                ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 5)->setPrintArea("A1:E{$line}");
            $sheet->getHeaderFooter()->setOddFooter('&R&P / &N');
            (new Xlsx($book))->save($path);

            return (string) file_get_contents($path);
        } finally {
            $book->disconnectWorksheets();
            @unlink($path);
        }
    }

    private function rows(WmsInventoryCount $count): array
    {
        $queues = DB::connection('sakemaru')->table('inventory_adjustment_queue')
            ->where('wms_inventory_count_id', $count->id)->where('client_id', $count->client_id)->orderBy('id')->get(['id', 'items']);
        $ids = array_map('intval', $count->inventory_adjustment_queue_ids ?? []);
        sort($ids);
        if ($queues->pluck('id')->map(fn ($id) => (int) $id)->all() !== $ids || $queues->count() !== (int) $count->inventory_adjustment_queue_count) {
            $this->invalid('最終確定時の連携データが不足しています。連携状況を確認してください。');
        }

        $rows = [];
        foreach ($queues as $queue) {
            $items = json_decode($queue->items, true);
            if (! is_array($items) || $items === []) {
                $this->invalid('最終確定時の明細データを確認してください。');
            }
            foreach ($items as $item) {
                if (! is_array($item) || ! isset($item['item_id'], $item['item_code'], $item['item_name'], $item['amount'])
                    || isset($rows[$item['item_id']]) || ! is_numeric($item['amount'])
                    || ($item['count_round'] ?? null) !== $count->inventory_adjustment_count_round
                    || ! is_array($item['source_count_items'] ?? null) || $item['source_count_items'] === []) {
                    $this->invalid('最終確定時の元明細が不足または重複しています。');
                }
                $theory = 0;
                $actual = 0;
                foreach ($item['source_count_items'] as $source) {
                    $theory += $this->quantity($source['stock_quantity_before'] ?? null);
                    $actual += $this->quantity($source['stock_quantity_after'] ?? null);
                }
                if ($actual - $theory !== $this->quantity($item['inventory_adjustment_quantity'] ?? null)) {
                    $this->invalid('最終確定時の数量と差異が一致しません。');
                }
                $rows[$item['item_id']] = ['code' => (string) $item['item_code'], 'name' => (string) $item['item_name'],
                    'theory' => $theory, 'actual' => $actual, 'amount' => round((float) $item['amount'], 2)];
            }
        }
        usort($rows, fn ($a, $b) => strnatcmp($a['code'], $b['code']));

        return $rows;
    }

    private function quantity(mixed $value): int
    {
        if (! is_numeric($value) || (float) $value !== (float) (int) $value) {
            $this->invalid('最終確定時の数量が未設定または整数ではありません。');
        }

        return (int) $value;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['inventory_count' => $message]);
    }
}
