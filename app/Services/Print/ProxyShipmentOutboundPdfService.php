<?php

namespace App\Services\Print;

use RuntimeException;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;
use Smalot\PdfParser\Parser;

class ProxyShipmentOutboundPdfService
{
    /** Validate the existing core contract before importing its outbound pages. */
    public function extract(string $pdf, array $expectedSerials): string
    {
        $pages = (new Parser)->parseContent($pdf)->getPages();
        $count = count($pages);
        if (! $expectedSerials || $count < count($expectedSerials) * 2 || $count % 2 !== 0) {
            throw new RuntimeException('Unexpected transfer PDF page count');
        }
        $half = intdiv($count, 2);
        $serials = [];
        foreach ($pages as $index => $page) {
            $text = preg_replace('/\s+/u', '', $page->getText());
            if (! preg_match('/転送伝票[（(](入庫用|出庫用)[）)]/u', $text, $type)
                || $type[1] !== ($index < $half ? '入庫用' : '出庫用')
                || ! preg_match('/識別ID[:：]([0-9]+)/u', $text, $serial)) {
                throw new RuntimeException('Unexpected transfer PDF page header');
            }
            $serials[] = $serial[1];
        }
        $inbound = array_slice($serials, 0, $half);
        $outbound = array_slice($serials, $half);
        $groups = [];
        foreach ($inbound as $serial) {
            if (! $groups || end($groups) !== $serial) {
                $groups[] = $serial;
            }
        }
        if ($inbound !== $outbound || $groups !== array_map('strval', $expectedSerials)) {
            throw new RuntimeException('Transfer PDF does not match selected documents');
        }
        $result = new class extends Fpdi
        {
            public function __construct()
            {
                parent::__construct();
                $this->tcpdflink = false;
            }
        };
        $result->setPrintHeader(false);
        $result->setPrintFooter(false);
        $result->SetAutoPageBreak(false);
        if ($result->setSourceFile(StreamReader::createByString($pdf)) !== $count) {
            throw new RuntimeException('Transfer PDF parsers disagree');
        }
        for ($number = $half + 1; $number <= $count; $number++) {
            $template = $result->importPage($number);
            $size = $result->getTemplateSize($template);
            $result->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $result->useTemplate($template, 0, 0, $size['width'], $size['height']);
        }

        return $result->Output('', 'S');
    }
}
