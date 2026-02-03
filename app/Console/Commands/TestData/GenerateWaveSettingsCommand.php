<?php

namespace App\Console\Commands\TestData;

use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Warehouse;
use App\Models\WaveSetting;
use Illuminate\Console\Command;

class GenerateWaveSettingsCommand extends Command
{
    protected $signature = 'testdata:wave-settings
                            {--warehouse-id= : Specific warehouse ID (optional, generates for all active warehouses if not specified)}
                            {--reset : Delete all existing wave settings before generating}';

    protected $description = 'Generate WMS wave settings for all warehouses and delivery courses (1-hour intervals)';

    public function handle()
    {
        $warehouseId = $this->option('warehouse-id');
        $shouldReset = $this->option('reset');

        $this->info('Generating Wave Settings...');
        $this->newLine();

        // Reset if requested
        if ($shouldReset) {
            $this->warn('⚠️  Resetting all wave settings...');
            $deletedCount = WaveSetting::query()->delete();
            $this->info("✓ Deleted {$deletedCount} existing wave settings");
            $this->newLine();
        }

        // Get warehouses
        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->when($warehouseId, fn ($q) => $q->where('id', $warehouseId))
            ->orderBy('code')
            ->get();

        if ($warehouses->isEmpty()) {
            $this->error('No active warehouses found!');

            return 1;
        }

        $this->info("Found {$warehouses->count()} warehouse(s)");

        // Define time slots (1-hour intervals, full 24 hours for testing)
        $timeSlots = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $nextHour = ($hour + 1) % 24;
            $timeSlots[] = [
                'start' => sprintf('%02d:00:00', $hour),
                'deadline' => sprintf('%02d:00:00', $nextHour),
            ];
        }

        $totalCreated = 0;

        foreach ($warehouses as $warehouse) {
            $this->line("Processing Warehouse [{$warehouse->code}] {$warehouse->name}...");

            // Get delivery courses for this warehouse
            $courses = DeliveryCourse::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('is_active', true)
                ->orderBy('code')
                ->get();

            if ($courses->isEmpty()) {
                $this->warn("  ⚠️  No active delivery courses found for warehouse {$warehouse->code}");

                continue;
            }

            $this->info("  Found {$courses->count()} delivery course(s)");

            foreach ($courses as $course) {
                foreach ($timeSlots as $slot) {
                    // Check if setting already exists
                    $exists = WaveSetting::query()
                        ->where('warehouse_id', $warehouse->id)
                        ->where('delivery_course_id', $course->id)
                        ->where('picking_start_time', $slot['start'])
                        ->exists();

                    if ($exists) {
                        $this->line("    ⏭️  Skipped: Course {$course->code} @ {$slot['start']} (already exists)");

                        continue;
                    }

                    // Create wave setting
                    WaveSetting::create([
                        'warehouse_id' => $warehouse->id,
                        'delivery_course_id' => $course->id,
                        'picking_start_time' => $slot['start'],
                        'picking_deadline_time' => $slot['deadline'],
                        'creator_id' => 0,
                        'last_updater_id' => 0,
                    ]);

                    $totalCreated++;
                }

                $this->info("    ✓ Created settings for Course [{$course->code}] {$course->name}");
            }

            $this->newLine();
        }

        $this->info('Wave settings generation completed!');
        $this->info("Total created: {$totalCreated} settings");

        return 0;
    }
}
