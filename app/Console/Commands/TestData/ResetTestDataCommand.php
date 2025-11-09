<?php

namespace App\Console\Commands\TestData;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetTestDataCommand extends Command
{
    protected $signature = 'testdata:reset
                            {--warehouse-id= : Warehouse ID to limit deletion scope}
                            {--waves : Delete waves, picking tasks, and reservations}
                            {--earnings : Delete earnings data}
                            {--stocks : Delete stock data (real_stocks, wms_real_stocks)}
                            {--locations : Delete locations}
                            {--floors : Delete floors}
                            {--all : Delete all test data}';

    protected $description = 'Reset WMS test data';

    private ?int $warehouseId = null;

    public function handle()
    {
        $this->info('🗑️  Resetting test data...');
        $this->newLine();

        $this->warehouseId = $this->option('warehouse-id') ? (int) $this->option('warehouse-id') : null;

        $deleteWaves = $this->option('waves');
        $deleteEarnings = $this->option('earnings');
        $deleteStocks = $this->option('stocks');
        $deleteLocations = $this->option('locations');
        $deleteFloors = $this->option('floors');
        $all = $this->option('all');

        // If --all is specified, delete everything
        if ($all) {
            $deleteWaves = true;
            $deleteEarnings = true;
            $deleteStocks = true;
            $deleteLocations = true;
            $deleteFloors = true;
        }

        // If nothing specified, show error
        if (!$deleteWaves && !$deleteEarnings && !$deleteStocks && !$deleteLocations && !$deleteFloors) {
            $this->error('Please specify what to delete using options: --waves, --earnings, --stocks, --locations, --floors, or --all');
            return 1;
        }

        if ($this->warehouseId) {
            $this->line("Scope: Warehouse ID {$this->warehouseId}");
        } else {
            $this->line("Scope: All warehouses");
        }
        $this->newLine();

        try {
            $totalDeleted = 0;

            // Delete in reverse order of creation to avoid foreign key issues
            if ($deleteWaves) {
                $totalDeleted += $this->deleteWaves();
            }

            if ($deleteEarnings) {
                $totalDeleted += $this->deleteEarnings();
            }

            if ($deleteStocks) {
                $totalDeleted += $this->deleteStocks();
            }

            if ($deleteLocations) {
                $totalDeleted += $this->deleteLocations();
            }

            if ($deleteFloors) {
                $totalDeleted += $this->deleteFloors();
            }

            $this->newLine();
            $this->info("✅ Deleted {$totalDeleted} records total");
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Error resetting data: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }

    private function deleteWaves(): int
    {
        $this->line('🗑️  Deleting waves, picking tasks, and reservations...');
        $count = 0;

        // Delete wms_reservations
        $query = DB::connection('sakemaru')->table('wms_reservations');
        if ($this->warehouseId) {
            $query->where('warehouse_id', $this->warehouseId);
        }
        $deleted = $query->delete();
        $count += $deleted;
        $this->line("  Deleted {$deleted} wms_reservations");

        // Delete wms_picking_item_results
        $query = DB::connection('sakemaru')->table('wms_picking_item_results as wpir')
            ->join('wms_picking_tasks as wpt', 'wpir.picking_task_id', '=', 'wpt.id');
        if ($this->warehouseId) {
            $query->where('wpt.warehouse_id', $this->warehouseId);
        }
        $deleted = $query->delete();
        $count += $deleted;
        $this->line("  Deleted {$deleted} wms_picking_item_results");

        // Delete wms_picking_tasks
        $query = DB::connection('sakemaru')->table('wms_picking_tasks');
        if ($this->warehouseId) {
            $query->where('warehouse_id', $this->warehouseId);
        }
        $deleted = $query->delete();
        $count += $deleted;
        $this->line("  Deleted {$deleted} wms_picking_tasks");

        // Delete wms_waves
        if ($this->warehouseId) {
            // Join with wms_wave_settings to filter by warehouse
            $deleted = DB::connection('sakemaru')->table('wms_waves as ww')
                ->join('wms_wave_settings as wws', 'ww.wms_wave_setting_id', '=', 'wws.id')
                ->where('wws.warehouse_id', $this->warehouseId)
                ->delete();
        } else {
            $deleted = DB::connection('sakemaru')->table('wms_waves')->delete();
        }
        $count += $deleted;
        $this->line("  Deleted {$deleted} wms_waves");

        return $count;
    }

    private function deleteEarnings(): int
    {
        $this->line('🗑️  Deleting earnings...');
        $count = 0;

        // Get warehouse code if specified
        $warehouseCode = null;
        if ($this->warehouseId) {
            $warehouse = DB::connection('sakemaru')->table('warehouses')->find($this->warehouseId);
            $warehouseCode = $warehouse ? $warehouse->code : null;
        }

        // Delete earning_details first
        $query = DB::connection('sakemaru')->table('earning_details as ed')
            ->join('earnings as e', 'ed.earning_id', '=', 'e.id');
        if ($warehouseCode) {
            $query->where('e.warehouse_code', $warehouseCode);
        }
        // Only delete test earnings (created via API with specific note pattern)
        $query->where('e.note', 'like', 'WMSテスト:%');
        $deleted = $query->delete();
        $count += $deleted;
        $this->line("  Deleted {$deleted} earning_details");

        // Delete earnings
        $query = DB::connection('sakemaru')->table('earnings');
        if ($warehouseCode) {
            $query->where('warehouse_code', $warehouseCode);
        }
        $query->where('note', 'like', 'WMSテスト:%');
        $deleted = $query->delete();
        $count += $deleted;
        $this->line("  Deleted {$deleted} earnings");

        return $count;
    }

    private function deleteStocks(): int
    {
        $this->line('🗑️  Deleting stocks...');
        $count = 0;

        // Get real_stock IDs to delete
        $query = DB::connection('sakemaru')->table('real_stocks');
        if ($this->warehouseId) {
            $query->where('warehouse_id', $this->warehouseId);
        }
        $realStockIds = $query->pluck('id')->toArray();

        if (empty($realStockIds)) {
            $this->line("  No stocks to delete");
            return 0;
        }

        // Delete wms_real_stocks first (foreign key to real_stocks)
        $deleted = DB::connection('sakemaru')->table('wms_real_stocks')
            ->whereIn('real_stock_id', $realStockIds)
            ->delete();
        $count += $deleted;
        $this->line("  Deleted {$deleted} wms_real_stocks");

        // Delete real_stocks
        $query = DB::connection('sakemaru')->table('real_stocks');
        if ($this->warehouseId) {
            $query->where('warehouse_id', $this->warehouseId);
        }
        $deleted = $query->delete();
        $count += $deleted;
        $this->line("  Deleted {$deleted} real_stocks");

        return $count;
    }

    private function deleteLocations(): int
    {
        $this->line('🗑️  Deleting locations...');
        $count = 0;

        // Delete wms_locations first
        $query = DB::connection('sakemaru')->table('wms_locations as wl')
            ->join('locations as l', 'wl.location_id', '=', 'l.id');
        if ($this->warehouseId) {
            $query->where('l.warehouse_id', $this->warehouseId);
        }
        $deleted = $query->delete();
        $count += $deleted;
        $this->line("  Deleted {$deleted} wms_locations");

        // Delete locations
        $query = DB::connection('sakemaru')->table('locations');
        if ($this->warehouseId) {
            $query->where('warehouse_id', $this->warehouseId);
        }
        $deleted = $query->delete();
        $count += $deleted;
        $this->line("  Deleted {$deleted} locations");

        return $count;
    }

    private function deleteFloors(): int
    {
        $this->line('🗑️  Deleting floors...');

        $query = DB::connection('sakemaru')->table('floors');
        if ($this->warehouseId) {
            $query->where('warehouse_id', $this->warehouseId);
        }
        $deleted = $query->delete();
        $this->line("  Deleted {$deleted} floors");

        return $deleted;
    }
}
