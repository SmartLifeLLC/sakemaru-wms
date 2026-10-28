<?php

namespace App\Services\Distribution;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;

/** Parse spreadsheet data only. Never evaluate formulas, macros or external links. */
class DistributionExcelReader
{
    private const ALIASES = [
        1 => ['本店'], 2 => ['二の宮', '二宮', '二の宮店'], 3 => ['坂井', '坂井店'],
        4 => ['SD', 'SD前', 'サンドム', 'サンドーム前', 'サンドーム前店'],
        7 => ['光陽', '光陽店'], 8 => ['プラ', 'プラザ', 'プラザ店'],
        9 => ['ビオ', 'ヴィオ', 'ビオ店'], 10 => ['敦賀', '敦賀店'],
        11 => ['越前', '越前店'], 21 => ['江守', '江守店'], 22 => ['小浜', '小浜店'],
    ];

    public static function normalize(mixed $value): string
    {
        $text = $value instanceof RichText ? $value->getPlainText() : (string) ($value ?? '');

        $text = \Normalizer::normalize($text, \Normalizer::FORM_KC);

        return mb_strtoupper(preg_replace('/[\s\x{3000}]+/u', '', mb_convert_kana($text, 'asKV', 'UTF-8')));
    }

    public static function quantity(mixed $value): ?int
    {
        $value = trim((string) \Normalizer::normalize((string) ($value ?? ''), \Normalizer::FORM_KC));
        if ($value === '') {
            return 0;
        }
        if (! preg_match('/^(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.0+)?$/D', $value)) {
            return null;
        }
        $number = (float) str_replace(',', '', $value);

        return $number <= 99999999 && $number >= 0 ? (int) $number : null;
    }

