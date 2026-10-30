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
    private const CODE_HEADERS = ['単品CD', '自社CD', '自社コード', '商品CD', '商品コード', '華CD', 'CD', 'JAN', 'JANCD', 'JANコード'];

    private const STANDARD_STORE_CODES = [1, 2, 3, 4, 7, 8, 9, 10, 11, 21, 22];

    private const ALIASES = [
        1 => ['本店'], 2 => ['二の宮', '二宮', '二の宮店'], 3 => ['坂井', '坂井店'],
        4 => ['SD', 'SD前', 'サンドム', 'サンドーム', 'サンドーム前', 'サンドーム前店'],
        7 => ['光陽', '光陽店'], 8 => ['プラ', 'プラザ', 'プラザ店'],
        9 => ['ビオ', 'ヴィオ', 'ビオ店'], 10 => ['敦賀', '敦賀店'],
        11 => ['越前', '越前店'], 21 => ['江守', '江守店'], 22 => ['小浜', '小浜店'],
        71 => ['特販'], 91 => ['本部'],
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
                'rows' => [], 'skipped' => [], 'errors' => [], 'warnings' => [], 'duplicate_warnings' => [], 'excel_total' => 0];
            $codeColumn = null;
            $codeColumns = [];
            $itemsByJan = [];
            foreach ($items as $item) {
                foreach ($item['jan_codes'] ?? [] as $jan) {
                    $itemsByJan[self::normalize($jan)][$item['id']] = $item;
                }
            }
            for ($r = 1; $r <= $maxRow; $r++) {
                $matches = [];
                for ($c = 1; $c <= $maxCol; $c++) {
                    if ($sheet->cellExists([$c, $r]) && in_array(self::normalize($sheet->getCell([$c, $r])->getValue()), self::CODE_HEADERS, true)) {
                        $matches[] = $c;
                    }
                }
                if ($matches !== []) {
                    $codeColumns = $matches;
                    $codeColumn = $matches[0];
                    $result['header_row'] = $r;
                    break;
                }
            }
            if (! $codeColumn) {
                throw new RuntimeException('先頭シートに商品CDまたはJANの見出しがありません（単品CD・自社CD・自社コード・華CD・CD・JAN CD・JANコード）。');
            }
            $result['code_column'] = Coordinate::stringFromColumnIndex($codeColumn);
            $result['code_header'] = (string) $sheet->getCell([$codeColumn, $result['header_row']])->getValue();
            $byCode = [];
            $byName = [];
            foreach ($destinations as $d) {
                $code = (int) $d['code'];
                $byCode[$code] = $d;
                foreach (array_merge([$d['name']], self::ALIASES[$code] ?? []) as $alias) {
                    $byName[self::normalize($alias)][] = $code;
                }
            }
            // The import's 本部 label explicitly means warehouse CD 91, even if another master uses that name.
            $byName[self::normalize('本部')] = isset($byCode[91]) ? [91] : [];
            $totalHeaders = array_map(self::normalize(...), ['総合計', '合計', '計', 'ケース換算']);
            $ignored = array_merge($totalHeaders, array_map(self::normalize(...), ['表示正式名称', '商品名', '容量', '入数', '原価', '単価', '税抜', '率', 'コメント', 'コメント１', '賞味', 'ロット', '仕入', '仕入先', '単位', '納品', 'ケース', '備考']));
            $seenDestinations = [];
            $storeColumns = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                if (in_array($c, $codeColumns, true)) {
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
                $referenceOnly = $key === '';
                for ($r = $result['header_row'] + 1; $r <= $maxRow; $r++) {
                    if (! $sheet->cellExists([$c, $r])) {
                        continue;
                    }
                    $cell = $sheet->getCell([$c, $r]);
                    $value = $cell->getValue();
                    $value = $value instanceof RichText ? $value->getPlainText() : $value;
                    if ($value === null || (is_string($value) && trim($value) === '')) {
                        continue;
                    }
                    $hasContent = true;
                    // Headerless notes and case conversions of a named total are references.
                    // Other numeric/formula columns still require explicit review.
                    $totalConversion = $referenceOnly && is_string($value) && $cell->getDataType() === DataType::TYPE_FORMULA
                        && preg_match('/^=\$?([A-Z]{1,3})\$?(\d+)\s*\/\s*(\d+(?:\.\d+)?)$/iD', $value, $formula)
                        && (int) $formula[2] === $r && (float) $formula[3] > 0
                        && in_array(self::normalize($sheet->getCell($formula[1].$result['header_row'])->getValue()), $totalHeaders, true);
                    if (! $referenceOnly || ! is_string($value) || ($cell->getDataType() === DataType::TYPE_FORMULA && ! $totalConversion) || self::quantity($value) !== null) {
                        $referenceOnly = false;
                        break;
                    }
                }
                $column = ['column' => $letter, 'header' => $header, 'destination_code' => null, 'status' => 'ignored', 'reason' => '参考・集計列'];
                if ($override === 'ignore' || ($override === null && (in_array($key, $ignored, true) || ($key === '' && $referenceOnly)))) {
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
                }
                $result['columns'][] = $column;
            }
            $allStoresMatched = array_diff(self::STANDARD_STORE_CODES, array_keys($seenDestinations)) === [];
            foreach ($result['columns'] as &$column) {
                if ($column['status'] !== 'unresolved') {
                    continue;
                }
                // Once all eleven stores are identified, remaining automatic columns are references.
                // Explicit mappings and duplicate/invalid store quantities still require validation.
                if ($allStoresMatched && ! isset($settings['mapping'][$column['column']])) {
                    $column['status'] = 'ignored';
                    $column['reason'] = '店舗以外の参考列';
                } else {
                    $result['errors'][] = "{$column['column']}列（{$column['header']}）の対応を確認してください。";
                }
            }
            unset($column);
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
                    if ($sheet->cellExists([$c, $r]) && in_array(self::normalize($sheet->getCell([$c, $r])->getValue()), self::CODE_HEADERS, true)) {
                        $repeatedHeaders[] = $c;
                    }
                }
                if ($repeatedHeaders !== []) {
                    $sameLayout = $repeatedHeaders === $codeColumns;
                    foreach ($storeColumns as $c => $destination) {
                        $sameLayout = $sameLayout && self::normalize($sheet->getCell([$c, $r])->getValue()) === self::normalize($sheet->getCell([$c, $result['header_row']])->getValue());
                    }
                    if (! $sameLayout) {
                        $result['errors'][] = "{$r}行目：途中の見出しの列構成が変わっています。店舗列を統一してください。";
                    }

                    continue;
                }
                $matches = [];
                $rawCodes = [];
                foreach ($codeColumns as $column) {
                    // Reacquire the cell before inspecting date formatting (PhpSpreadsheet detaches cells).
                    $cell = $sheet->getCell([$column, $r]);
                    $rawCode = trim((string) \Normalizer::normalize((string) ($cell->getValue() ?? ''), \Normalizer::FORM_KC));
                    $rawCodes[] = $rawCode;
                    if (Date::isDateTime($cell) || in_array($cell->getDataType(), [DataType::TYPE_BOOL, DataType::TYPE_ERROR, DataType::TYPE_FORMULA], true)
                        || ! preg_match('/^\d+(?:\.0+)?$/D', $rawCode)) {
                        continue;
                    }
                    $code = (string) (int) $rawCode;
                    $jan = preg_replace('/\.0+$/', '', $rawCode);
                    foreach (array_merge(isset($items[$code]) ? [$items[$code]] : [], array_values($itemsByJan[$jan] ?? [])) as $candidate) {
                        if ($candidate['is_active']) {
                            $matches[$candidate['id']] = $candidate;
                        }
                    }
                }
                if (count($matches) !== 1) {
                    $result['skipped'][] = ['row' => $r, 'code' => implode(' / ', array_filter($rawCodes)),
                        'reason' => count($matches) > 1 ? '商品CD・JANが複数の商品に一致するため特定できません' : '商品CDまたはJANが不正、未登録または無効'];

                    continue;
                }
                $item = reset($matches);
                $numericCode = (string) $item['code'];
                $mergedStoreQuantity = false;
                foreach ($storeColumns as $c => $destination) {
                    if ($sheet->getCell([$c, $r])->isInMergeRange()) {
                        $mergedStoreQuantity = true;
                        break;
                    }
                }
                if ($mergedStoreQuantity) {
                    $result['skipped'][] = ['row' => $r, 'code' => $numericCode, 'name' => $item['name'],
                        'reason_code' => 'merged_store_quantity', 'reason' => '店舗数量が結合されています'];

                    continue;
                }
                $messages = [];
                foreach ($storeColumns as $c => $destination) {
                    $quantityCell = $sheet->getCell([$c, $r]);
                    $quantityValue = $quantityCell->getValue();
                    if ($quantityValue instanceof RichText) {
                        $quantityValue = $quantityValue->getPlainText();
                    }
                    if ($quantityCell->getDataType() !== DataType::TYPE_FORMULA
                        && $quantityCell->getDataType() !== DataType::TYPE_ERROR
                        && is_string($quantityValue) && self::quantity($quantityValue) === null
                        && preg_match('/[\p{L}]/u', preg_replace('/^[+-]?[0-9.]+[eE][+-]?[0-9]+$/', '', self::normalize($quantityValue)))) {
                        $messages[] = $quantityCell->getCoordinate().'：'.$quantityValue;
                    }
                }
                if ($messages !== []) {
                    $result['skipped'][] = ['row' => $r, 'code' => $numericCode, 'name' => $item['name'],
                        'reason_code' => 'store_quantity_message', 'reason' => '数量欄にメッセージがあります（'.implode('／', $messages).'）'];

                    continue;
                }
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
                if (isset($seenItems[$item['id']])) {
                    $index = $seenItems[$item['id']];
                    $previous = $result['rows'][$index];
                    $result['duplicate_warnings'][] = "商品CD{$numericCode}：{$item['name']}は重複しています。{$previous['excel_row']}行目の数量を後の{$r}行目で上書きしました。";
                    $result['excel_total'] -= $previous['total'];
                    $result['rows'][$index] = $row;
                } else {
                    $seenItems[$item['id']] = count($result['rows']);
                    $result['rows'][] = $row;
                }
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
