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
    public ?string $selectedDate = null;
    public int $canvasWidth = 2000;
    public int $canvasHeight = 1500;
    public array $colors = [];
    public array $textStyles = [];
    public array $walls = [];
    public array $fixedAreas = [];

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

            // Set default delivery course if available
            $firstCourse = $this->deliveryCourses()->first();
            if ($firstCourse) {
                $this->selectedDeliveryCourseId = $firstCourse['id'];

                // Dispatch event to trigger route loading after component is mounted
                $this->dispatch('delivery-course-changed', courseId: $this->selectedDeliveryCourseId);
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
        } else {
            // Reset to defaults if no layout exists
            $this->canvasWidth = 2000;
            $this->canvasHeight = 1500;
            $this->colors = WmsWarehouseLayout::getDefaultColors();
            $this->textStyles = WmsWarehouseLayout::getDefaultTextStyles();
            $this->walls = [];
            $this->fixedAreas = [];
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
        // Trigger route visualization update
        $this->dispatch('delivery-course-changed', courseId: $this->selectedDeliveryCourseId);
    }

    /**
     * Called when date changes
     */
    public function updatedSelectedDate(): void
    {
        $this->selectedDeliveryCourseId = null;
        $this->dispatch('date-changed', date: $this->selectedDate);
    }
}