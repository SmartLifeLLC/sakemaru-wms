<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PatchAwLocationShiftCommand extends Command
{
    protected $signature = 'stock:patch-aw-location-shift
                            {--warehouse-code=91 : 対象倉庫CD}
                            {--output= : CSV output path. Defaults to storage/reports/current timestamp}
                            {--apply : 実際にlocation_idを更新する}';

    protected $description = 'Move shifted AW-0-000 location references to the canonical AW-0 location.';

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

        $target = $this->resolveLocation((int) $warehouse->id, null);
        $source = $this->resolveLocation((int) $warehouse->id, '000');

        if (! $target || ! $source) {
            $this->error('AW target/source location could not be resolved.');
            $this->line('target_location_id='.($target?->id ?? ''));
            $this->line('source_location_id='.($source?->id ?? ''));

            return self::FAILURE;
        }

        $plan = $this->buildPlan((int) $warehouse->id, (int) $warehouse->code, (int) $target->id, (int) $source->id);
        $outputPath = $this->writeCsv($plan['rows']);

        foreach ($plan['summary'] as $key => $value) {
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

    private function resolveLocation(int $warehouseId, ?string $code3): ?object
    {
        return DB::connection('sakemaru')
            ->table('locations')
            ->where('warehouse_id', $warehouseId)
            ->where('code1', 'AW')
            ->where('code2', '0')
            ->when($code3 === null, fn ($query) => $query->whereNull('code3'))
            ->when($code3 !== null, fn ($query) => $query->where('code3', $code3))
            ->orderBy('id')
            ->first(['id', 'warehouse_id', 'floor_id', 'code1', 'code2', 'code3', 'name']);
    }

    private function buildPlan(int $warehouseId, int $warehouseCode, int $targetLocationId, int $sourceLocationId): array
    {
        $tables = $this->locationReferenceTables();
        $rows = [];
        $summary = [
            'warehouse_code' => $warehouseCode,
            'warehouse_id' => $warehouseId,
            'target_location_id' => $targetLocationId,
            'source_location_id' => $sourceLocationId,
        ];

        foreach ($tables as $table) {
            $count = DB::connection('sakemaru')
                ->table($table)
                ->where('location_id', $sourceLocationId)
                ->count();

            $summary["{$table}_updates"] = $count;
            $rows[] = [
                'type' => 'reference_update',
                'table' => $table,
                'source_location_id' => $sourceLocationId,
                'target_location_id' => $targetLocationId,
                'rows' => $count,
                'message' => '',
            ];
        }

        return [
            'target_location_id' => $targetLocationId,
            'source_location_id' => $sourceLocationId,
            'tables' => $tables,
            'rows' => $rows,
            'summary' => $summary,
        ];
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
            $result = [];

            foreach ($plan['tables'] as $table) {
                $updated = DB::connection('sakemaru')
                    ->table($table)
                    ->where('location_id', $plan['source_location_id'])
                    ->update([
                        'location_id' => $plan['target_location_id'],
                        'updated_at' => $now,
                    ]);

                $result["{$table}_updated"] = $updated;
            }

            return $result;
        });
    }

    private function writeCsv(array $rows): string
    {
        $path = $this->option('output') ?: storage_path('reports/'.now()->format('Ymd-His').'-aw-location-shift-patch.csv');
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $handle = fopen($path, 'w');
        fputcsv($handle, ['type', 'table', 'source_location_id', 'target_location_id', 'rows', 'message']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }
}
