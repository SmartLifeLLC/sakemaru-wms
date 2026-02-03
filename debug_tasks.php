<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\WmsPickingTask;

echo "Checking WmsPickingTasks...\n";
$tasks = WmsPickingTask::latest()->take(10)->get();

if ($tasks->isEmpty()) {
    echo "No tasks found.\n";
} else {
    foreach ($tasks as $task) {
        echo "Task ID: {$task->id}, Warehouse: {$task->warehouse_id}, Floor: ".($task->floor_id ?? 'NULL').", Course: {$task->delivery_course_id}, Date: {$task->shipment_date->format('Y-m-d')}\n";
    }
}

echo "\nChecking Counts:\n";
$count = WmsPickingTask::count();
echo "Total Tasks: $count\n";

$nullFloorCount = WmsPickingTask::whereNull('floor_id')->count();
echo "Tasks with NULL floor_id: $nullFloorCount\n";
