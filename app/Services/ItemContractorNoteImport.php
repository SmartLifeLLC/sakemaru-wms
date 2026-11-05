<?php

namespace App\Services;

use App\Models\Sakemaru\Warehouse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class ItemContractorNoteImport
{
    /** @return list<array{id: int, code: string, name: string}> */
    public function warehouseOptions(int $clientId): array
    {
        return Warehouse::query()
            ->where('warehouses.client_id', $clientId)
            ->whereIn('warehouses.id', fn ($query) => $query->select('warehouse_id')
                ->from('item_contractors')->where('client_id', $clientId))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Warehouse $warehouse) => ['id' => (int) $warehouse->id, 'code' => (string) $warehouse->code, 'name' => $warehouse->name])
            ->all();
    }

    public function import(int $clientId, array $warehouseIds, string $path, string $extension): int
    {
        if ($clientId <= 0) {
            throw new InvalidArgumentException('更新対象の会社を確認してください。');
        }

        if ($warehouseIds === []) {
            throw new InvalidArgumentException('対象倉庫を1つ以上選択してください。');
        }

        $allowedIds = array_column($this->warehouseOptions($clientId), 'id');
        foreach ($warehouseIds as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || ! in_array((int) $id, $allowedIds, true)) {
                throw new InvalidArgumentException('選択した倉庫に更新できない倉庫が含まれています。対象倉庫を選び直してください。');
            }
        }

        $rows = $this->readRows($path, $extension);
        $db = DB::connection('sakemaru');

        return $db->transaction(function () use ($db, $clientId, $warehouseIds, $rows): int {
            $contractors = $db->table('item_contractors')->where('item_contractors.client_id', $clientId);
            $master = (clone $contractors)->join('items', 'items.id', '=', 'item_contractors.item_id')
                ->where('items.client_id', $clientId);
            $updated = 0;
            foreach (array_chunk($rows, 500, preserve_keys: true) as $chunk) {
                $byCode = (clone $master)->whereIn('items.code', array_keys($chunk));
                $matches = (clone $byCode)->whereIn('item_contractors.warehouse_id', $warehouseIds)
                    ->orderBy('item_contractors.id')
                    ->lockForUpdate()
                    ->get(['item_contractors.id', 'item_contractors.note', 'items.code'])
                    ->groupBy('code');

                $registered = null;
                foreach ($chunk as $code => $row) {
                    $records = $matches->get($code);
                    if ($records === null) {
                        $registered ??= $byCode->distinct()->pluck('items.code')->flip();
                        if (! $registered->has($code)) {
                            throw new InvalidArgumentException("{$row['line']}行目の商品コード「{$code}」は商品発注管理に未登録です。商品コードを確認してください。");
                        }

                        continue;
                    }

                    $changedIds = $records->filter(fn ($record) => $record->note !== $row['note'])->pluck('id');
                    if ($changedIds->isNotEmpty()) {
                        // Saving via the model can also change the legacy auto-order flags.
                        $updated += (clone $contractors)->whereIn('warehouse_id', $warehouseIds)->whereIn('id', $changedIds)
                            ->update(['note' => $row['note'], 'updated_at' => now()]);
                    }
                }
            }

            return $updated;
        }, 3);
    }

    /** @return array<int, array{line: int, note: ?string}> */
    private function readRows(string $path, string $extension): array
    {
        $reader = match (strtolower($extension)) {
            'xls' => new Xls,
            'xlsx' => new Xlsx,
            'csv' => (new Csv)->setDelimiter(',')->setEscapeCharacter('')->setPreserveNullString(true)->setValueBinder(new StringValueBinder),
            default => throw new InvalidArgumentException('拡張子が .xls、.xlsx、.csv のファイルを選択してください。'),
        };

        try {
            if ($reader instanceof Csv) {
                $reader->setInputEncoding(Csv::guessEncoding($path, 'SJIS-win'));
            } else {
                $reader->setLoadSheetsOnly($reader->listWorksheetNames($path)[0] ?? []);
            }
            $book = $reader->setReadDataOnly(true)->load($path);
        } catch (SpreadsheetException $exception) {
            throw new InvalidArgumentException('ファイルを読み込めません。拡張子とファイル形式を確認してください。', previous: $exception);
        }

        try {
            $sheet = $book->getSheet(0);
            $header = $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn(1).'1', null, false, false, true)[1];
            $columns = [];
            foreach (['商品コード', '備考'] as $label) {
                $matches = array_keys(array_filter($header, fn ($value) => trim((string) $value) === $label));
                if (count($matches) !== 1) {
                    throw new InvalidArgumentException("1行目に「{$label}」の見出しを1つ入力してください。");
                }
                $columns[] = $matches[0];
            }

            [$codeColumn, $noteColumn] = $columns;
            $rows = [];
            $lastRow = $sheet->getHighestDataRow();
            $invalidTypes = [DataType::TYPE_FORMULA, DataType::TYPE_ERROR, DataType::TYPE_BOOL];
            for ($line = 2; $line <= $lastRow; $line++) {
                $codeCell = $sheet->getCell($codeColumn.$line);
                $code = trim((string) $codeCell->getValue());
                $invalidCode = in_array($codeCell->getDataType(), $invalidTypes, true);

                $noteCell = $sheet->getCell($noteColumn.$line);
                $note = (string) $noteCell->getValue();
                if ($code === '' && trim($note) === '') {
                    continue;
                }
                if ($invalidCode || ! ctype_digit($code)) {
                    throw new InvalidArgumentException("{$line}行目の商品コードは半角数字で入力してください。");
                }
                if ($reader instanceof Csv && $noteCell->getValue() === null) {
                    throw new InvalidArgumentException("{$line}行目に備考欄がありません。見出しと同じ列数で入力してください。");
                }
                if (in_array($noteCell->getDataType(), $invalidTypes, true) || strlen($note) > 65535) {
                    throw new InvalidArgumentException("{$line}行目の備考を短い文字列で入力してください。");
                }

                $code = ltrim($code, '0') ?: '0';
                if (isset($rows[$code])) {
                    throw new InvalidArgumentException("{$line}行目の商品コード「{$code}」が重複しています。1商品につき1行にしてください。");
                }
                $rows[$code] = ['line' => $line, 'note' => trim($note) === '' ? null : $note];
            }

            if ($rows === []) {
                throw new InvalidArgumentException('2行目以降に更新する商品コードと備考を入力してください。');
            }

            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
