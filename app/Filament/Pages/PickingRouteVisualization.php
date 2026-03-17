<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Floor;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsPickingArea;
use App\Models\WmsPickingTask;
use App\Models\WmsWarehouseLayout;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

class PickingRouteVisualization extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-map';

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

    public array $pickingAreas = [];

    public int $pickingStartX = 0;

    public int $pickingStartY = 0;

    public int $pickingEndX = 0;

    public int $pickingEndY = 0;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::PICKING_ROUTE_VISUALIZATION->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return 'ピッキング経路確認';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::PICKING_ROUTE_VISUALIZATION->sort();
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
        $firstWarehouse = Warehouse::where('is_active', true)->where('is_virtual', false)->orderBy('code')->first();
        if ($firstWarehouse) {
            $this->selectedWarehouseId = $firstWarehouse->id;

            // Set default floor if available
            $firstFloor = Floor::where('warehouse_id', $this->selectedWarehouseId)
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
        if (! $this->selectedWarehouseId) {
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

        // Sync location positions within zone groups (code1+code2)
        // Ensures API route calculation uses the same coordinates as the displayed zones
        $this->syncLocationPositions();

        // Always load picking areas (regardless of layout existence)
        $this->loadPickingAreas();
    }

    /**
     * Sync location positions within zone groups (code1+code2).
     * The floor plan editor saves position to one location per group,
     * but other locations in the same group may have stale/missing positions.
     * This ensures all locations share the same position so that
     * A* route calculation targets the correct coordinates.
     */
    private function syncLocationPositions(): void
    {
        if (! $this->selectedFloorId) {
            return;
        }

        $locations = Location::where('floor_id', $this->selectedFloorId)
            ->whereNotNull('code1')
            ->whereNotNull('code2')
            ->where('code1', '!=', 'ZZ')
            ->get();

        $groups = $locations->groupBy(fn ($loc) => $loc->code1.'-'.$loc->code2);

        foreach ($groups as $groupLocs) {
            $source = $groupLocs->first(fn ($loc) => $loc->x1_pos > 0 || $loc->y1_pos > 0);
            if (! $source) {
                continue;
            }

            $idsToUpdate = [];
            foreach ($groupLocs as $loc) {
                if ($loc->id === $source->id) {
                    continue;
                }
                if ($loc->x1_pos == $source->x1_pos && $loc->y1_pos == $source->y1_pos
                    && $loc->x2_pos == $source->x2_pos && $loc->y2_pos == $source->y2_pos) {
                    continue;
                }
                $idsToUpdate[] = $loc->id;
            }

            if (! empty($idsToUpdate)) {
                Location::whereIn('id', $idsToUpdate)->update([
                    'x1_pos' => $source->x1_pos,
                    'y1_pos' => $source->y1_pos,
                    'x2_pos' => $source->x2_pos,
                    'y2_pos' => $source->y2_pos,
                ]);
            }
        }
    }

    /**
     * Load picking areas for the selected warehouse and floor
     */
    private function loadPickingAreas(): void
    {
        if (! $this->selectedWarehouseId || ! $this->selectedFloorId) {
            $this->pickingAreas = [];

            return;
        }

        $this->pickingAreas = WmsPickingArea::where('warehouse_id', $this->selectedWarehouseId)
            ->where('floor_id', $this->selectedFloorId)
            ->with('pickers')
            ->withCount('locations')
            ->get()
            ->map(function ($area) {
                return [
                    'id' => $area->id,
                    'name' => $area->name,
                    'color' => $area->color ?? '#8B5CF6',
                    'polygon' => $area->polygon,
                    'temperature_type' => $area->temperature_type,
                    'available_quantity_flags' => $area->available_quantity_flags,
                    'is_restricted_area' => $area->is_restricted_area ?? false,
                    'location_count' => $area->locations_count ?? 0,
                    'pickers' => $area->pickers->map(function ($picker) {
                        return [
                            'id' => $picker->id,
                            'code' => $picker->code,
                            'name' => $picker->name,
                            'can_access_restricted_area' => $picker->can_access_restricted_area ?? false,
                        ];
                    })->values()->toArray(),
                ];
            })->values()->toArray();
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
                fixedAreas: $this->fixedAreas,
                pickingAreas: $this->pickingAreas,
                canvasWidth: $this->canvasWidth,
                canvasHeight: $this->canvasHeight
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
            ->where('is_virtual', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Get floors for selected warehouse
     */
    #[Computed]
    public function floors()
    {
        if (! $this->selectedWarehouseId) {
            return collect();
        }

        return Floor::where('warehouse_id', $this->selectedWarehouseId)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'warehouse_id']);
    }

    /**
     * Get zones (locations) for the selected floor - grouped by code1+code2
     */
    #[Computed]
    public function zones()
    {
        if (! $this->selectedFloorId) {
            return collect();
        }

        $locations = Location::where('floor_id', $this->selectedFloorId)
            ->whereNotNull('code1')
            ->whereNotNull('code2')
            ->where('code1', '!=', 'ZZ')  // Exclude default location
            ->orderBy('code1')
            ->orderBy('code2')
            ->orderBy('code3')
            ->get();

        // Group locations by code1+code2
        $zoneGroups = [];
        foreach ($locations as $location) {
            $zoneKey = $location->code1.'-'.$location->code2;
            if (! isset($zoneGroups[$zoneKey])) {
                $zoneGroups[$zoneKey] = [
                    'locations' => [],
                    'first_location' => $location,
                ];
            }
            $zoneGroups[$zoneKey]['locations'][] = $location;
        }

        // Build zones array - one entry per code1+code2 group
        $zones = [];
        $zoneIndex = 0;
        foreach ($zoneGroups as $zoneKey => $group) {
            $firstLoc = $group['first_location'];
            $locationIds = collect($group['locations'])->pluck('id')->toArray();

            // Use first location's position (match FloorPlanEditor logic)
            $x1 = 0;
            $y1 = 0;
            $x2 = 0;
            $y2 = 0;
            foreach ($group['locations'] as $loc) {
                if ($loc->x1_pos > 0 || $loc->y1_pos > 0) {
                    $x1 = (int) $loc->x1_pos;
                    $y1 = (int) $loc->y1_pos;
                    $x2 = (int) $loc->x2_pos;
                    $y2 = (int) $loc->y2_pos;
                    break;
                }
            }

            // Auto-generate position if none set
            if ($x1 == 0 && $y1 == 0) {
                $row = intdiv($zoneIndex, 30);
                $col = $zoneIndex % 30;
                $x1 = 50 + $col * 45;
                $y1 = 50 + $row * 35;
                $x2 = $x1 + 40;
                $y2 = $y1 + 30;
            }

            $zones[] = [
                'id' => $firstLoc->id,
                'zone_key' => $zoneKey,
                'floor_id' => $firstLoc->floor_id,
                'warehouse_id' => $firstLoc->warehouse_id,
                'code1' => $firstLoc->code1,
                'code2' => $firstLoc->code2,
                'name' => $firstLoc->code1.$firstLoc->code2,
                'x1' => $x1,
                'y1' => $y1,
                'x2' => $x2,
                'y2' => $y2,
                'is_restricted_area' => $firstLoc->is_restricted_area ?? false,
                'shelf_count' => count($group['locations']),
                'location_ids' => $locationIds,
            ];

            $zoneIndex++;
        }

        return collect($zones);
    }

    /**
     * Get available delivery courses for the selected warehouse and date
     */
    #[Computed]
    public function deliveryCourses()
    {
        if (! $this->selectedWarehouseId || ! $this->selectedDate) {
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
        if (! $this->selectedWarehouseId || ! $this->selectedDate || ! $this->selectedDeliveryCourseId) {
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
                $statusLabel = match ($task->status) {
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
        if (! $this->selectedWarehouseId || ! $this->selectedFloorId || ! $this->selectedDate || ! $this->selectedDeliveryCourseId) {
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

        if (! $task) {
            \Filament\Notifications\Notification::make()
                ->title('タスクが見つかりません')
                ->danger()
                ->send();

            return;
        }

        if (! in_array($task->status, ['PENDING', 'PICKING_READY'])) {
            \Filament\Notifications\Notification::make()
                ->title('PENDING/PICKING_READYステータスのタスクのみ再計算可能です')
                ->warning()
                ->send();

            return;
        }

        // Get floor ID from task itself
        $floorId = $task->floor_id;

        if (! $floorId) {
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
        $service = new \App\Services\Picking\PickRouteService;
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
