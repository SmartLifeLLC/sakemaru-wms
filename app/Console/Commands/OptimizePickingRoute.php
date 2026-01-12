<?php

namespace App\Console\Commands;

use App\Models\WmsPickingTask;
use App\Services\Picking\PickRouteService;
use Illuminate\Console\Command;

class OptimizePickingRoute extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wms:optimize-picking-route
                            {--task-id= : Specific picking task ID to optimize}
                            {--warehouse-id= : Warehouse ID (optimize all tasks for this warehouse)}
                            {--date= : Shipping date (YYYY-MM-DD), works with --warehouse-id}
                            {--delivery-course-id= : Delivery course ID filter}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Optimize picking routes using A* algorithm and 2-opt';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $taskId = $this->option('task-id');
        $warehouseId = $this->option('warehouse-id');
        $date = $this->option('date');
        $deliveryCourseId = $this->option('delivery-course-id');

        $pickRouteService = new PickRouteService;

        // Option 1: Optimize specific task
        if ($taskId) {
            $this->optimizeTask($taskId, $pickRouteService);

            return 0;
        }

        // Option 2: Optimize all tasks for warehouse/date/course
        if ($warehouseId) {
            $this->optimizeMultipleTasks($warehouseId, $date, $deliveryCourseId, $pickRouteService);

            return 0;
        }

        $this->error('Please specify either --task-id or --warehouse-id');

        return 1;
    }

    /**
     * Optimize a specific picking task
     */
    private function optimizeTask(int $taskId, PickRouteService $service): void
    {
        $task = WmsPickingTask::with('pickingItemResults.location')->find($taskId);

        if (! $task) {
            $this->error("Task {$taskId} not found");

            return;
        }

        $this->info("Optimizing task {$taskId}...");
        $this->line("Warehouse: {$task->warehouse_id}");
        $this->line("Delivery Course: {$task->delivery_course_id}");
        $this->line("Items: {$task->pickingItemResults->count()}");

        // Get floor ID from first item's location
        $floorId = $task->pickingItemResults->first()?->location?->floor_id;

        if (! $floorId) {
            $this->warn('No floor information found for task items');

            return;
        }

        // Get all picking item IDs
        $itemIds = $task->pickingItemResults->pluck('id')->toArray();

        // Optimize
        $result = $service->updateWalkingOrder($itemIds, $task->warehouse_id, $floorId);

        if ($result['success']) {
            $this->info('✓ Optimization completed');
            $this->line("  Updated: {$result['updated']} items");
            $this->line("  Total distance: {$result['total_distance']} pixels");
            $this->line("  Locations: {$result['location_count']}");
        } else {
            $this->error("Optimization failed: {$result['message']}");
        }
    }

    /**
     * Optimize multiple tasks
     */
    private function optimizeMultipleTasks(
        int $warehouseId,
        ?string $date,
        ?int $deliveryCourseId,
        PickRouteService $service
    ): void {
        $query = WmsPickingTask::where('warehouse_id', $warehouseId)
            ->where('status', 'PENDING'); // Only optimize pending tasks

        if ($date) {
            $query->whereDate('shipment_date', $date);
        }

        if ($deliveryCourseId) {
            $query->where('delivery_course_id', $deliveryCourseId);
        }

        $tasks = $query->get();

        if ($tasks->isEmpty()) {
            $this->warn('No pending tasks found matching criteria');

            return;
        }

        $this->info("Found {$tasks->count()} task(s) to optimize");
        $bar = $this->output->createProgressBar($tasks->count());
        $bar->start();

        $totalUpdated = 0;
        $totalDistance = 0;

        foreach ($tasks as $task) {
            $task->load('pickingItemResults.location');

            $floorId = $task->pickingItemResults->first()?->location?->floor_id;

            if ($floorId) {
                $itemIds = $task->pickingItemResults->pluck('id')->toArray();
                $result = $service->updateWalkingOrder($itemIds, $task->warehouse_id, $floorId);

                if ($result['success']) {
                    $totalUpdated += $result['updated'];
                    $totalDistance += $result['total_distance'];
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('✓ Optimization completed');
        $this->line("  Tasks processed: {$tasks->count()}");
        $this->line("  Items updated: {$totalUpdated}");
        $this->line("  Total distance: {$totalDistance} pixels");
    }
}
