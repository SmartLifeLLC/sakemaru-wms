<?php

namespace App\Console\Commands\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderTransmissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExportJxAutoOrderSamplesCommand extends Command
{
    protected $signature = 'wms:export-jx-auto-order-samples
                            {--output= : 出力ディレクトリ。未指定なら storage/app/jx-auto-order-samples/{timestamp}}
                            {--limit= : 検証用の最大明細数}';

    protected $description = '自動発注ON商品のJX仕入先別サンプルCSV/JXファイルをローカル出力する（DB更新・送信なし）';

    private array $contractorsById = [];

    private array $settingsByContractorId = [];

    private array $generatorMapping = [];

    private array $orderingCodesByItemId = [];

    private array $latestCostPricesByItemId = [];

    private array $latestSupplierPricesByItemSupplier = [];

    private array $csvRowsByTargetId = [];

    private array $candidateRowsByTargetId = [];

    private array $validationRows = [];

    private array $excludedRows = [];

    public function handle(): int
    {
        $generator = app(OrderTransmissionService::class)->getGenerator();
        if (! $generator) {
            $this->error('JXファイルGeneratorが設定されていません。');

            return self::FAILURE;
        }

        $outputDir = $this->option('output') ?: storage_path('app/jx-auto-order-samples/'.now()->format('Ymd_His'));
        $this->prepareOutputDirectories($outputDir);

        $this->generatorMapping = $generator->getTransmissionContractorMapping();
        $jxTargetIds = $this->jxTargetContractorIds($generator);
        if (empty($jxTargetIds)) {
            $this->error('JX対応仕入先がありません。');

            return self::FAILURE;
        }

        $this->loadReferenceData();

        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $sourceRows = $this->loadAutoOrderRows($limit);
        $this->info('自動発注ON候補元: '.$sourceRows->count().'件');

        foreach ($sourceRows as $row) {
            $targetId = $this->resolveTransmissionContractorId((int) $row->contractor_id);
            if (! in_array($targetId, $jxTargetIds, true)) {
                continue;
            }

            $prepared = $this->prepareCandidateRow($row, $targetId);
            if ($prepared === null) {
                continue;
            }

            $this->csvRowsByTargetId[$targetId][] = $prepared['csv'];
            $this->candidateRowsByTargetId[$targetId][] = $prepared['candidate'];
        }

        $totalCsvRows = collect($this->csvRowsByTargetId)->sum(fn (array $rows) => count($rows));
        $this->info('JX対象明細: '.$totalCsvRows.'件');

        $generatedCount = 0;
        foreach ($this->candidateRowsByTargetId as $targetId => $candidates) {
            $target = $this->contractorsById[$targetId] ?? null;
            $targetCode = $target?->code ?? $targetId;
            $baseName = $this->safeFilename($targetCode.'_'.($target?->name ?? $targetId));

            $csvPath = "{$outputDir}/csv/{$baseName}.csv";
            $this->writeCsv($csvPath, $this->csvRowsByTargetId[$targetId] ?? []);

            $files = $generator->generate(collect($candidates));
            $jxFiles = collect($files)->filter(fn (array $file) => (int) ($file['contractor_id'] ?? 0) === (int) $targetId);

            if ($jxFiles->isEmpty()) {
                $this->validationRows[] = [
                    '仕入先CD' => $targetCode,
                    '仕入先名' => $target?->name ?? '',
                    'CSV明細数' => count($candidates),
                    'JX明細数' => 0,
                    '128バイト固定長' => 'NG',
                    'Dレコード件数一致' => 'NG',
                    '6缶ケース欄' => '未検証',
                    'ファイル' => '',
                    '備考' => 'JXファイルが生成されませんでした',
                ];

                continue;
            }

            foreach ($jxFiles as $file) {
                $jxPath = "{$outputDir}/jx/{$baseName}_{$file['filename']}";
                file_put_contents($jxPath, $file['content']);
                $validation = $this->validateJxFile($file['content'], count($candidates));
                $this->validationRows[] = [
                    '仕入先CD' => $targetCode,
                    '仕入先名' => $target?->name ?? '',
                    'CSV明細数' => count($candidates),
                    'JX明細数' => $validation['d_record_count'],
                    '128バイト固定長' => $validation['fixed_128'] ? 'OK' : 'NG',
                    'Dレコード件数一致' => $validation['d_record_count'] === count($candidates) ? 'OK' : 'NG',
                    '6缶ケース欄' => $this->validateSixPackRows($this->csvRowsByTargetId[$targetId] ?? []),
                    'ファイル' => $jxPath,
                    '備考' => $validation['message'],
                ];
                $generatedCount++;
            }
        }

        $this->writeCsv("{$outputDir}/validation_summary.csv", $this->validationRows);
        $this->writeCsv("{$outputDir}/excluded_rows.csv", $this->excludedRows);

        $this->info('CSV出力: '.count($this->csvRowsByTargetId).'仕入先');
        $this->info('JX出力: '.$generatedCount.'ファイル');
        $this->info('検証結果: '.$outputDir.'/validation_summary.csv');
        $this->info('出力先: '.$outputDir);

        return self::SUCCESS;
    }

