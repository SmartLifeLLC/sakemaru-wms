<?php

namespace App\Services\AutoOrder;

use App\Models\WmsContractorSetting;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class ContractorOrderConditionImport
{
    public function read(string $path, string $sheet = '原本'): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheet]);
        $book = $reader->load($path);
        try {
            $source = $book->getSheetByName($sheet);
            if (! $source) {
                throw new RuntimeException('指定したシートがありません。原本シートを確認してください。');
            }
            $data = $source->toArray();
            $header = array_map(fn ($value): string => trim(mb_convert_kana((string) $value, 'as')), array_shift($data) ?? []);
            $codeColumn = array_search('仕入先', $header, true);
            $noteColumn = array_search('発注ロット', $header, true);
            if ($codeColumn === false || $noteColumn === false) {
                throw new RuntimeException('仕入先・発注ロットの見出しが見つかりません。');
            }
            $rows = [];
            foreach ($data as $index => $row) {
                $code = trim(mb_convert_kana((string) ($row[$codeColumn] ?? ''), 'as'));
                if ($code === '') {
                    continue;
                }
                if (! preg_match('/^\d+(?:\.0+)?$/', $code)) {
                    throw new RuntimeException(($index + 2).'行目の仕入先CDを確認してください。');
                }
                $note = trim(str_replace(["\r\n", "\r"], "\n", (string) ($row[$noteColumn] ?? '')));
                if (mb_strlen($note) > 2000) {
                    throw new RuntimeException(($index + 2).'行目の発注条件は2,000文字以内にしてください。');
                }
                $rows[] = ['row' => $index + 2, 'code' => (string) (int) $code, 'conditions' => $note];
            }

            return $rows;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function plan(array $rows, int $clientId, bool $overwrite = false): array
    {
        $codes = array_column($rows, 'code');
        $duplicates = array_filter(array_count_values($codes), fn (int $count): bool => $count > 1);
        $contractors = DB::connection('sakemaru')->table('contractors')->where('client_id', $clientId)->whereIn('code', $codes)->get()->groupBy('code');
        $settings = WmsContractorSetting::whereIn('contractor_id', $contractors->flatten(1)->pluck('id'))->get()->groupBy('contractor_id');
        foreach ($rows as &$row) {
            $matches = $contractors->get($row['code'], collect());
            $row['status'] = 'unmatched';
            if (isset($duplicates[$row['code']]) || $matches->count() > 1) {
                $row['status'] = 'duplicate';
            } elseif ($matches->count() === 1) {
                $row['contractor_id'] = (int) $matches->first()->id;
                $current = $settings->get($row['contractor_id'], collect());
                $row['before'] = $current->first()?->order_conditions;
                $row['setting_id'] = $current->first()?->id;
                $row['status'] = match (true) {
                    $current->count() > 1 => 'duplicate',
                    $row['conditions'] === '' => 'blank',
                    (string) $row['before'] === $row['conditions'] => 'unchanged',
                    filled($row['before']) && ! $overwrite => 'conflict',
                    default => 'change',
                };
            }
        }
        unset($row);

        return ['client_id' => $clientId, 'counts' => array_count_values(array_column($rows, 'status')), 'rows' => $rows];
    }

    public function apply(array $plan): int
    {
        foreach (['unmatched', 'duplicate', 'conflict'] as $status) {
            if (($plan['counts'][$status] ?? 0) > 0) {
                throw new RuntimeException('未一致・重複・既存値との相違を解決してから取り込んでください。');
            }
        }

        return DB::connection('sakemaru')->transaction(function () use ($plan): int {
            $count = 0;
            foreach ($plan['rows'] as $row) {
                if ($row['status'] !== 'change') {
                    continue;
                }
                $contractor = DB::connection('sakemaru')->table('contractors')->where('id', $row['contractor_id'])
                    ->where('client_id', $plan['client_id'])->where('code', $row['code'])->lockForUpdate()->first();
                $settings = WmsContractorSetting::where('contractor_id', $row['contractor_id'])->lockForUpdate()->get();
                $setting = $settings->first();
                if (! $contractor || $settings->count() > 1 || $setting?->id !== $row['setting_id'] || $setting?->order_conditions !== $row['before']) {
                    throw new RuntimeException('照合後に発注先の情報が変更されています。再度照合してください。');
                }
                $setting ??= new WmsContractorSetting(['contractor_id' => $row['contractor_id'], 'transmission_type' => 'MANUAL_CSV']);
                $setting->order_conditions = $row['conditions'];
                $setting->save();
                $count++;
            }

            return $count;
        });
    }
}