    public function parse(string $path, array $items, array $destinations, array $settings): array
    {
        if (filesize($path) > 20 * 1024 * 1024) {
            throw new RuntimeException('Excelは20MiB以下のファイルを選択してください。');
        }
        $type = IOFactory::identify($path);
        if (! in_array($type, ['Xls', 'Xlsx'], true)) {
            throw new RuntimeException('xlsまたはxlsx形式のExcelを選択してください。');
        }
        $reader = IOFactory::createReader($type);
        $names = $reader->listWorksheetNames($path);
        $info = $reader->listWorksheetInfo($path)[0] ?? [];
        if (($info['totalRows'] ?? 0) > 10000 || ($info['totalColumns'] ?? 0) > 256) {
            throw new RuntimeException('Excelは10,000行・256列以内に分けて取り込んでください。');
        }
        $reader->setLoadSheetsOnly([$names[0]]);
        $reader->setReadEmptyCells(false);
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheet(0);
            if ($sheet->getSheetState() !== 'visible') {
                throw new RuntimeException('先頭シートを表示してから、再アップロードしてください。');
            }
            $maxRow = $sheet->getHighestDataRow();
            $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            if ($maxRow > 10000 || $maxCol > 256) {
                throw new RuntimeException('Excelは10,000行・256列以内に分けて取り込んでください。');
            }
            $result = ['sheet_name' => $sheet->getTitle(), 'header_row' => null, 'columns' => [],
                'rows' => [], 'skipped' => [], 'errors' => [], 'warnings' => [], 'excel_total' => 0];
            $codeColumn = null;
            for ($r = 1; $r <= $maxRow; $r++) {
                $matches = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    if ($sheet->cellExists([$c, $r]) && in_array(self::normalize($sheet->getCell([$c, $r])->getValue()), ['単品CD', '自社CD'], true)) {
                        $matches[] = $c;
                    }
                }
                if ($matches !== []) {
                    if (count($matches) !== 1) {
                        throw new RuntimeException("{$r}行目に商品CDの見出しが複数あります。1列に整理してください。");
                    }
                    $codeColumn = $matches[0];
                    $result['header_row'] = $r;
                    break;
                }
            }
            if (! $codeColumn) {
                throw new RuntimeException('先頭シートに「単品CD」または「自社CD」の見出しがありません。');
            }
            $byCode = [];
            $byName = [];
            foreach ($destinations as $d) {
                $code = (int) $d['code'];
                $byCode[$code] = $d;
                foreach (array_merge([$d['name']], self::ALIASES[$code] ?? []) as $alias) {
                    $byName[self::normalize($alias)][] = $code;
                }
            }
            $ignored = array_map(self::normalize(...), ['表示正式名称', '商品名', '容量', '入数', '原価', '単価', '税抜', '率', 'コメント', 'コメント１', '賞味', 'ロット', '仕入先', '単位', '納品', 'ケース', '総合計', '計', 'ケース換算', '備考']);
            $seenDestinations = [];
            $storeColumns = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                if ($c === $codeColumn) {
                    continue;
                }
                $letter = Coordinate::stringFromColumnIndex($c);
                $header = (string) $sheet->getCell([$c, $result['header_row']])->getValue();
                $key = self::normalize($header);
                $override = $settings['mapping'][$letter] ?? null;
                $codes = $settings['store_mode'] === 'code'
                    ? (preg_match('/^\d+(?:\.0+)?$/D', $key) ? [(int) $key] : [])
                    : array_unique($byName[$key] ?? []);
                $code = $override !== null && $override !== 'ignore' ? (int) $override : (count($codes) === 1 ? (int) reset($codes) : null);
                $destination = $code !== null ? ($byCode[$code] ?? null) : null;
                $hasContent = false;
                for ($r = $result['header_row'] + 1; $r <= $maxRow; $r++) {
                    if ($sheet->cellExists([$c, $r]) && $sheet->getCell([$c, $r])->getValue() !== null) {
                        $hasContent = true;
                        break;
                    }
                }
                $column = ['column' => $letter, 'header' => $header, 'destination_code' => null, 'status' => 'ignored', 'reason' => '参考・集計列'];
                if ($override === 'ignore' || ($override === null && in_array($key, $ignored, true))) {
                    $result['columns'][] = $column;

                    continue;
                }
                if ($destination && ! empty($destination['allowed'])) {
                    if (isset($seenDestinations[$code])) {
                        $result['errors'][] = "{$letter}列と{$seenDestinations[$code]}列が同じ店舗へ対応しています。";
                    }
                    $seenDestinations[$code] = $letter;
                    $column = array_replace($column, ['destination_code' => $code, 'status' => 'matched', 'reason' => $destination['name']]);
                    $storeColumns[$c] = $destination;
                } elseif ($destination) {
                    $column['reason'] = '出庫元または対象外の倉庫';
                    for ($r = $result['header_row'] + 1; $r <= $maxRow; $r++) {
                        if (self::quantity($this->value($sheet->getCell([$c, $r]), $result)) !== 0) {
                            $result['errors'][] = "{$letter}{$r}：対象外の倉庫に数量があります。内容を確認してください。";
                        }
                    }
                    $result['warnings'][] = "{$letter}列（{$header}）は出庫元または対象外のため除外します。";
                } elseif ($key !== '' || $hasContent) {
                    $column['status'] = 'unresolved';
                    $column['reason'] = '店舗を選択するか、対象外を指定してください';
                    $result['errors'][] = "{$letter}列（{$header}）の対応を確認してください。";
                }
                $result['columns'][] = $column;
            }
            if ($storeColumns === []) {
                $result['errors'][] = "先頭シート『{$sheet->getTitle()}』に店舗列がありません。数量を入力したシートを先頭に移して再アップロードしてください。";

                return $result;
            }
            $seenItems = [];
            $hiddenRows = 0;
            foreach (range($result['header_row'] + 1, max($result['header_row'] + 1, $maxRow)) as $r) {
                $cell = $sheet->getCell([$codeColumn, $r]);
                $raw = trim((string) \Normalizer::normalize((string) ($cell->getValue() ?? ''), \Normalizer::FORM_KC));
                $repeatedHeaders = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    if ($sheet->cellExists([$c, $r]) && in_array(self::normalize($sheet->getCell([$c, $r])->getValue()), ['単品CD', '自社CD'], true)) {
                        $repeatedHeaders[] = $c;
                    }
                }
                if ($repeatedHeaders !== []) {
                    $sameLayout = $repeatedHeaders === [$codeColumn];
                    foreach ($storeColumns as $c => $destination) {
                        $sameLayout = $sameLayout && self::normalize($sheet->getCell([$c, $r])->getValue()) === self::normalize($sheet->getCell([$c, $result['header_row']])->getValue());
                    }
                    if (! $sameLayout) {
                        $result['errors'][] = "{$r}行目：途中の見出しの列構成が変わっています。店舗列を統一してください。";
                    }

                    continue;
                }
                $numericCode = preg_match('/^\d+(?:\.0+)?$/D', $raw) && (int) $raw > 0 ? (string) (int) $raw : '';
                $item = $items[$numericCode] ?? null;
                if (! $item || ! $item['is_active'] || Date::isDateTime($cell) || in_array($cell->getDataType(), [DataType::TYPE_BOOL, DataType::TYPE_ERROR, DataType::TYPE_FORMULA], true)) {
                    $result['skipped'][] = ['row' => $r, 'code' => $raw, 'reason' => $raw === '' ? '商品CDなし' : '商品CDが不正、未登録または無効'];

                    continue;
                }
                if (isset($seenItems[$item['id']])) {
                    $result['errors'][] = "{$r}行目：商品CD{$numericCode}は{$seenItems[$item['id']]}行目と重複しています。";
                }
                $seenItems[$item['id']] = $r;
                if (! $sheet->getRowDimension($r)->getVisible()) {
                    $hiddenRows++;
                }
                $row = ['excel_row' => $r, 'item_id' => (int) $item['id'], 'product_code' => (string) $item['code'],
                    'name' => $item['name'], 'units_per_case' => (int) $item['capacity_case'], 'allocations' => [], 'total' => 0];
                for ($c = 1; $c <= $maxCol; $c++) {
                    $header = self::normalize($sheet->getCell([$c, $result['header_row']])->getValue());
                    if ($header === '入数') {
                        $excelCapacity = self::quantity($this->value($sheet->getCell([$c, $r]), $result));
                        if ($excelCapacity !== null && $excelCapacity !== (int) $item['capacity_case']) {
                            $result['warnings'][] = "{$r}行目 商品CD{$numericCode}：Excel入数{$excelCapacity}／マスタ入数{$item['capacity_case']}。ケース換算はマスタ入数を使用します。";
                        }
                    }
                    if ($header === '納品') {
                        $row['delivery_note'] = (string) $sheet->getCell([$c, $r])->getValue();
                        if (str_contains($row['delivery_note'], '直送')) {
                            $result['warnings'][] = "{$r}行目 商品CD{$numericCode}：納品欄は{$row['delivery_note']}です。本部分配への取込対象か確認してください。";
                        }
                    }
                }
                foreach ($storeColumns as $c => $d) {
                    $cell = $sheet->getCell([$c, $r]);
                    $q = self::quantity($this->value($cell, $result));
                    if ($cell->isInMergeRange()) {
                        $result['errors'][] = "{$cell->getCoordinate()}：店舗数量の結合を解除してください。";
                    }
                    if ($q === null || ($settings['quantity_unit'] === 'case' && $item['capacity_case'] <= 0)) {
                        $result['errors'][] = "{$cell->getCoordinate()}：分配数は0以上の整数、ケース入数は1以上で入力してください。";

                        continue;
                    }
                    $q *= $settings['quantity_unit'] === 'case' ? (int) $item['capacity_case'] : 1;
                    if ($q > 99999999) {
                        $result['errors'][] = "{$cell->getCoordinate()}：換算後の分配数が99,999,999を超えています。";
                    }
                    $row['allocations'][str_pad((string) $d['code'], 2, '0', STR_PAD_LEFT)] = $q;
                    $row['total'] += $q;
                }
                if ($row['total'] > 99999999) {
                    $result['errors'][] = "{$r}行目：分配合計が99,999,999を超えています。";
                }
                for ($c = 1; $c <= $maxCol; $c++) {
                    if (self::normalize($sheet->getCell([$c, $result['header_row']])->getValue()) !== '総合計') {
                        continue;
                    }
                    $totalCell = $sheet->getCell([$c, $r]);
                    $totalValue = $totalCell->getDataType() === DataType::TYPE_FORMULA ? $totalCell->getOldCalculatedValue() : $totalCell->getValue();
                    $excelTotal = $totalValue !== null && $totalValue !== '' ? self::quantity($totalValue) : null;
                    if ($excelTotal !== null && $excelTotal !== $row['total']) {
                        $result['warnings'][] = "{$r}行目 商品CD{$numericCode}：Excel総合計{$excelTotal}／取込分配合計{$row['total']}。店舗別数量から再計算した分配合計を使用します。";
                    }
                }
                $result['rows'][] = $row;
                $result['excel_total'] += $row['total'];
            }
            if (count($result['rows']) > 6000) {
                $result['errors'][] = '商品を6,000行以内に分けて取り込んでください。';
            }
            if ($hiddenRows > 0) {
                $result['warnings'][] = "非表示の商品行{$hiddenRows}件も取り込み対象です。";
            }
            if ($sheet->getAutoFilter()->getRange() !== '') {
                $result['warnings'][] = 'フィルターで非表示の行も取り込み対象です。';
            }
            $result['warnings'] = array_values(array_unique($result['warnings']));
            $result['errors'] = array_values(array_unique($result['errors']));

            return $result;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function value($cell, array &$result): mixed
    {
        if (Date::isDateTime($cell) || $cell->getDataType() === DataType::TYPE_BOOL) {
            $result['errors'][] = "{$cell->getCoordinate()}：日付や真偽値ではなく、数量を入力してください。";

            return '#ERROR';
        }
        if ($cell->getDataType() === DataType::TYPE_ERROR) {
            $result['errors'][] = "{$cell->getCoordinate()}：Excelのエラー値を修正してください。";

            return '#ERROR';
        }
        if ($cell->getDataType() !== DataType::TYPE_FORMULA) {
            return $cell->getValue();
        }
        $value = $cell->getOldCalculatedValue();
        if ($value === null || str_contains((string) $cell->getValue(), '[') || (! is_numeric($value) && $value !== '')) {
            $result['errors'][] = "{$cell->getCoordinate()}：数式の結果を確認できません。外部参照を除き、再計算して保存してください。";

            return '#ERROR';
        }

        return $value;
    }
}