    private function prepareOutputDirectories(string $outputDir): void
    {
        foreach ([$outputDir, "{$outputDir}/csv", "{$outputDir}/jx"] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    private function jxTargetContractorIds($generator): array
    {
        $settingIds = WmsContractorSetting::query()
            ->where('transmission_type', 'JX_FINET')
            ->pluck('contractor_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        return array_values(array_unique(array_merge(
            $settingIds,
            array_map('intval', $generator->getJxTransmissionContractorIds() ?? []),
            array_map('intval', array_values($this->generatorMapping))
        )));
    }

    private function loadReferenceData(): void
    {
        $this->contractorsById = Contractor::query()->get()->keyBy('id')->all();
        $this->settingsByContractorId = WmsContractorSetting::query()->get()->keyBy('contractor_id')->all();

        $this->orderingCodesByItemId = DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->leftJoin('item_quantity_information as iqi', 'iqi.id', '=', 'isi.item_quantity_information_id')
            ->where('isi.is_active', true)
            ->whereRaw("isi.search_string REGEXP '[1-9]'")
            ->select(
                'isi.item_id',
                'isi.search_string',
                'isi.is_used_for_ordering',
                'isi.code_type',
                'isi.quantity_type',
                'iqi.quantity as ordering_unit_qty'
            )
            ->orderByDesc('isi.is_used_for_ordering')
            ->orderBy('iqi.quantity')
            ->get()
            ->groupBy('item_id')
            ->all();

        $systemDate = now()->toDateString();
        $this->latestCostPricesByItemId = DB::connection('sakemaru')
            ->select('
                SELECT ip.item_id, ip.cost_unit_price, ip.cost_case_price
                FROM item_prices ip
                INNER JOIN (
                    SELECT item_id, MAX(start_date) AS max_start_date
                    FROM item_prices
                    WHERE is_active = true
                      AND start_date <= ?
                    GROUP BY item_id
                ) latest
                  ON ip.item_id = latest.item_id
                 AND ip.start_date = latest.max_start_date
                WHERE ip.is_active = true
            ', [$systemDate]);
        $this->latestCostPricesByItemId = collect($this->latestCostPricesByItemId)->keyBy('item_id')->all();

        $supplierPrices = DB::connection('sakemaru')
            ->select('
                SELECT ipp.item_id, s.id AS supplier_id, ipp.unit_price, ipp.case_price
                FROM item_partner_prices ipp
                INNER JOIN (
                    SELECT item_id, partner_id, MAX(start_date) AS max_start_date
                    FROM item_partner_prices
                    WHERE partner_category = "SUPPLIER"
                      AND is_active = true
                      AND start_date <= ?
                    GROUP BY item_id, partner_id
                ) latest
                  ON ipp.item_id = latest.item_id
                 AND ipp.partner_id = latest.partner_id
                 AND ipp.start_date = latest.max_start_date
                INNER JOIN suppliers s
                  ON s.partner_id = ipp.partner_id
                 AND s.partner_category = ipp.partner_category
                WHERE ipp.partner_category = "SUPPLIER"
                  AND ipp.is_active = true
            ', [$systemDate]);

        foreach ($supplierPrices as $price) {
            $this->latestSupplierPricesByItemSupplier[$price->item_id.':'.$price->supplier_id] = $price;
        }
    }

    private function loadAutoOrderRows(?int $limit): Collection
    {
        $query = DB::connection('sakemaru')
            ->table('item_contractors as ic')
            ->join('items as i', 'i.id', '=', 'ic.item_id')
            ->join('warehouses as w', 'w.id', '=', 'ic.warehouse_id')
            ->join('contractors as c', 'c.id', '=', 'ic.contractor_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'ic.supplier_id')
            ->leftJoin('partners as sp', 'sp.id', '=', 's.partner_id')
            ->where('ic.is_auto_order', true)
            ->where('c.is_auto_change_order', true)
            ->where('i.end_of_sale_type', 'NORMAL')
            ->where('i.is_ended', false)
            ->where(fn ($q) => $q->whereNull('i.start_of_sale_date')->orWhere('i.start_of_sale_date', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('i.end_of_sale_date')->orWhere('i.end_of_sale_date', '>', now()->toDateString()))
            ->select([
                'ic.id as item_contractor_id',
                'ic.warehouse_id',
                'ic.item_id',
                'ic.contractor_id',
                'ic.supplier_id',
                'ic.safety_stock',
                'ic.max_stock',
                'ic.min_stock',
                'ic.purchase_unit',
                'ic.auto_order_quantity',
                'i.code as item_code',
                'i.name_main as item_name',
                'i.packaging',
                'i.capacity_case',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                'c.code as contractor_code',
                'c.name as contractor_name',
                'sp.code as supplier_code',
                'sp.name as supplier_name',
            ])
            ->orderBy('c.code')
            ->orderBy('w.code')
            ->orderBy('i.code');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    private function prepareCandidateRow(object $row, int $targetId): ?array
    {
        $capacityCase = max(1, (int) ($row->capacity_case ?? 1));
        $ordering = $this->resolveOrderingCodeInfo((int) $row->item_id, $capacityCase);
        if ($ordering['code'] === null) {
            $this->excludedRows[] = $this->baseCsvRow($row, $targetId) + [
                '除外理由' => '発注コードなし',
            ];

            return null;
        }

        $pieceOrderQty = (int) ($row->auto_order_quantity ?? 0);
        $quantitySource = '自動発注数';
        if ($pieceOrderQty <= 0) {
            $pieceOrderQty = max($capacityCase, (int) ($row->purchase_unit ?? 1), 1);
            $quantitySource = '自動発注数未設定のため1ケース相当';
        }

        $orderQuantityCase = (int) ceil($pieceOrderQty / $capacityCase);
        $jxOrderQty = $this->calculateJxOrderQuantity($orderQuantityCase, $capacityCase, $ordering['ordering_unit_qty']);
        $costPrice = $this->latestCostPricesByItemId[$row->item_id] ?? null;
        $supplierPrice = $this->latestSupplierPricesByItemSupplier[$row->item_id.':'.$row->supplier_id] ?? null;

        $candidate = new WmsOrderCandidate([
            'batch_code' => 'SAMPLE'.now()->format('YmdHis'),
            'warehouse_id' => (int) $row->warehouse_id,
            'item_id' => (int) $row->item_id,
            'item_code' => $row->item_code,
            'contractor_id' => (int) $row->contractor_id,
            'supplier_id' => $row->supplier_id ? (int) $row->supplier_id : null,
            'purchase_unit_price' => null,
            'ordering_code' => $ordering['code'],
            'suggested_quantity' => $pieceOrderQty,
            'order_quantity' => $orderQuantityCase,
            'safety_stock' => (int) ($row->safety_stock ?? 0),
            'purchase_unit' => max(1, (int) ($row->purchase_unit ?? 1)),
            'quantity_type' => QuantityType::CASE,
            'expected_arrival_date' => now()->addDay(),
            'status' => CandidateStatus::CONFIRMED,
        ]);
        $candidate->setRelation('item', $this->newModel(Item::class, [
            'id' => (int) $row->item_id,
            'code' => $row->item_code,
            'name_main' => $row->item_name,
            'name' => $row->item_name,
            'packaging' => $row->packaging,
            'capacity_case' => $capacityCase,
        ]));
        $candidate->setRelation('warehouse', $this->newModel(Warehouse::class, [
            'id' => (int) $row->warehouse_id,
            'code' => $row->warehouse_code,
            'name' => $row->warehouse_name,
        ]));
        $candidate->setRelation('contractor', $this->newModel(Contractor::class, [
            'id' => (int) $row->contractor_id,
            'code' => $row->contractor_code,
            'name' => $row->contractor_name,
        ]));

        $csv = $this->baseCsvRow($row, $targetId) + [
            '発注コード' => $ordering['code'],
            '発注コード数量' => $ordering['ordering_unit_qty'] ?? '',
            '発注数量元' => $quantitySource,
            '発注数量バラ換算' => $pieceOrderQty,
            '発注数量ケース' => $orderQuantityCase,
            'JX仕入入数' => $ordering['ordering_unit_qty'] ?? $capacityCase,
            'JXケース数' => $ordering['ordering_unit_qty'] ? $jxOrderQty : $orderQuantityCase,
            'JXバラ数' => $ordering['ordering_unit_qty'] ? 0 : 0,
            '6缶ケース欄OK' => (int) $ordering['ordering_unit_qty'] === 6 ? 'OK' : '',
            '発注点' => (int) ($row->safety_stock ?? 0),
            '最大発注点' => (int) ($row->max_stock ?? 0),
            '最低在庫数' => (int) ($row->min_stock ?? 0),
            '自動発注数' => (int) ($row->auto_order_quantity ?? 0),
            '仕入単位' => (int) ($row->purchase_unit ?? 0),
            '現在原価バラ' => $costPrice?->cost_unit_price ?? '',
            '現在原価ケース' => $costPrice?->cost_case_price ?? '',
            '仕入先単価バラ' => $supplierPrice?->unit_price ?? '',
            '仕入先単価ケース' => $supplierPrice?->case_price ?? '',
        ];

        return ['candidate' => $candidate, 'csv' => $csv];
    }

    private function baseCsvRow(object $row, int $targetId): array
    {
        $target = $this->contractorsById[$targetId] ?? null;

        return [
            '集約先仕入先ID' => $targetId,
            '集約先仕入先CD' => $target?->code ?? '',
            '集約先仕入先名' => $target?->name ?? '',
            '元仕入先ID' => (int) $row->contractor_id,
            '元仕入先CD' => $row->contractor_code,
            '元仕入先名' => $row->contractor_name,
            '倉庫ID' => (int) $row->warehouse_id,
            '倉庫CD' => $row->warehouse_code,
            '倉庫名' => $row->warehouse_name,
            '商品ID' => (int) $row->item_id,
            '商品CD' => $row->item_code,
            '商品名' => $row->item_name,
            '規格' => $row->packaging,
            'ケース入数' => (int) ($row->capacity_case ?? 1),
            '仕入先ID' => $row->supplier_id ? (int) $row->supplier_id : '',
            '仕入先CD' => $row->supplier_code ?? '',
            '仕入先名' => $row->supplier_name ?? '',
        ];
    }

    private function resolveTransmissionContractorId(int $contractorId): int
    {
        $setting = $this->settingsByContractorId[$contractorId] ?? null;
        if ($setting?->transmission_contractor_id) {
            return (int) $setting->transmission_contractor_id;
        }

        return (int) ($this->generatorMapping[$contractorId] ?? $contractorId);
    }

    private function resolveOrderingCodeInfo(int $itemId, int $capacityCase): array
    {
        $codes = collect($this->orderingCodesByItemId[$itemId] ?? []);
        $ordering = $codes->first(fn ($code) => (bool) $code->is_used_for_ordering);
        $orderingCode = $this->normalizeCode($ordering?->search_string);
        $orderingUnitQty = $this->effectiveOrderingUnitQty($ordering?->ordering_unit_qty, $capacityCase);

        $preferredPack = $codes->first(fn ($code) => $this->effectiveOrderingUnitQty($code->ordering_unit_qty, $capacityCase) !== null);
        if ($preferredPack) {
            return [
                'code' => $this->normalizeCode($preferredPack->search_string),
                'ordering_unit_qty' => $this->effectiveOrderingUnitQty($preferredPack->ordering_unit_qty, $capacityCase),
            ];
        }

        return [
            'code' => $orderingCode,
            'ordering_unit_qty' => $orderingUnitQty,
        ];
    }

    private function effectiveOrderingUnitQty($qty, int $capacityCase): ?int
    {
        $qty = (int) ($qty ?? 0);
        if ($qty <= 1 || $qty === $capacityCase) {
            return null;
        }

        return $qty;
    }

    private function calculateJxOrderQuantity(int $orderQuantityCase, int $capacityCase, ?int $orderingUnitQty): int
    {
        if ($orderingUnitQty === null) {
            return $orderQuantityCase;
        }

        if ($orderingUnitQty === 6 && $capacityCase >= 24) {
            return $orderQuantityCase;
        }

        return (int) ceil(($orderQuantityCase * $capacityCase) / $orderingUnitQty);
    }

    private function validateJxFile(string $content, int $expectedDetails): array
    {
        $length = strlen($content);
        $fixed128 = $length > 0 && $length % 128 === 0;
        $dCount = 0;

        if ($fixed128) {
            for ($offset = 0; $offset < $length; $offset += 128) {
                if (substr($content, $offset, 1) === 'D') {
                    $dCount++;
                }
            }
        }

        return [
            'fixed_128' => $fixed128,
            'd_record_count' => $dCount,
            'message' => $fixed128
                ? ($dCount === $expectedDetails ? 'OK' : 'Dレコード件数不一致')
                : "ファイル長が128の倍数ではありません: {$length}",
        ];
    }

    private function validateSixPackRows(array $rows): string
    {
        $sixPackRows = array_filter($rows, fn (array $row) => (int) ($row['発注コード数量'] ?? 0) === 6);
        if (empty($sixPackRows)) {
            return '対象なし';
        }

        foreach ($sixPackRows as $row) {
            if (($row['6缶ケース欄OK'] ?? '') !== 'OK') {
                return 'NG';
            }
        }

        return 'OK';
    }

    private function writeCsv(string $path, array $rows): void
    {
        $stream = fopen($path, 'w');
        fwrite($stream, "\xEF\xBB\xBF");
        if ($rows !== []) {
            fputcsv($stream, array_keys(reset($rows)));
            foreach ($rows as $row) {
                fputcsv($stream, $row);
            }
        }
        fclose($stream);
    }

    private function normalizeCode(?string $code): ?string
    {
        $code = trim((string) $code);
        if ($code === '' || preg_match('/^0+$/', $code) === 1) {
            return null;
        }

        return str_pad($code, 13, '0', STR_PAD_LEFT);
    }

    private function safeFilename(string $value): string
    {
        return preg_replace('/[^\w\-一-龠ぁ-んァ-ヶー]+/u', '_', $value) ?: 'unknown';
    }

    private function newModel(string $class, array $attributes)
    {
        $model = new $class;
        $model->setRawAttributes($attributes, true);

        return $model;
    }
}
