<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AssignPickersToTasksSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Assign pickers to existing picking tasks
     * - Same picker gets multiple tasks (to test filtering by picker_id)
     * - Tasks are distributed across pickers
     */
    public function run(): void
    {
        $warehouseId = $this->command->option('warehouse-id') ?? 991;

        $this->command->info("Assigning pickers to tasks for warehouse {$warehouseId}");

        // Get available pickers
        $pickers = DB::connection('sakemaru')
            ->table('wms_pickers')
            ->where('is_active', 1)
            ->where('code', 'LIKE', 'TEST%')
            ->pluck('id')
            ->toArray();

        if (empty($pickers)) {
            $this->command->error('No test pickers found. Please run WmsPickerSeeder first.');

            return;
        }

        $this->command->info('Found '.count($pickers).' test pickers');

        // Get unassigned picking tasks for this warehouse
        $tasks = DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('warehouse_id', $warehouseId)
            ->whereNull('picker_id')
            ->whereIn('status', ['PENDING', 'PICKING'])
            ->orderBy('id')
            ->get();

        if ($tasks->isEmpty()) {
            $this->command->warn('No unassigned tasks found');

            return;
        }

        $this->command->info("Found {$tasks->count()} unassigned tasks");

        // Assign tasks to pickers in round-robin fashion
        // This ensures each picker gets multiple tasks
        $assignedCount = 0;
        foreach ($tasks as $index => $task) {
            $pickerId = $pickers[$index % count($pickers)];

            DB::connection('sakemaru')
                ->table('wms_picking_tasks')
                ->where('id', $task->id)
                ->update([
                    'picker_id' => $pickerId,
                    'updated_at' => now(),
                ]);

            $assignedCount++;
        }

        $this->command->info("Assigned {$assignedCount} tasks to ".count($pickers).' pickers');

        // Show assignment summary
        $this->command->newLine();
        $this->command->info('Assignment Summary:');
        foreach ($pickers as $pickerId) {
            $taskCount = DB::connection('sakemaru')
                ->table('wms_picking_tasks')
                ->where('picker_id', $pickerId)
                ->count();

            $picker = DB::connection('sakemaru')
                ->table('wms_pickers')
                ->where('id', $pickerId)
                ->first();

            $this->command->line("  - {$picker->name} ({$picker->code}): {$taskCount} tasks");
        }
    }
}
