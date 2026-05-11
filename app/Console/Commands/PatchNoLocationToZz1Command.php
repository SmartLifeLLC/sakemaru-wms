<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PatchNoLocationToZz1Command extends Command
{
    protected $signature = 'stock:patch-no-location-to-zz1
                            {--warehouse-code=91 : 対象倉庫CD}
                            {--output= : CSV output path. Defaults to storage/reports/current timestamp}
                            {--apply : 実際にlocation_idとlocationsを更新する}';

    protected $description = 'Move no-location shelves AA1/ZZ1 to the 2F ZZ1 location and hide its display name.';

    private const NO_LOCATION_DISPLAY_NAME = '　';

    public function handle(): int
    {
        $warehouseCode = (int) $this->option('warehouse-code');
        $apply = (bool) $this->option('apply');

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

        $floor2 = DB::connection('sakemaru')
            ->table('floors')
            ->where('warehouse_id', $warehouse->id)
            ->where(function ($query) {
                $query->where('name', 'like', '%2F')
                    ->orWhere('code', '2F');
            })
            ->orderBy('id')
            ->first(['id', 'name']);

        if (! $floor2) {
            $this->error("2F floor not found for warehouse code={$warehouseCode}");

            return self::FAILURE;
        }

        $plan = $this->buildPlan((int) $warehouse->id, (int) $warehouse->code, (int) $floor2->id);
        $outputPath = $this->writeCsv($plan['rows']);

        foreach ($plan['summary'] as $key => $value) {
            $this->line("{$key}={$value}");
        }
        $this->info("CSV: {$outputPath}");

        if ($plan['target_location_id'] === null) {
            $this->error('Target ZZ1 location could not be resolved.');

            return self::FAILURE;
        }

        if ($apply) {
            $result = $this->applyPatch($plan);
            foreach ($result as $key => $value) {
                $this->line("{$key}={$value}");
            }
            $this->info('Patch complete.');
        }

        return self::SUCCESS;
    }

    private function buildPlan(int $warehouseId, int $warehouseCode, int $floor2Id): array
    {
        $target = $this->resolveTargetLocation($warehouseId, $floor2Id);
        $sourceLocationIds = $this->resolveSourceLocationIds($warehouseId, $target?->id);

        $tables = $this->locationReferenceTables();
        $rows = [];
        $summary = [
            'warehouse_code' => $warehouseCode,
            'warehouse_id' => $warehouseId,
            'target_location_id' => $target?->id ?? '',
            'target_floor_id' => $floor2Id,
            'source_location_ids' => implode(',', $sourceLocationIds),
            'target_name_after_patch' => self::NO_LOCATION_DISPLAY_NAME,
        ];

        foreach ($tables as $table) {
            $count = empty($sourceLocationIds)
                ? 0
                : DB::connection('sakemaru')->table($table)->whereIn('location_id', $sourceLocationIds)->count();

            $summary["{$table}_updates"] = $count;
            $rows[] = [
                'type' => 'reference_update',
                'table' => $table,
                'target_location_id' => $target?->id,
                'source_location_ids' => implode(',', $sourceLocationIds),
                'rows' => $count,
                'message' => '',
            ];
        }

        $locationIdsToHide = array_values(array_unique(array_filter([
            $target?->id,
            ...$sourceLocationIds,
        ])));

        $summary['locations_to_rename'] = count($locationIdsToHide);
        $rows[] = [
            'type' => 'location_update',
            'table' => 'locations',
            'target_location_id' => $target?->id,
            'source_location_ids' => implode(',', $sourceLocationIds),
            'rows' => count($locationIdsToHide),
            'message' => 'set no-location display names to full-width space and available flags to 3',
        ];

        if ($target === null) {
            $summary['locations_to_create'] = 1;
            $rows[] = [
                'type' => 'location_create',
                'table' => 'locations',
                'target_location_id' => '',
                'source_location_ids' => '',
                'rows' => 1,
                'message' => 'create ZZ1 2F no-location',
            ];
        } else {
            $summary['locations_to_create'] = 0;
        }

        return [
            'warehouse_id' => $warehouseId,
            'floor2_id' => $floor2Id,
            'target_location_id' => $target?->id,
            'source_location_ids' => $sourceLocationIds,
            'location_ids_to_hide' => $locationIdsToHide,
            'tables' => $tables,
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    private function resolveTargetLocation(int $warehouseId, int $floor2Id): ?object
    {
        return DB::connection('sakemaru')
            ->table('locations')
            ->where('warehouse_id', $warehouseId)
            ->where('floor_id', $floor2Id)
            ->where('code1', 'ZZ')
            ->where('code2', '1')
            ->whereNull('code3')
            ->orderBy('id')
            ->first(['id', 'warehouse_id', 'floor_id', 'code1', 'code2', 'code3', 'name']);
    }

    private function resolveSourceLocationIds(int $warehouseId, ?int $targetLocationId): array
    {
        return DB::connection('sakemaru')
            ->table('locations')
            ->where('warehouse_id', $warehouseId)
            ->where(function ($query) {
                $query->where('name', 'AA1')
                    ->orWhere('name', 'ZZ1')
                    ->orWhere(function ($query) {
                        $query->where('code1', 'AA')
                            ->where('code2', '1')
                            ->whereNull('code3');
                    })
                    ->orWhere(function ($query) {
                        $query->where('code1', 'ZZ')
                            ->where('code2', '1')
                            ->whereNull('code3');
                    });
            })
            ->when($targetLocationId !== null, fn ($query) => $query->where('id', '<>', $targetLocationId))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function locationReferenceTables(): array
    {
        return collect([
            'item_incoming_default_locations',
            'real_stock_lots',
            'wms_reservations',
            'wms_picking_item_results',
            'wms_shortages',
        ])->filter(fn (string $table) => Schema::connection('sakemaru')->hasTable($table)
            && Schema::connection('sakemaru')->hasColumn($table, 'location_id'))
            ->values()
            ->all();
    }

    private function applyPatch(array $plan): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($plan) {
            $now = now();
            $targetLocationId = $plan['target_location_id'];

            if ($targetLocationId === null) {
                $targetLocationId = DB::connection('sakemaru')->table('locations')->insertGetId([
                    'client_id' => 6,
                    'warehouse_id' => $plan['warehouse_id'],
                    'floor_id' => $plan['floor2_id'],
                    'available_quantity_flags' => 3,
                    'temperature_type' => 'NORMAL',
                    'is_restricted_area' => 0,
                    'code1' => 'ZZ',
                    'code2' => '1',
                    'code3' => null,
                    'name' => self::NO_LOCATION_DISPLAY_NAME,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'is_created_from_data_transfer' => 1,
                ]);
            }

            $result = [
                'target_location_id' => $targetLocationId,
                'locations_updated' => 0,
            ];

            if (! empty($plan['source_location_ids'])) {
                foreach ($plan['tables'] as $table) {
                    $updated = DB::connection('sakemaru')
                        ->table($table)
                        ->whereIn('location_id', $plan['source_location_ids'])
                        ->update([
                            'location_id' => $targetLocationId,
                            'updated_at' => $now,
                        ]);

                    $result["{$table}_updated"] = $updated;
                }
            }

            $locationIdsToHide = $plan['location_ids_to_hide'];
            if (! in_array($targetLocationId, $locationIdsToHide, true)) {
                $locationIdsToHide[] = $targetLocationId;
            }

            $result['locations_updated'] = DB::connection('sakemaru')
                ->table('locations')
                ->whereIn('id', $locationIdsToHide)
                ->update([
                    'available_quantity_flags' => 3,
                    'name' => self::NO_LOCATION_DISPLAY_NAME,
                    'updated_at' => $now,
                ]);

            DB::connection('sakemaru')
                ->table('locations')
                ->where('id', $targetLocationId)
                ->update([
                    'floor_id' => $plan['floor2_id'],
                    'updated_at' => $now,
                ]);

            return $result;
        });
    }

    private function writeCsv(array $rows): string
    {
        $path = $this->option('output') ?: storage_path('reports/'.now()->format('Ymd-His').'-no-location-zz1-patch.csv');
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $handle = fopen($path, 'w');
        fputcsv($handle, ['type', 'table', 'target_location_id', 'source_location_ids', 'rows', 'message']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }
}
