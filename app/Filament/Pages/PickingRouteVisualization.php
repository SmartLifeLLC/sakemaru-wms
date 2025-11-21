<?php

namespace App\Filament\Pages;

use App\Enums\EMenuCategory;
use App\Models\Sakemaru\Warehouse;
use App\Models\Sakemaru\Floor;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\WmsWarehouseLayout;
use App\Models\WmsPickingTask;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Illuminate\Support\Carbon;

class PickingRouteVisualization extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-map-pin';

    protected string $view = 'filament.pages.picking-route-visualization';

    protected static \UnitEnum|string|null $navigationGroup = null;

    // Livewire properties
    public ?int $selectedWarehouseId = null;
    public ?int $selectedFloorId = null;
    public ?int $selectedDeliveryCourseId = null;
    public ?int $selectedPickingTaskId = null;
    public ?string $selectedDate = null;
    public int $canvasWidth = 2000;
    public int $canvasHeight = 1500;
    public array $colors = [];
    public array $textStyles = [];
    public array $walls = [];
    public array $fixedAreas = [];
    public int $pickingStartX = 0;
    public int $pickingStartY = 0;
    public int $pickingEndX = 0;
    public int $pickingEndY = 0;

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::OUTBOUND->label();
    }

    public static function getNavigationLabel(): string
    {
        return 'ピッキング経路確認';
    }

    public static function getNavigationSort(): ?int
    {
        return 60;
    }

    public function getTitle(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return true;
    }

    /**
     * Mount - Initialize component
     */
    public function mount(): void
    {
        // Initialize with defaults
        $this->colors = WmsWarehouseLayout::getDefaultColors();
        $this->textStyles = WmsWarehouseLayout::getDefaultTextStyles();
        $this->selectedDate = now()->format('Y-m-d');

        // Set default warehouse if available
        $firstWarehouse = Warehouse::where('is_active', true)->orderBy('code')->first();
        if ($firstWarehouse) {
            $this->selectedWarehouseId = $firstWarehouse->id;

            // Set default floor if available
            $firstFloor = Floor::where('warehouse_id', $this->selectedWarehouseId)
                ->where('is_active', true)
                ->orderBy('code')
                ->first();

            if ($firstFloor) {
                $this->selectedFloorId = $firstFloor->id;
            }

            $this->loadLayout();

            // Auto-select first delivery course for today
            $firstDeliveryCourse = WmsPickingTask::where('warehouse_id', $this->selectedWarehouseId)
                ->whereDate('shipment_date', $this->selectedDate)
                ->whereNotNull('delivery_course_id')
                ->orderBy('delivery_course_id')
                ->value('delivery_course_id');

            if ($firstDeliveryCourse) {
                $this->selectedDeliveryCourseId = $firstDeliveryCourse;

                // Auto-select first picking task for the selected delivery course
                $firstTask = WmsPickingTask::where('warehouse_id', $this->selectedWarehouseId)
                    ->where('floor_id', $this->selectedFloorId)
                    ->whereDate('shipment_date', $this->selectedDate)
                    ->where('delivery_course_id', $firstDeliveryCourse)
                    ->orderBy('id')
                    ->first();

                if ($firstTask) {
                    $this->selectedPickingTaskId = $firstTask->id;
                }
            }
        }
    }

    /**
     * Load layout from database
     */
    public function loadLayout(): void
    {
        if (!$this->selectedWarehouseId) {
            return;
        }

        // Load layout for the selected warehouse and floor
        $layout = WmsWarehouseLayout::where('warehouse_id', $this->selectedWarehouseId)
            ->where('floor_id', $this->selectedFloorId)
            ->first();

        if ($layout) {
            $this->canvasWidth = $layout->width;
            $this->canvasHeight = $layout->height;
            $this->colors = $layout->colors ?? WmsWarehouseLayout::getDefaultColors();
            $this->textStyles = $layout->text_styles ?? WmsWarehouseLayout::getDefaultTextStyles();
            $this->walls = $layout->walls ?? [];
            $this->fixedAreas = $layout->fixed_areas ?? [];
            $this->pickingStartX = $layout->picking_start_x ?? 0;
            $this->pickingStartY = $layout->picking_start_y ?? 0;
            $this->pickingEndX = $layout->picking_end_x ?? 0;
            $this->pickingEndY = $layout->picking_end_y ?? 0;
        } else {
            // Reset to defaults if no layout exists
            $this->canvasWidth = 2000;
            $this->canvasHeight = 1500;
            $this->colors = WmsWarehouseLayout::getDefaultColors();
            $this->textStyles = WmsWarehouseLayout::getDefaultTextStyles();
            $this->walls = [];
            $this->fixedAreas = [];
            $this->pickingStartX = 0;
            $this->pickingStartY = 0;
            $this->pickingEndX = 0;
            $this->pickingEndY = 0;
        }
    }

    /**
     * Load initial data - called from Alpine.js
     */
    public function loadInitialData(): void
    {
        if ($this->selectedWarehouseId && $this->selectedFloorId) {
            $zones = $this->zones->toArray();
            $this->dispatch('layout-loaded',
                zones: $zones,
                walls: $this->walls,
                fixedAreas: $this->fixedAreas
            );
        }
    }

    /**
     * Get all active warehouses
     */
    #[Computed]
    public function warehouses()
    {
        return Warehouse::where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Get floors for selected warehouse
     */
    #[Computed]
    public function floors()
    {
        if (!$this->selectedWarehouseId) {
            return collect();
        }

        return Floor::where('warehouse_id', $this->selectedWarehouseId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'warehouse_id']);
    }

    /**
     * Get zones (locations) for the selected floor
     */
    #[Computed]
    public function zones()
    {
        if (!$this->selectedFloorId) {
            return collect();
        }

        return \App\Models\Sakemaru\Location::where('floor_id', $this->selectedFloorId)
            ->whereNotNull('x1_pos')
            ->whereNotNull('y1_pos')
            ->whereNotNull('x2_pos')
            ->whereNotNull('y2_pos')
            ->with(['levels' => function ($query) {
                $query->orderBy('level_number', 'asc');
            }])
            ->get()
            ->map(function ($location) {
                return [
                    'id' => $location->id,
                    'name' => trim("{$location->code1} {$location->code2}"),
                    'x1' => $location->x1_pos,
                    'y1' => $location->y1_pos,
                    'x2' => $location->x2_pos,
                    'y2' => $location->y2_pos,
                ];
            });
    }

    /**
     * Get available delivery courses for the selected warehouse and date
     */
    #[Computed]
    public function deliveryCourses()
    {
        if (!$this->selectedWarehouseId || !$this->selectedDate) {
            return collect();
        }

        // Get delivery courses from picking tasks for the selected warehouse and date
        $taskCourseIds = WmsPickingTask::where('warehouse_id', $this->selectedWarehouseId)
            ->whereDate('shipment_date', $this->selectedDate)
            ->whereNotNull('delivery_course_id')
            ->distinct()
            ->pluck('delivery_course_id');

        return DeliveryCourse::whereIn('id', $taskCourseIds)
            ->orderBy('code')
            ->get()
            ->map(function ($course) {
                return [
                    'id' => $course->id,
                    'code' => $course->code,
                    'name' => $course->name,
                ];
            });
    }

    /**
     * Get picking tasks for the selected criteria
     * 完了したタスクも含めて全て表示
     */
    #[Computed]
    public function pickingTasks()
    {
        if (!$this->selectedWarehouseId || !$this->selectedDate || !$this->selectedDeliveryCourseId) {
            return collect();
        }

        $query = WmsPickingTask::where('warehouse_id', $this->selectedWarehouseId)
            ->whereDate('shipment_date', $this->selectedDate)
            ->where('delivery_course_id', $this->selectedDeliveryCourseId);

        // フロアが選択されている場合のみフィルタ
        if ($this->selectedFloorId) {
            $query->where('floor_id', $this->selectedFloorId);
        }

        return $query
            // ステータスフィルタなし - 全てのタスクを表示（PENDING, PICKING, COMPLETED）
            ->with('picker:id,code,name')
            ->orderBy('id')
            ->get()
            ->map(function ($task) {
                $statusLabel = match($task->status) {
                    'PENDING' => '待機中',
                    'PICKING' => 'ピッキング中',
                    'COMPLETED' => '完了',
                    default => $task->status,
                };

                return [
                    'id' => $task->id,
                    'status' => $task->status,
                    'status_label' => $statusLabel,
                    'picker_name' => $task->picker ? "{$task->picker->code} - {$task->picker->name}" : null,
                ];
            });
    }

    /**
     * Called when warehouse changes
     */
    public function updatedSelectedWarehouseId(): void
    {
        $this->selectedFloorId = null;
        $this->selectedDeliveryCourseId = null;

        if ($this->selectedWarehouseId) {
            // Auto-select first floor
            $firstFloor = Floor::where('warehouse_id', $this->selectedWarehouseId)
                ->where('is_active', true)
                ->orderBy('code')
                ->first();

            if ($firstFloor) {
                $this->selectedFloorId = $firstFloor->id;
            }

            $this->loadLayout();
        }
    }

    /**
     * Called when floor changes
     */
    public function updatedSelectedFloorId(): void
    {
        $this->loadLayout();
        $this->loadInitialData();
    }

    /**
     * Called when delivery course changes
     */
    public function updatedSelectedDeliveryCourseId(): void
    {
        // Reset picking task selection
        $this->selectedPickingTaskId = null;

        if ($this->selectedDeliveryCourseId && $this->selectedWarehouseId && $this->selectedFloorId) {
            // Auto-select first picking task for the selected delivery course
            $firstTask = WmsPickingTask::where('warehouse_id', $this->selectedWarehouseId)
                ->where('floor_id', $this->selectedFloorId)
                ->whereDate('shipment_date', $this->selectedDate)
                ->where('delivery_course_id', $this->selectedDeliveryCourseId)
                ->orderBy('id')
                ->first();

            if ($firstTask) {
                $this->selectedPickingTaskId = $firstTask->id;
            }
        }

        // Trigger route visualization update
        $this->dispatch('delivery-course-changed', courseId: $this->selectedDeliveryCourseId);
    }

    /**
     * Called when date changes
     */
    public function updatedSelectedDate(): void
    {
        $this->selectedDeliveryCourseId = null;
        $this->selectedPickingTaskId = null;
        $this->dispatch('date-changed', date: $this->selectedDate);
    }

    /**
     * Called when picking task changes
     */
    public function updatedSelectedPickingTaskId(): void
    {
        // Trigger route visualization update
        if ($this->selectedPickingTaskId) {
            $this->dispatch('picking-task-changed', taskId: $this->selectedPickingTaskId);
        }
    }

    /**
     * Update walking order for picking items
     */
    public function updateWalkingOrder(array $itemIds): void
    {
        if (!$this->selectedWarehouseId || !$this->selectedFloorId || !$this->selectedDate || !$this->selectedDeliveryCourseId) {
            return;
        }

        // Get picking tasks for this delivery course
        $tasks = WmsPickingTask::where('warehouse_id', $this->selectedWarehouseId)
            ->whereDate('shipment_date', $this->selectedDate)
            ->where('delivery_course_id', $this->selectedDeliveryCourseId)
            ->where('status', 'PENDING') // Only allow reordering for PENDING tasks
            ->get();

        if ($tasks->isEmpty()) {
            $this->dispatch('reorder-failed', message: 'PENDINGステータスのタスクのみ並び替え可能です');
            return;
        }

        $taskIds = $tasks->pluck('id')->toArray();

        // Update walking_order for each item based on new position
        \DB::connection('sakemaru')->transaction(function () use ($itemIds, $taskIds) {
            foreach ($itemIds as $index => $itemId) {
                \DB::connection('sakemaru')
                    ->table('wms_picking_item_results')
                    ->where('id', $itemId)
                    ->whereIn('picking_task_id', $taskIds)
                    ->update([
                        'walking_order' => $index + 1,
                        'updated_at' => now(),
                    ]);
            }
        });

        // Notify frontend to reload the route
        $this->dispatch('walking-order-updated');
    }

    /**
     * Recalculate picking route using A* algorithm
     */
    public function recalculatePickingRoute(int $taskId): void
    {
        $task = WmsPickingTask::with('pickingItemResults')->find($taskId);

        if (!$task) {
            \Filament\Notifications\Notification::make()
                ->title('タスクが見つかりません')
                ->danger()
                ->send();
            return;
        }

        if ($task->status !== 'PENDING') {
            \Filament\Notifications\Notification::make()
                ->title('PENDINGステータスのタスクのみ再計算可能です')
                ->warning()
                ->send();
            return;
        }

        // Get floor ID from task itself
        $floorId = $task->floor_id;

        if (!$floorId) {
            \Filament\Notifications\Notification::make()
                ->title('フロア情報が見つかりません')
                ->body('このタスクにはフロアIDが設定されていません')
                ->danger()
                ->send();
            return;
        }

        // Get all picking item IDs
        $itemIds = $task->pickingItemResults->pluck('id')->toArray();

        if (empty($itemIds)) {
            \Filament\Notifications\Notification::make()
                ->title('ピッキングアイテムがありません')
                ->warning()
                ->send();
            return;
        }

        // Optimize using PickRouteService
        $service = new \App\Services\Picking\PickRouteService();
        $result = $service->updateWalkingOrder($itemIds, $task->warehouse_id, $floorId, $taskId);

        if ($result['success']) {
            \Filament\Notifications\Notification::make()
                ->title('経路再計算完了')
                ->body("更新: {$result['updated']}件 / 総距離: {$result['total_distance']}px / ロケーション数: {$result['location_count']} / 計算時間: {$result['calculation_time_ms']}ms")
                ->success()
                ->send();

            // Notify frontend to reload
            $this->dispatch('walking-order-updated');
        } else {
            \Filament\Notifications\Notification::make()
                ->title('経路再計算失敗')
                ->body($result['message'] ?? '不明なエラー')
                ->danger()
                ->send();
        }
    }
}