<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PatchOracleShelfMappingCommand extends Command
{
    protected $signature = 'stock:patch-oracle-shelf-mapping
                            {--mapping= : shelf_mapping_diff.csv or shelf_mapping_diff_simple.csv}
                            {--warehouse-code=91 : 対象倉庫CD}
                            {--output= : CSV output path. Defaults to storage/reports/current timestamp}
                            {--apply : 実際にlocations作成とlocation_id更新を行う}';

    protected $description = 'Patch WMS item locations to the Oracle shelf mapping CSV and emit an assignment-cause audit.';

    public function handle(): int
    {
        $mappingPath = $this->option('mapping') ?: storage_path('reports/20260512-091215-oracle-mysql-shelf-diff/shelf_mapping_diff.csv');
        $warehouseCode = (int) $this->option('warehouse-code');
        $apply = (bool) $this->option('apply');

        if (! is_file($mappingPath)) {
            $this->error("Mapping CSV not found: {$mappingPath}");

            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('DRY RUN: no rows will be changed. Add --apply to patch data.');
        }

        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('code', $warehouseCode)
            ->first(['id', 'code', 'name']);

        if (! $warehouse) {
            $this->error("Warehouse not found: code={$warehouseCode}");

            return self::FAILURE;
        }

        $mappings = $this->loadMappings($mappingPath, $warehouseCode);
        if (empty($mappings)) {
            $this->warn('No mapping rows found.');

            return self::SUCCESS;
        }

        $plan = $this->buildPlan((int) $warehouse->id, $warehouseCode, $mappings);
        $outputPath = $this->writeCsv($plan['rows']);

        $summary = $plan['summary'];
        foreach ($summary as $key => $value) {
            $this->line("{$key}={$value}");
        }
        $this->info("CSV: {$outputPath}");

        if ($apply) {
            $result = $this->applyPatch($plan);
            foreach ($result as $key => $value) {
                $this->line("{$key}={$value}");
            }
            $this->info('Patch complete.');
        }

        return self::SUCCESS;
    }

    private function loadMappings(string $path, int $warehouseCode): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);

            return [];
        }

        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            $row = array_combine($headers, $line);
            if (! is_array($row)) {
                continue;
            }

            $rowWarehouseCode = (int) $this->csvValue($row, ['warehouse_code', '倉庫CD']);
            if ($rowWarehouseCode !== $warehouseCode) {
                continue;
            }

            $itemCode = trim((string) $this->csvValue($row, ['item_code', '商品コード']));
            $oldShelf = trim((string) $this->csvValue($row, ['old_shelf', '旧棚番']));
            $newShelf = trim((string) $this->csvValue($row, ['new_shelf', '新棚番']));

            if ($itemCode === '' || $oldShelf === '' || $newShelf === '' || $oldShelf === $newShelf) {
                continue;
            }

            $rows[] = [
                'warehouse_code' => $rowWarehouseCode,
                'item_code' => $itemCode,
                'item_name' => trim((string) $this->csvValue($row, ['item_name', '商品名'])),
                'old_shelf' => $oldShelf,
                'new_shelf' => $newShelf,
                'old_location_ids' => trim((string) $this->csvValue($row, ['old_location_ids'])),
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function csvValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return (string) $row[$key];
            }
        }

        return '';
    }

    private function buildPlan(int $warehouseId, int $warehouseCode, array $mappings): array
    {
        $rows = [];
        $summary = [
            'warehouse_code' => $warehouseCode,
            'mapping_rows' => count($mappings),
            'locations_to_create' => 0,
            'real_stock_lots_updates' => 0,
            'item_default_location_updates' => 0,
            'active_document_reference_updates' => 0,
            'skipped_rows' => 0,
        ];

        $seenCreates = [];

        foreach ($mappings as $mapping) {
            $item = DB::connection('sakemaru')
                ->table('items')
                ->where('code', $mapping['item_code'])
                ->first(['id', 'code', 'name']);

            if (! $item) {
                $rows[] = $this->planRow($mapping, [
                    'status' => 'skipped',
                    'reason' => 'item_not_found',
                ]);
                $summary['skipped_rows']++;

                continue;
            }

            $oldLocations = $this->findOldLocations($warehouseId, $mapping);
            if ($oldLocations->isEmpty()) {
                $rows[] = $this->planRow($mapping, [
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'status' => 'skipped',
                    'reason' => 'old_location_not_found',
                ]);
                $summary['skipped_rows']++;

                continue;
            }

            $targetLocation = $this->findTargetLocation($warehouseId, $mapping['new_shelf']);
            $locationCreate = null;
            if (! $targetLocation) {
                $createKey = "{$warehouseId}:{$mapping['new_shelf']}";
                if (! isset($seenCreates[$createKey])) {
                    $locationCreate = $this->buildLocationCreateRow(
                        $warehouseId,
                        $warehouseCode,
                        $mapping['new_shelf'],
                        $oldLocations->first()
                    );
                    $seenCreates[$createKey] = $locationCreate;
                    $summary['locations_to_create']++;
                } else {
                    $locationCreate = $seenCreates[$createKey];
                }
            }

            $oldLocationIds = $oldLocations->pluck('id')->map(fn ($id) => (int) $id)->all();
            $lotUpdates = $this->countActiveLotUpdates($warehouseId, (int) $item->id, $oldLocationIds);
            $defaultUpdates = $this->countDefaultLocationUpdates($warehouseId, (int) $item->id, $oldLocationIds);
            $activeDocumentRefs = $this->countActiveDocumentReferences($warehouseId, (int) $item->id, $oldLocationIds);

            $summary['real_stock_lots_updates'] += $lotUpdates;
            $summary['item_default_location_updates'] += $defaultUpdates;
            $summary['active_document_reference_updates'] += $activeDocumentRefs;

            $rows[] = $this->planRow($mapping, [
                'item_id' => $item->id,
                'item_name' => $item->name,
                'status' => 'planned',
                'reason' => $this->inferAssignmentReasonFromLocations($warehouseId, $mapping['old_shelf'], $mapping['new_shelf'], $oldLocations),
                'old_location_ids' => implode(',', $oldLocationIds),
                'old_location_names' => $oldLocations->pluck('name')->implode(','),
                'old_location_code_paths' => $oldLocations->map(fn ($location) => $this->locationCodePath($location))->implode(','),
                'new_location_id' => $targetLocation?->id ?? '',
                'new_location_create' => $locationCreate ? 'yes' : 'no',
                'new_floor_id' => $targetLocation?->floor_id ?? ($locationCreate['floor_id'] ?? ''),
                'real_stock_lots_updates' => $lotUpdates,
                'item_default_location_updates' => $defaultUpdates,
                'active_document_reference_count' => $activeDocumentRefs,
            ]);
        }

        return [
            'warehouse_id' => $warehouseId,
            'warehouse_code' => $warehouseCode,
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    private function planRow(array $mapping, array $overrides): array
    {
        return array_merge([
            'warehouse_code' => $mapping['warehouse_code'],
            'item_code' => $mapping['item_code'],
            'item_name' => $mapping['item_name'],
            'old_shelf' => $mapping['old_shelf'],
            'new_shelf' => $mapping['new_shelf'],
            'status' => '',
            'reason' => '',
            'item_id' => '',
            'old_location_ids' => '',
            'old_location_names' => '',
            'old_location_code_paths' => '',
            'new_location_id' => '',
            'new_location_create' => '',
            'new_floor_id' => '',
            'real_stock_lots_updates' => 0,
            'item_default_location_updates' => 0,
            'active_document_reference_count' => 0,
        ], $overrides);
    }

    private function findLocationsByShelf(int $warehouseId, string $shelf): \Illuminate\Support\Collection
    {
        [$code1, $code2, $code3] = $this->parseShelfCode($shelf);

        return DB::connection('sakemaru')
            ->table('locations')
            ->where('warehouse_id', $warehouseId)
            ->where(function ($query) use ($shelf, $code1, $code2, $code3) {
                $query->where('name', $shelf)
                    ->orWhere(function ($query) use ($code1, $code2, $code3) {
                        $query->where('code1', $code1)
                            ->where('code2', $code2)
                            ->when($code3 === null, fn ($query) => $query->whereNull('code3'))
                            ->when($code3 !== null, fn ($query) => $query->where('code3', $code3));
                    });
            })
            ->orderBy('id')
            ->get(['id', 'warehouse_id', 'floor_id', 'code1', 'code2', 'code3', 'name', 'available_quantity_flags', 'temperature_type', 'is_restricted_area', 'wms_picking_area_id']);
    }

    private function findOldLocations(int $warehouseId, array $mapping): \Illuminate\Support\Collection
    {
        $oldLocationIds = $this->csvLocationIds($mapping['old_location_ids'] ?? '');
        if (! empty($oldLocationIds)) {
            $locations = DB::connection('sakemaru')
                ->table('locations')
                ->whereIn('id', $oldLocationIds)
                ->orderBy('id')
                ->get(['id', 'warehouse_id', 'floor_id', 'code1', 'code2', 'code3', 'name', 'available_quantity_flags', 'temperature_type', 'is_restricted_area', 'wms_picking_area_id']);

            if ($locations->isNotEmpty()) {
                return $locations;
            }
        }

        return $this->findLocationsByShelf($warehouseId, $mapping['old_shelf']);
    }

    private function csvLocationIds(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn ($id) => trim($id))
            ->filter(fn ($id) => $id !== '' && ctype_digit($id))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function findTargetLocation(int $warehouseId, string $shelf): ?object
    {
        [$code1, $code2, $code3] = $this->parseShelfCode($shelf);

        return DB::connection('sakemaru')
            ->table('locations')
            ->where('warehouse_id', $warehouseId)
            ->where(function ($query) use ($shelf, $code1, $code2, $code3) {
                $query->where('name', $shelf)
                    ->orWhere(function ($query) use ($code1, $code2, $code3) {
                        $query->where('code1', $code1)
                            ->where('code2', $code2)
                            ->when($code3 === null, fn ($query) => $query->whereNull('code3'))
                            ->when($code3 !== null, fn ($query) => $query->where('code3', $code3));
                    });
            })
            ->orderBy('id')
            ->first(['id', 'warehouse_id', 'floor_id', 'code1', 'code2', 'code3', 'name']);
    }

    private function buildLocationCreateRow(int $warehouseId, int $warehouseCode, string $shelf, object $sourceLocation): array
    {
        [$code1, $code2, $code3] = $this->parseShelfCode($shelf);
        $floorId = $this->resolveFloorId($warehouseId, $warehouseCode, $shelf, $sourceLocation);
        $prefixLocation = $this->findPrefixLocation($warehouseId, $shelf);

        return [
            'client_id' => 6,
            'warehouse_id' => $warehouseId,
            'floor_id' => $floorId,
            'wms_picking_area_id' => $prefixLocation?->wms_picking_area_id,
            'available_quantity_flags' => $this->allocatableQuantityFlags(
                $prefixLocation?->available_quantity_flags ?? $sourceLocation->available_quantity_flags ?? null
            ),
            'temperature_type' => $prefixLocation?->temperature_type ?? $sourceLocation->temperature_type ?? 'NORMAL',
            'is_restricted_area' => $prefixLocation?->is_restricted_area ?? $sourceLocation->is_restricted_area ?? 0,
            'code1' => $code1,
            'code2' => $code2,
            'code3' => $code3,
            'name' => $shelf,
            'is_created_from_data_transfer' => 1,
        ];
    }

    private function resolveFloorId(int $warehouseId, int $warehouseCode, string $shelf, object $sourceLocation): int
    {
        $prefixLocation = $this->findPrefixLocation($warehouseId, $shelf);
        if ($prefixLocation?->floor_id) {
            return (int) $prefixLocation->floor_id;
        }

        if ($sourceLocation->floor_id) {
            return (int) $sourceLocation->floor_id;
        }

        $floor = DB::connection('sakemaru')
            ->table('floors')
            ->where('warehouse_id', $warehouseId)
            ->where(function ($query) {
                $query->where('name', 'like', '%1F')
                    ->orWhere('code', '1F');
            })
            ->orderBy('id')
            ->first(['id']);

        if ($floor) {
            return (int) $floor->id;
        }

        return (int) DB::connection('sakemaru')
            ->table('floors')
            ->where('warehouse_id', $warehouseId)
            ->orderBy('id')
            ->value('id');
    }

    private function allocatableQuantityFlags(?int $flags): int
    {
        return ($flags !== null && $flags !== 8) ? $flags : 7;
    }

    private function findPrefixLocation(int $warehouseId, string $shelf): ?object
    {
        $prefix = substr($shelf, 0, 3);

        return DB::connection('sakemaru')
            ->table('locations')
            ->where('warehouse_id', $warehouseId)
            ->where('name', $prefix)
            ->orderBy('id')
            ->first(['id', 'floor_id', 'available_quantity_flags', 'temperature_type', 'is_restricted_area', 'wms_picking_area_id']);
    }

    private function countActiveLotUpdates(int $warehouseId, int $itemId, array $oldLocationIds): int
    {
        if (empty($oldLocationIds)) {
            return 0;
        }

        return DB::connection('sakemaru')
            ->table('real_stock_lots as rsl')
            ->join('real_stocks as rs', 'rs.id', '=', 'rsl.real_stock_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('rs.item_id', $itemId)
            ->whereIn('rsl.location_id', $oldLocationIds)
            ->where('rsl.status', 'ACTIVE')
            ->where('rsl.current_quantity', '<>', 0)
            ->count();
    }

    private function countDefaultLocationUpdates(int $warehouseId, int $itemId, array $oldLocationIds): int
    {
        if (empty($oldLocationIds)) {
            return 0;
        }

        return DB::connection('sakemaru')
            ->table('item_incoming_default_locations')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('location_id', $oldLocationIds)
            ->count();
    }

    private function countActiveDocumentReferences(int $warehouseId, int $itemId, array $oldLocationIds): int
    {
        if (empty($oldLocationIds)) {
            return 0;
        }

        $reservations = DB::connection('sakemaru')
            ->table('wms_reservations')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('location_id', $oldLocationIds)
            ->whereNotIn('status', ['RELEASED', 'CANCELLED'])
            ->count();

        $pickingResults = DB::connection('sakemaru')
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pt.id', '=', 'pir.picking_task_id')
            ->where('pt.warehouse_id', $warehouseId)
            ->where('pir.item_id', $itemId)
            ->whereIn('pir.location_id', $oldLocationIds)
            ->whereNotIn('pt.status', ['COMPLETED', 'SHIPPED'])
            ->count();

        return $reservations + $pickingResults;
    }

    private function inferAssignmentReason(string $oldShelf, string $newShelf, array $oldLocationNames): string
    {
        if ($oldShelf === 'Z-0-0' || in_array('デフォルト', $oldLocationNames, true)) {
            return 'default_location_fallback';
        }

        if (str_starts_with($oldShelf, 'ZZ1')) {
            return 'zz1_no_location_fallback';
        }

        $compactOld = str_replace('-', '', $oldShelf);
        if ($compactOld !== '' && str_starts_with($newShelf, $compactOld) && strlen($compactOld) < strlen($newShelf)) {
            return 'prefix_only_location';
        }

        return 'legacy_location_mismatch';
    }

    private function inferAssignmentReasonFromLocations(int $warehouseId, string $oldShelf, string $newShelf, \Illuminate\Support\Collection $oldLocations): string
    {
        if ($oldLocations->contains(fn ($location) => (int) $location->warehouse_id !== $warehouseId)) {
            return 'cross_warehouse_location_reference';
        }

        return $this->inferAssignmentReason($oldShelf, $newShelf, $oldLocations->pluck('name')->all());
    }

    private function parseShelfCode(string $shelf): array
    {
        $shelf = trim($shelf);
        if ($shelf === '') {
            return ['X', '00', '000'];
        }

        if (str_contains($shelf, '-')) {
            $parts = explode('-', $shelf);

            return [
                $parts[0] ?: 'X',
                $parts[1] ?? '00',
                isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null,
            ];
        }

        if (strlen($shelf) >= 6) {
            $first3 = substr($shelf, 0, 3);
            $code3 = substr($shelf, 3, 3);
            for ($i = 0; $i < strlen($first3); $i++) {
                if (ctype_digit($first3[$i])) {
                    return [
                        $i > 0 ? substr($first3, 0, $i) : $first3[0],
                        substr($first3, $i),
                        $code3,
                    ];
                }
            }

            return [$first3, '00', $code3];
        }

        for ($i = 0; $i < strlen($shelf); $i++) {
            if (ctype_digit($shelf[$i])) {
                return [
                    $i > 0 ? substr($shelf, 0, $i) : $shelf[0],
                    substr($shelf, $i),
                    null,
                ];
            }
        }

        return [$shelf, '00', null];
    }

    private function locationCodePath(object $location): string
    {
        return implode('-', array_filter([
            $location->code1,
            $location->code2,
            $location->code3,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    private function applyPatch(array $plan): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($plan) {
            $now = now();
            $createdLocations = 0;
            $lotUpdates = 0;
            $defaultUpdates = 0;
            $reservationUpdates = 0;
            $pickingResultUpdates = 0;
            $targetLocationIds = [];

            foreach ($plan['rows'] as $row) {
                if ($row['status'] !== 'planned') {
                    continue;
                }

                $targetLocationId = $row['new_location_id'] ? (int) $row['new_location_id'] : null;
                if ($targetLocationId === null) {
                    $targetLocationId = $this->findTargetLocation($plan['warehouse_id'], $row['new_shelf'])?->id;
                }

                if ($targetLocationId === null) {
                    $sourceLocation = DB::connection('sakemaru')
                        ->table('locations')
                        ->whereIn('id', array_values(array_filter(array_map('intval', explode(',', (string) $row['old_location_ids'])))))
                        ->orderBy('id')
                        ->first(['id', 'warehouse_id', 'floor_id', 'code1', 'code2', 'code3', 'name', 'available_quantity_flags', 'temperature_type', 'is_restricted_area', 'wms_picking_area_id'])
                        ?: $this->findLocationsByShelf($plan['warehouse_id'], $row['old_shelf'])->first();
                    $createRow = $this->buildLocationCreateRow($plan['warehouse_id'], $plan['warehouse_code'], $row['new_shelf'], $sourceLocation);
                    $createRow['created_at'] = $now;
                    $createRow['updated_at'] = $now;
                    $targetLocationId = DB::connection('sakemaru')->table('locations')->insertGetId($createRow);
                    $createdLocations++;
                }

                $targetLocationIds[$row['new_shelf']] = $targetLocationId;
                $oldLocationIds = array_values(array_filter(array_map('intval', explode(',', (string) $row['old_location_ids']))));
                if (empty($oldLocationIds)) {
                    continue;
                }

                $lotUpdates += DB::connection('sakemaru')
                    ->table('real_stock_lots as rsl')
                    ->join('real_stocks as rs', 'rs.id', '=', 'rsl.real_stock_id')
                    ->where('rs.warehouse_id', $plan['warehouse_id'])
                    ->where('rs.item_id', (int) $row['item_id'])
                    ->whereIn('rsl.location_id', $oldLocationIds)
                    ->where('rsl.status', 'ACTIVE')
                    ->where('rsl.current_quantity', '<>', 0)
                    ->update([
                        'rsl.location_id' => $targetLocationId,
                        'rsl.floor_id' => (int) DB::connection('sakemaru')->table('locations')->where('id', $targetLocationId)->value('floor_id'),
                        'rsl.updated_at' => $now,
                    ]);

                $defaultUpdates += DB::connection('sakemaru')
                    ->table('item_incoming_default_locations')
                    ->where('warehouse_id', $plan['warehouse_id'])
                    ->where('item_id', (int) $row['item_id'])
                    ->whereIn('location_id', $oldLocationIds)
                    ->update([
                        'location_id' => $targetLocationId,
                        'updated_at' => $now,
                    ]);

                $reservationUpdates += DB::connection('sakemaru')
                    ->table('wms_reservations')
                    ->where('warehouse_id', $plan['warehouse_id'])
                    ->where('item_id', (int) $row['item_id'])
                    ->whereIn('location_id', $oldLocationIds)
                    ->whereNotIn('status', ['RELEASED', 'CANCELLED'])
                    ->update([
                        'location_id' => $targetLocationId,
                        'updated_at' => $now,
                    ]);

                $pickingResultUpdates += DB::connection('sakemaru')
                    ->table('wms_picking_item_results as pir')
                    ->join('wms_picking_tasks as pt', 'pt.id', '=', 'pir.picking_task_id')
                    ->where('pt.warehouse_id', $plan['warehouse_id'])
                    ->where('pir.item_id', (int) $row['item_id'])
                    ->whereIn('pir.location_id', $oldLocationIds)
                    ->whereNotIn('pt.status', ['COMPLETED', 'SHIPPED'])
                    ->update([
                        'pir.location_id' => $targetLocationId,
                        'pir.updated_at' => $now,
                    ]);
            }

            return [
                'locations_created' => $createdLocations,
                'real_stock_lots_updated' => $lotUpdates,
                'item_default_locations_updated' => $defaultUpdates,
                'wms_reservations_updated' => $reservationUpdates,
                'wms_picking_item_results_updated' => $pickingResultUpdates,
                'target_location_ids' => implode(',', array_unique($targetLocationIds)),
            ];
        });
    }

    private function writeCsv(array $rows): string
    {
        $path = $this->option('output') ?: storage_path('reports/'.now()->format('Ymd-His').'-oracle-shelf-mapping-patch.csv');
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $columns = [
            'warehouse_code',
            'item_code',
            'item_name',
            'old_shelf',
            'new_shelf',
            'status',
            'reason',
            'item_id',
            'old_location_ids',
            'old_location_names',
            'old_location_code_paths',
            'new_location_id',
            'new_location_create',
            'new_floor_id',
            'real_stock_lots_updates',
            'item_default_location_updates',
            'active_document_reference_count',
        ];

        $handle = fopen($path, 'w');
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($column) => $row[$column] ?? '', $columns));
        }
        fclose($handle);

        return $path;
    }
}
