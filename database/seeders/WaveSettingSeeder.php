<?php

namespace Database\Seeders;

use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Warehouse;
use App\Models\WaveSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WaveSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing wave settings
        WaveSetting::query()->delete();
        $this->command->info('Cleared existing wave settings');

        // Get all warehouses
        $warehouses = Warehouse::all();

        if ($warehouses->isEmpty()) {
            $this->command->warn('No warehouses found. Please seed warehouses first.');
            return;
        }

        $this->command->info("Found {$warehouses->count()} warehouses");

        // Define sample time slots (1-hour intervals from 09:00 to 18:00)
        $timeSlots = [
            ['start' => '09:00:00', 'deadline' => '10:00:00'],
            ['start' => '10:00:00', 'deadline' => '11:00:00'],
            ['start' => '11:00:00', 'deadline' => '12:00:00'],
            ['start' => '12:00:00', 'deadline' => '13:00:00'],
            ['start' => '13:00:00', 'deadline' => '14:00:00'],
            ['start' => '14:00:00', 'deadline' => '15:00:00'],
            ['start' => '15:00:00', 'deadline' => '16:00:00'],
            ['start' => '16:00:00', 'deadline' => '17:00:00'],
            ['start' => '17:00:00', 'deadline' => '18:00:00'],
        ];

        $totalSettings = 0;

        foreach ($warehouses as $warehouse) {
            // Get delivery courses for this warehouse
            $deliveryCourses = DeliveryCourse::where('warehouse_id', $warehouse->id)->get();

            if ($deliveryCourses->isEmpty()) {
                $warehouseName = $warehouse->name ?? 'N/A';
                $this->command->warn("No delivery courses found for warehouse {$warehouse->id} ({$warehouseName})");
                continue;
            }

            $this->command->info("  Warehouse {$warehouse->id}: {$deliveryCourses->count()} delivery courses");

            foreach ($deliveryCourses as $index => $course) {
                // Use a different time slot for each delivery course (cycling through available slots)
                $slot = $timeSlots[$index % count($timeSlots)];

                WaveSetting::create([
                    'warehouse_id' => $warehouse->id,
                    'delivery_course_id' => $course->id,
                    'picking_start_time' => $slot['start'],
                    'picking_deadline_time' => $slot['deadline'],
                    'creator_id' => 1,
                    'last_updater_id' => 1,
                ]);

                $totalSettings++;
            }
        }

        $this->command->info("Created {$totalSettings} wave settings");
    }
}
