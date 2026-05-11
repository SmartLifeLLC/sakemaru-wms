<?php

namespace App\Console\Commands;

use App\Models\WmsPickingTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupFinishedStockTransferPickingTasksCommand extends Command
{
    protected $signature = 'wms:cleanup-finished-stock-transfer-picking-tasks
                            {--warehouse-code= : 対象倉庫CD。未指定なら全倉庫}
                            {--shipment-date= : 対象出荷日 YYYY-MM-DD。未指定なら全日}
                            {--output= : CSV output path. Defaults to storage/reports/current timestamp}
                            {--apply : 実際にwms_picking_tasksを終端状態へ更新する}';

    protected $description = 'Hide stale transfer-only picking tasks whose stock transfers are already terminal.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('DRY RUN: no rows will be changed. Add --apply to patch data.');
        }

        $rows = $this->buildRows();
        $outputPath = $this->writeCsv($rows);

        $this->line('target_tasks='.count($rows));
        $this->line('target_shipped_tasks='.collect($rows)->where('new_status', WmsPickingTask::STATUS_SHIPPED)->count());
        $this->line('target_completed_tasks='.collect($rows)->where('new_status', WmsPickingTask::STATUS_COMPLETED)->count());
        $this->info("CSV: {$outputPath}");

        if ($apply && ! empty($rows)) {
            $result = $this->applyCleanup($rows);
            foreach ($result as $key => $value) {
                $this->line("{$key}={$value}");
            }
            $this->info('Cleanup complete.');
        }

        return self::SUCCESS;
    }

    private function buildRows(): array
    {
        $warehouseCode = $this->option('warehouse-code');
        $shipmentDate = $this->option('shipment-date');

        $query = DB::connection('sakemaru')
            ->table('wms_picking_tasks as pt')
            ->join('wms_picking_item_results as pir', 'pir.picking_task_id', '=', 'pt.id')
            ->leftJoin('stock_transfers as st', 'st.id', '=', 'pir.stock_transfer_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'pt.warehouse_id')
            ->whereIn('pt.status', [
                WmsPickingTask::STATUS_PENDING,
                WmsPickingTask::STATUS_PICKING_READY,
                WmsPickingTask::STATUS_PICKING,
                WmsPickingTask::STATUS_COMPLETED,
                WmsPickingTask::STATUS_SHORTAGE,
            ])
            ->when($warehouseCode !== null && $warehouseCode !== '', fn ($query) => $query->where('w.code', (int) $warehouseCode))
            ->when($shipmentDate !== null && $shipmentDate !== '', fn ($query) => $query->whereDate('pt.shipment_date', $shipmentDate))
            ->groupBy('pt.id', 'pt.status', 'pt.warehouse_id', 'w.code', 'pt.shipment_date', 'pt.wave_id', 'pt.delivery_course_id')
            ->selectRaw('
                pt.id as task_id,
                pt.status as old_status,
                pt.warehouse_id,
                w.code as warehouse_code,
                pt.shipment_date,
                pt.wave_id,
                pt.delivery_course_id,
                COUNT(DISTINCT CASE WHEN pir.stock_transfer_id IS NOT NULL THEN pir.stock_transfer_id END) as transfer_count,
                SUM(CASE WHEN pir.stock_transfer_id IS NOT NULL THEN 1 ELSE 0 END) as transfer_item_rows,
                SUM(CASE WHEN pir.earning_id IS NOT NULL OR pir.source_type = "EARNING" THEN 1 ELSE 0 END) as earning_item_rows,
                SUM(CASE
                    WHEN pir.stock_transfer_id IS NOT NULL
                     AND COALESCE(st.picking_status, "BEFORE") NOT IN ("COMPLETED", "SHIPPED")
                     AND COALESCE(st.is_confirmed, 0) = 0
                     AND COALESCE(st.is_delivered, 0) = 0
                    THEN 1 ELSE 0 END
                ) as active_transfer_item_rows,
                SUM(CASE
                    WHEN pir.stock_transfer_id IS NOT NULL
                     AND (st.picking_status = "SHIPPED" OR COALESCE(st.is_confirmed, 0) = 1 OR COALESCE(st.is_delivered, 0) = 1)
                    THEN 1 ELSE 0 END
                ) as shipped_like_item_rows,
                GROUP_CONCAT(DISTINCT pir.stock_transfer_id ORDER BY pir.stock_transfer_id SEPARATOR ",") as stock_transfer_ids,
                GROUP_CONCAT(DISTINCT st.picking_status ORDER BY st.picking_status SEPARATOR ",") as stock_transfer_statuses,
                GROUP_CONCAT(DISTINCT st.is_confirmed ORDER BY st.is_confirmed SEPARATOR ",") as stock_transfer_confirmed_flags,
                GROUP_CONCAT(DISTINCT st.is_delivered ORDER BY st.is_delivered SEPARATOR ",") as stock_transfer_delivered_flags
            ')
            ->havingRaw('transfer_item_rows > 0')
            ->havingRaw('earning_item_rows = 0')
            ->havingRaw('active_transfer_item_rows = 0')
            ->orderBy('pt.shipment_date')
            ->orderBy('pt.id');

        return $query->get()
            ->map(function ($row): array {
                $newStatus = ((int) $row->shipped_like_item_rows) > 0
                    ? WmsPickingTask::STATUS_SHIPPED
                    : WmsPickingTask::STATUS_COMPLETED;

                return [
                    'task_id' => (int) $row->task_id,
                    'old_status' => (string) $row->old_status,
                    'new_status' => $newStatus,
                    'warehouse_id' => (int) $row->warehouse_id,
                    'warehouse_code' => (string) $row->warehouse_code,
                    'shipment_date' => (string) $row->shipment_date,
                    'wave_id' => (string) ($row->wave_id ?? ''),
                    'delivery_course_id' => (string) ($row->delivery_course_id ?? ''),
                    'transfer_count' => (int) $row->transfer_count,
                    'stock_transfer_ids' => (string) ($row->stock_transfer_ids ?? ''),
                    'stock_transfer_statuses' => (string) ($row->stock_transfer_statuses ?? ''),
                    'stock_transfer_confirmed_flags' => (string) ($row->stock_transfer_confirmed_flags ?? ''),
                    'stock_transfer_delivered_flags' => (string) ($row->stock_transfer_delivered_flags ?? ''),
                ];
            })
            ->all();
    }

    private function applyCleanup(array $rows): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($rows) {
            $now = now();
            $taskUpdates = 0;
            $itemUpdates = 0;
            $transferUpdates = 0;

            foreach ($rows as $row) {
                $taskUpdates += DB::connection('sakemaru')
                    ->table('wms_picking_tasks')
                    ->where('id', $row['task_id'])
                    ->where('status', $row['old_status'])
                    ->update([
                        'status' => $row['new_status'],
                        'completed_at' => DB::raw('COALESCE(completed_at, NOW())'),
                        'updated_at' => $now,
                    ]);

                $itemUpdates += DB::connection('sakemaru')
                    ->table('wms_picking_item_results')
                    ->where('picking_task_id', $row['task_id'])
                    ->whereNotNull('stock_transfer_id')
                    ->whereIn('status', ['PENDING', 'PICKING'])
                    ->update([
                        'picked_qty' => DB::raw('CASE WHEN COALESCE(picked_qty, 0) = 0 THEN planned_qty ELSE picked_qty END'),
                        'picked_qty_type' => DB::raw('COALESCE(picked_qty_type, planned_qty_type)'),
                        'status' => 'COMPLETED',
                        'picked_at' => DB::raw('COALESCE(picked_at, NOW())'),
                        'updated_at' => $now,
                    ]);

                $stockTransferIds = collect(explode(',', $row['stock_transfer_ids']))
                    ->map(fn (string $id) => (int) trim($id))
                    ->filter()
                    ->values()
                    ->all();

                if (! empty($stockTransferIds)) {
                    $transferUpdates += DB::connection('sakemaru')
                        ->table('stock_transfers')
                        ->whereIn('id', $stockTransferIds)
                        ->where('picking_status', '!=', 'SHIPPED')
                        ->update([
                            'picking_status' => $row['new_status'] === WmsPickingTask::STATUS_SHIPPED ? 'SHIPPED' : 'COMPLETED',
                            'updated_at' => $now,
                        ]);
                }
            }

            return [
                'tasks_updated' => $taskUpdates,
                'items_updated' => $itemUpdates,
                'stock_transfers_updated' => $transferUpdates,
            ];
        });
    }

    private function writeCsv(array $rows): string
    {
        $path = $this->option('output') ?: storage_path('reports/'.now()->format('Ymd-His').'-finished-stock-transfer-picking-tasks.csv');
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'task_id',
            'old_status',
            'new_status',
            'warehouse_id',
            'warehouse_code',
            'shipment_date',
            'wave_id',
            'delivery_course_id',
            'transfer_count',
            'stock_transfer_ids',
            'stock_transfer_statuses',
            'stock_transfer_confirmed_flags',
            'stock_transfer_delivered_flags',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }
}
