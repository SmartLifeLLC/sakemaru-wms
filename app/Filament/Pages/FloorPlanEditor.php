<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Enums\EMenuCategory;
use App\Models\Sakemaru\Floor;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsFloorObject;
use App\Models\WmsLocation;
use App\Models\WmsPicker;
use App\Models\WmsPickingArea;
use App\Models\WmsWarehouseLayout;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

class FloorPlanEditor extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = Heroicon::OutlinedMap;

    protected string $view = 'filament.pages.floor-plan-editor';

    protected static \UnitEnum|string|null $navigationGroup = null;

    // Livewire properties
    public ?int $selectedWarehouseId = null;

    public ?int $selectedFloorId = null;

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

    public ?array $walkableAreas = null;

    public ?array $navmeta = null;

    public $pickingAreas = [];

    public $pickingAreaMode = null; // 'draw'

    public $currentPolygonPoints = [];

    public $showPickingAreaNameModal = false;

    public $newPickingAreaName = '';

    public $newPickingAreaColor = '#8B5CF6'; // Default purple

    public array $newPickingArea = [
        'name' => '',
        'polygon' => [],
    ];

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::MASTER_WAREHOUSE->label();
    }

    public static function getNavigationLabel(): string
    {
        return '倉庫フロアプラン';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::FLOOR_PLAN_EDITOR->sort();
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

        // Check for URL parameters first
        $warehouseParam = request()->query('warehouse');
        $floorParam = request()->query('floor');

        if ($warehouseParam) {
            // Use URL parameters
            $this->selectedWarehouseId = (int) $warehouseParam;
            if ($floorParam) {
                $this->selectedFloorId = (int) $floorParam;
            } else {
                // Get first floor for this warehouse
                $firstFloor = Floor::where('warehouse_id', $this->selectedWarehouseId)
                    ->orderBy('code')
                    ->first();
                if ($firstFloor) {
                    $this->selectedFloorId = $firstFloor->id;
                }
            }
            $this->loadLayout();
        } else {
            // Set default warehouse if available
            $firstWarehouse = Warehouse::where('is_active', true)->orderBy('code')->first();
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
            }
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
                fixedAreas: $this->fixedAreas,
                canvasWidth: $this->canvasWidth,
                canvasHeight: $this->canvasHeight,
                walkableAreas: $this->walkableAreas,
                navmeta: $this->navmeta,
                pickingAreas: $this->pickingAreas
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
        if (! $this->selectedWarehouseId) {
            return collect();
        }

        return Floor::where('warehouse_id', $this->selectedWarehouseId)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'warehouse_id']);
    }

    /**
     * Get zones (locations GROUPED by code1+code2) for selected floor
     * Each zone represents a rack position (code1+code2), with multiple shelves (code3)
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
                    'max_x1' => 0,
                    'max_y1' => 0,
                    'max_x2' => 0,
                    'max_y2' => 0,
                ];
            }
            $zoneGroups[$zoneKey]['locations'][] = $location;

            // Track best position (non-zero)
            if ($location->x1_pos > 0 || $location->y1_pos > 0) {
                $zoneGroups[$zoneKey]['max_x1'] = max($zoneGroups[$zoneKey]['max_x1'], (int) $location->x1_pos);
                $zoneGroups[$zoneKey]['max_y1'] = max($zoneGroups[$zoneKey]['max_y1'], (int) $location->y1_pos);
                $zoneGroups[$zoneKey]['max_x2'] = max($zoneGroups[$zoneKey]['max_x2'], (int) $location->x2_pos);
                $zoneGroups[$zoneKey]['max_y2'] = max($zoneGroups[$zoneKey]['max_y2'], (int) $location->y2_pos);
            }
        }

        // Build zones array - one entry per code1+code2 group
        $zones = [];
        $zoneIndex = 0;
        foreach ($zoneGroups as $zoneKey => $group) {
            $firstLoc = $group['first_location'];
            $locationIds = collect($group['locations'])->pluck('id')->toArray();

            // Use stored position or auto-generate
            $x1 = $group['max_x1'];
            $y1 = $group['max_y1'];
            $x2 = $group['max_x2'];
            $y2 = $group['max_y2'];

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
                'id' => $firstLoc->id,  // Use first location's ID as zone ID
                'zone_key' => $zoneKey,
                'floor_id' => $firstLoc->floor_id,
                'warehouse_id' => $firstLoc->warehouse_id,
                'code1' => $firstLoc->code1,
                'code2' => $firstLoc->code2,
                'name' => $firstLoc->code1.$firstLoc->code2,  // Zone name = code1+code2 only
                'x1_pos' => $x1,
                'y1_pos' => $y1,
                'x2_pos' => $x2,
                'y2_pos' => $y2,
                'available_quantity_flags' => $firstLoc->available_quantity_flags,
                'temperature_type' => $firstLoc->temperature_type?->value,
                'is_restricted_area' => $firstLoc->is_restricted_area ?? false,
                'shelf_count' => count($group['locations']),
                'location_ids' => $locationIds,
            ];

            $zoneIndex++;
        }

        return collect($zones);
    }

    /**
     * Get floor objects (pillars, fixed areas) for selected floor
     */
    #[Computed]
    public function floorObjects()
    {
        if (! $this->selectedFloorId) {
            return collect();
        }

        return WmsFloorObject::where('floor_id', $this->selectedFloorId)
            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    /**
     * Load layout for current warehouse/floor
     */
    public function loadLayout(): void
    {
        if (! $this->selectedWarehouseId) {
            $this->resetToDefaults();

            return;
        }

        // Try to get floor-specific layout first, then warehouse default
        $layout = WmsWarehouseLayout::where('warehouse_id', $this->selectedWarehouseId)
            ->where('floor_id', $this->selectedFloorId)
            ->first();

        if (! $layout && $this->selectedFloorId) {
            // Try warehouse default
            $layout = WmsWarehouseLayout::where('warehouse_id', $this->selectedWarehouseId)
                ->whereNull('floor_id')
                ->first();
        }

        if ($layout) {
            // Load settings from database
            $this->canvasWidth = $layout->width ?? 2000;
            $this->canvasHeight = $layout->height ?? 1500;
            $this->colors = $layout->colors ?? WmsWarehouseLayout::getDefaultColors();
            $this->textStyles = $layout->text_styles ?? WmsWarehouseLayout::getDefaultTextStyles();
            $this->walls = $layout->walls ?? [];
            $this->fixedAreas = $layout->fixed_areas ?? [];
            $this->pickingStartX = $layout->picking_start_x ?? 0;
            $this->pickingStartY = $layout->picking_start_y ?? 0;
            $this->pickingEndX = $layout->picking_end_x ?? 0;
            $this->pickingEndY = $layout->picking_end_y ?? 0;
            $this->walkableAreas = $layout->walkable_areas ?? null;
            $this->navmeta = $layout->navmeta ?? null;
        } else {
            // Reset layout settings but continue to load picking areas
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
            $this->walkableAreas = null;
            $this->navmeta = null;
        }

        // Always load picking areas (regardless of layout existence)
        $this->pickingAreas = \App\Models\WmsPickingArea::where('warehouse_id', $this->selectedWarehouseId)
            ->where('floor_id', $this->selectedFloorId)
            ->get()
            ->map(function ($area) {
                return [
                    'id' => $area->id,
                    'name' => $area->name,
                    'color' => $area->color ?? '#8B5CF6',
                    'polygon' => $area->polygon,
                    'available_quantity_flags' => $area->available_quantity_flags,
                    'temperature_type' => $area->temperature_type,
                    'is_restricted_area' => $area->is_restricted_area ?? false,
                ];
            })->values()->toArray();
    }

    /**
     * Reload picking areas (called after update)
     */
    private function loadPickingAreas(): void
    {
        if (! $this->selectedWarehouseId || ! $this->selectedFloorId) {
            $this->pickingAreas = [];

            return;
        }

        $this->pickingAreas = WmsPickingArea::where('warehouse_id', $this->selectedWarehouseId)
            ->where('floor_id', $this->selectedFloorId)
            ->get()
            ->map(function ($area) {
                return [
                    'id' => $area->id,
                    'name' => $area->name,
                    'color' => $area->color ?? '#8B5CF6',
                    'polygon' => $area->polygon,
                    'available_quantity_flags' => $area->available_quantity_flags,
                    'temperature_type' => $area->temperature_type,
                    'is_restricted_area' => $area->is_restricted_area ?? false,
                ];
            })->values()->toArray();
    }

    /**
     * Reset to default settings
     */
    private function resetToDefaults(): void
    {
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
        $this->walkableAreas = null;
        $this->navmeta = null;
        $this->pickingAreas = [];
    }

    /**
     * Save layout
     */
    public function saveLayout(): void
    {
        if (! $this->selectedWarehouseId) {
            \Filament\Notifications\Notification::make()
                ->title('倉庫を選択してください')
                ->danger()
                ->send();

            return;
        }

        WmsWarehouseLayout::updateOrCreate(
            [
                'warehouse_id' => $this->selectedWarehouseId,
                'floor_id' => $this->selectedFloorId,
            ],
            [
                'width' => $this->canvasWidth,
                'height' => $this->canvasHeight,
                'colors' => $this->colors,
                'text_styles' => $this->textStyles,
                'walls' => $this->walls,
                'fixed_areas' => $this->fixedAreas,
                'picking_start_x' => $this->pickingStartX,
                'picking_start_y' => $this->pickingStartY,
                'picking_end_x' => $this->pickingEndX,
                'picking_end_y' => $this->pickingEndY,
                'walkable_areas' => $this->walkableAreas,
                'navmeta' => $this->navmeta,
            ]
        );

        \Filament\Notifications\Notification::make()
            ->title('レイアウト設定を保存しました')
            ->success()
            ->send();

        // Dispatch event to close modal and update canvas
        $this->dispatch('layout-saved', canvasWidth: $this->canvasWidth, canvasHeight: $this->canvasHeight);
    }

    /**
     * Update canvas size
     */
    public function updateCanvasSize($width, $height): void
    {
        if (! $this->selectedWarehouseId) {
            \Filament\Notifications\Notification::make()
                ->title('倉庫を選択してください')
                ->danger()
                ->send();

            return;
        }

        // Validate and update canvas size
        $this->canvasWidth = max(500, min(10000, (int) $width));
        $this->canvasHeight = max(500, min(10000, (int) $height));

        // Save to database directly
        WmsWarehouseLayout::updateOrCreate(
            [
                'warehouse_id' => $this->selectedWarehouseId,
                'floor_id' => $this->selectedFloorId,
            ],
            [
                'width' => $this->canvasWidth,
                'height' => $this->canvasHeight,
                'colors' => $this->colors,
                'text_styles' => $this->textStyles,
                'walls' => $this->walls,
                'fixed_areas' => $this->fixedAreas,
                'picking_start_x' => $this->pickingStartX,
                'picking_start_y' => $this->pickingStartY,
                'picking_end_x' => $this->pickingEndX,
                'picking_end_y' => $this->pickingEndY,
                'walkable_areas' => $this->walkableAreas,
                'navmeta' => $this->navmeta,
            ]
        );

        // Dispatch event with validated values to update Alpine.js
        $this->dispatch('canvas-size-updated', width: $this->canvasWidth, height: $this->canvasHeight);

        \Filament\Notifications\Notification::make()
            ->title("キャンバスサイズを変更しました（幅: {$this->canvasWidth}px, 高さ: {$this->canvasHeight}px）")
            ->success()
            ->send();
    }

    /**
     * Update selected warehouse
     */
    public function updatedSelectedWarehouseId(): void
    {
        $this->selectedFloorId = null;
        $this->loadLayout();

        // Notify frontend to reload all layout data
        $zones = $this->zones->toArray();
        $this->dispatch('layout-loaded',
            zones: $zones,
            walls: $this->walls,
            fixedAreas: $this->fixedAreas,
            canvasWidth: $this->canvasWidth,
            canvasHeight: $this->canvasHeight,
            walkableAreas: $this->walkableAreas,
            navmeta: $this->navmeta,
            pickingAreas: $this->pickingAreas
        );
    }

    /**
     * Update selected floor
     */
    public function updatedSelectedFloorId(): void
    {
        $this->loadLayout();

        // Notify frontend to reload all layout data
        $zones = $this->zones->toArray();
        $this->dispatch('layout-loaded',
            zones: $zones,
            walls: $this->walls,
            fixedAreas: $this->fixedAreas,
            canvasWidth: $this->canvasWidth,
            canvasHeight: $this->canvasHeight,
            walkableAreas: $this->walkableAreas,
            navmeta: $this->navmeta,
            pickingAreas: $this->pickingAreas
        );
    }

    /**
     * Add new wall/pillar
     */
    public function addWall(): void
    {
        $newId = count($this->walls) > 0 ? max(array_column($this->walls, 'id')) + 1 : 1;

        $newWall = [
            'id' => $newId,
            'name' => '柱'.$newId,
            'x1' => 100,
            'y1' => 100,
            'x2' => 150,
            'y2' => 150,
        ];

        $this->walls[] = $newWall;

        $this->dispatch('wall-added', wall: $newWall);
    }

    /**
     * Add new fixed area
     */
    public function addFixedArea(): void
    {
        $newId = count($this->fixedAreas) > 0 ? max(array_column($this->fixedAreas, 'id')) + 1 : 1;

        $newArea = [
            'id' => $newId,
            'name' => '固定領域'.$newId,
            'x1' => 200,
            'y1' => 200,
            'x2' => 300,
            'y2' => 300,
        ];

        $this->fixedAreas[] = $newArea;

        $this->dispatch('fixed-area-added', fixedArea: $newArea);
    }

    /**
     * Remove wall
     */
    public function removeWall(int $id): void
    {
        $this->walls = array_values(array_filter($this->walls, fn ($wall) => $wall['id'] !== $id));
    }

    /**
     * Remove fixed area
     */
    public function removeFixedArea(int $id): void
    {
        $this->fixedAreas = array_values(array_filter($this->fixedAreas, fn ($area) => $area['id'] !== $id));
    }

    /**
     * Add new zone (location)
     */
    public function addZone(): void
    {
        if (! $this->selectedFloorId) {
            \Filament\Notifications\Notification::make()
                ->title('フロアを選択してください')
                ->danger()
                ->send();

            return;
        }

        try {
            // Get floor to get client_id
            $floor = Floor::find($this->selectedFloorId);
            if (! $floor) {
                \Filament\Notifications\Notification::make()
                    ->title('フロアが見つかりません')
                    ->danger()
                    ->send();

                return;
            }

            // Get warehouse name
            $warehouse = Warehouse::find($this->selectedWarehouseId);
            if (! $warehouse) {
                \Filament\Notifications\Notification::make()
                    ->title('倉庫が見つかりません')
                    ->danger()
                    ->send();

                return;
            }

            // Generate codes
            $code1 = 'A';
            $code2 = str_pad((string) (Location::where('floor_id', $this->selectedFloorId)->count() + 1), 3, '0', STR_PAD_LEFT);

            // Create location name: "[倉庫名][フロア名-CODE1-CODE2]"
            $locationName = "{$warehouse->name}{$floor->name}-{$code1}-{$code2}";

            // Create a new temporary location in the center of canvas
            $newLocation = Location::create([
                'client_id' => $floor->client_id,
                'warehouse_id' => $this->selectedWarehouseId,
                'floor_id' => $this->selectedFloorId,
                'code1' => $code1,
                'code2' => $code2,
                'code3' => null,
                'name' => $locationName,
                'x1_pos' => 400,
                'y1_pos' => 300,
                'x2_pos' => 460,
                'y2_pos' => 340,
                'available_quantity_flags' => 3,
                'creator_id' => 0,
                'last_updater_id' => 0,
            ]);

            // Reload zones and dispatch
            $zones = $this->zones->toArray();
            $this->dispatch('layout-loaded',
                zones: $zones,
                walls: $this->walls,
                fixedAreas: $this->fixedAreas,
                canvasWidth: $this->canvasWidth,
                canvasHeight: $this->canvasHeight,
                walkableAreas: $this->walkableAreas,
                navmeta: $this->navmeta,
                pickingAreas: $this->pickingAreas
            );

            \Filament\Notifications\Notification::make()
                ->title('区画を追加しました')
                ->success()
                ->send();

        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('区画の追加に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Update zone position
     */
    public function updateZonePosition(int $zoneId, int $x1, int $y1, int $x2, int $y2): void
    {
        $location = Location::find($zoneId);
        if ($location) {
            $location->update([
                'x1_pos' => $x1,
                'y1_pos' => $y1,
                'x2_pos' => $x2,
                'y2_pos' => $y2,
            ]);
        }
    }

    /**
     * Update wall position
     */
    public function updateWallPosition(int $wallId, int $x1, int $y1, int $x2, int $y2): void
    {
        foreach ($this->walls as $key => $wall) {
            if ($wall['id'] === $wallId) {
                $this->walls[$key]['x1'] = $x1;
                $this->walls[$key]['y1'] = $y1;
                $this->walls[$key]['x2'] = $x2;
                $this->walls[$key]['y2'] = $y2;
                break;
            }
        }
    }

    /**
     * Save all positions at once (zones, walls, fixed areas, new zones, deleted zones)
     */
    public function saveAllPositions(array $changedZones, array $walls, array $fixedAreas, array $newZones = [], array $deletedZoneIds = []): void
    {
        if (! $this->selectedWarehouseId) {
            return;
        }

        // Delete zones
        $deletedCount = 0;
        if (! empty($deletedZoneIds)) {
            foreach ($deletedZoneIds as $zoneId) {
                $location = Location::find($zoneId);
                if ($location) {
                    $location->delete();
                    $deletedCount++;
                }
            }
        }

        // Update only changed zones positions in database
        foreach ($changedZones as $zoneData) {
            // Skip temp IDs (new zones are handled separately)
            if (is_string($zoneData['id']) && str_starts_with($zoneData['id'], 'temp_')) {
                continue;
            }
            $location = Location::find($zoneData['id']);
            if ($location) {
                $location->update([
                    'x1_pos' => $zoneData['x1_pos'],
                    'y1_pos' => $zoneData['y1_pos'],
                    'x2_pos' => $zoneData['x2_pos'],
                    'y2_pos' => $zoneData['y2_pos'],
                ]);
            }
        }

        // Create new zones
        $createdCount = 0;
        if (! empty($newZones) && $this->selectedFloorId) {
            $floor = Floor::find($this->selectedFloorId);
            $warehouse = Warehouse::find($this->selectedWarehouseId);

            if ($floor && $warehouse) {
                foreach ($newZones as $zoneData) {
                    // Generate location name
                    $locationName = "{$warehouse->name}{$floor->name}-{$zoneData['code1']}-{$zoneData['code2']}";

                    // Create the location
                    $newLocation = Location::create([
                        'client_id' => $floor->client_id,
                        'warehouse_id' => $this->selectedWarehouseId,
                        'floor_id' => $this->selectedFloorId,
                        'code1' => $zoneData['code1'],
                        'code2' => $zoneData['code2'],
                        'code3' => null,
                        'name' => $zoneData['name'] === 'NEW ZONE' ? $locationName : $zoneData['name'],
                        'x1_pos' => $zoneData['x1_pos'],
                        'y1_pos' => $zoneData['y1_pos'],
                        'x2_pos' => $zoneData['x2_pos'],
                        'y2_pos' => $zoneData['y2_pos'],
                        'available_quantity_flags' => $zoneData['available_quantity_flags'] ?? 3,
                    ]);

                    $createdCount++;
                }
            }
        }

        // Update walls in Livewire state
        $this->walls = $walls;

        // Update fixed areas in Livewire state
        $this->fixedAreas = $fixedAreas;

        // Save layout (which saves walls and fixed areas to database)
        $this->saveLayout();

        // Show success notification
        $message = '保存しました';
        $details = [];
        if ($createdCount > 0) {
            $details[] = "新規: {$createdCount}件";
        }
        if ($deletedCount > 0) {
            $details[] = "削除: {$deletedCount}件";
        }
        if (! empty($details)) {
            $message .= '（'.implode('、', $details).'）';
        }
        \Filament\Notifications\Notification::make()
            ->title($message)
            ->success()
            ->send();
    }

    /**
     * Update fixed area position
     */
    public function updateFixedAreaPosition(int $areaId, int $x1, int $y1, int $x2, int $y2): void
    {
        foreach ($this->fixedAreas as $key => $area) {
            if ($area['id'] === $areaId) {
                $this->fixedAreas[$key]['x1'] = $x1;
                $this->fixedAreas[$key]['y1'] = $y1;
                $this->fixedAreas[$key]['x2'] = $x2;
                $this->fixedAreas[$key]['y2'] = $y2;
                break;
            }
        }
    }

    /**
     * Export layout as JSON
     */
    public function exportLayout()
    {
        if (! $this->selectedWarehouseId || ! $this->selectedFloorId) {
            \Filament\Notifications\Notification::make()
                ->title('倉庫とフロアを選択してください')
                ->danger()
                ->send();

            return null;
        }

        $warehouse = Warehouse::find($this->selectedWarehouseId);
        $floor = Floor::find($this->selectedFloorId);

        if (! $warehouse || ! $floor) {
            return null;
        }

        // Get zones with codes instead of IDs
        $zones = $this->zones->map(function ($zone) {
            return [
                'code1' => $zone['code1'],
                'code2' => $zone['code2'],
                'name' => $zone['name'],
                'x1_pos' => $zone['x1_pos'],
                'y1_pos' => $zone['y1_pos'],
                'x2_pos' => $zone['x2_pos'],
                'y2_pos' => $zone['y2_pos'],
                'available_quantity_flags' => $zone['available_quantity_flags'],
                'levels' => $zone['levels'],
            ];
        })->values()->toArray();

        $layout = [
            'warehouse_code' => $warehouse->code,
            'warehouse_name' => $warehouse->name,
            'floor_code' => $floor->code,
            'floor_name' => $floor->name,
            'canvas' => [
                'width' => $this->canvasWidth,
                'height' => $this->canvasHeight,
            ],
            'colors' => $this->colors,
            'text_styles' => $this->textStyles,
            'zones' => $zones,
            'walls' => $this->walls,
            'fixed_areas' => $this->fixedAreas,
            'exported_at' => now()->toIso8601String(),
        ];

        $filename = sprintf(
            'layout_%s_%s_%s.json',
            $warehouse->code,
            $floor->code,
            now()->format('Ymd_His')
        );

        return response()->streamDownload(function () use ($layout) {
            echo json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Import layout from JSON data
     */
    public function importLayoutData($layout)
    {
        try {
            if (! $this->selectedWarehouseId || ! $this->selectedFloorId) {
                \Filament\Notifications\Notification::make()
                    ->title('倉庫とフロアを選択してください')
                    ->danger()
                    ->send();

                return;
            }

            $floor = Floor::find($this->selectedFloorId);
            if (! $floor) {
                throw new \Exception('フロアが見つかりません');
            }

            if (! is_array($layout)) {
                throw new \Exception('無効なレイアウトデータです');
            }

            // Import canvas size
            if (isset($layout['canvas'])) {
                $this->canvasWidth = $layout['canvas']['width'] ?? 2000;
                $this->canvasHeight = $layout['canvas']['height'] ?? 1500;
            }

            // Import colors and text styles
            if (isset($layout['colors'])) {
                $this->colors = $layout['colors'];
            }
            if (isset($layout['text_styles'])) {
                $this->textStyles = $layout['text_styles'];
            }

            // Import walls and fixed areas
            $this->walls = $layout['walls'] ?? [];
            $this->fixedAreas = $layout['fixed_areas'] ?? [];

            // Import zones (create or update locations)
            if (isset($layout['zones'])) {
                // Get warehouse name for generating location names
                $warehouse = Warehouse::find($this->selectedWarehouseId);
                $warehouseName = $warehouse ? $warehouse->name : '';
                $floorName = $floor->name ?? '';

                foreach ($layout['zones'] as $zoneData) {
                    // Generate location name if not provided or if it's a temporary name
                    $locationName = $zoneData['name'] ?? '';
                    if (empty($locationName) || $locationName === '新規区画') {
                        // Create location name: "[倉庫名][フロア名-CODE1-CODE2]"
                        $locationName = "{$warehouseName}{$floorName}-{$zoneData['code1']}-{$zoneData['code2']}";
                    }

                    $location = Location::updateOrCreate(
                        [
                            'floor_id' => $this->selectedFloorId,
                            'code1' => $zoneData['code1'],
                            'code2' => $zoneData['code2'],
                        ],
                        [
                            'client_id' => $floor->client_id,
                            'warehouse_id' => $this->selectedWarehouseId,
                            'code3' => null,
                            'name' => $locationName,
                            'x1_pos' => $zoneData['x1_pos'],
                            'y1_pos' => $zoneData['y1_pos'],
                            'x2_pos' => $zoneData['x2_pos'],
                            'y2_pos' => $zoneData['y2_pos'],
                            'available_quantity_flags' => $zoneData['available_quantity_flags'],
                            'creator_id' => 0,
                            'last_updater_id' => 0,
                        ]
                    );
                }
            }

            // Save layout to database
            $this->saveLayout();

            // Reload data
            $zones = $this->zones->toArray();
            $this->dispatch('layout-loaded',
                zones: $zones,
                walls: $this->walls,
                fixedAreas: $this->fixedAreas,
                canvasWidth: $this->canvasWidth,
                canvasHeight: $this->canvasHeight,
                walkableAreas: $this->walkableAreas,
                navmeta: $this->navmeta,
                pickingAreas: $this->pickingAreas
            );

            \Filament\Notifications\Notification::make()
                ->title('レイアウトをインポートしました')
                ->success()
                ->send();

        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('インポートに失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Save new picking area
     */
    public function savePickingArea(
        string $name,
        array $polygon,
        string $color = '#8B5CF6',
        ?int $availableQuantityFlags = null,
        ?string $temperatureType = null,
        bool $isRestrictedArea = false
    ): void {
        if (! $this->selectedWarehouseId || ! $this->selectedFloorId) {
            return;
        }

        try {
            // Create with temporary code
            $area = WmsPickingArea::create([
                'warehouse_id' => $this->selectedWarehouseId,
                'floor_id' => $this->selectedFloorId,
                'code' => uniqid(),
                'name' => $name,
                'color' => $color,
                'polygon' => $polygon,
                'available_quantity_flags' => $availableQuantityFlags,
                'temperature_type' => $temperatureType,
                'is_restricted_area' => $isRestrictedArea,
                'is_active' => true,
            ]);

            // Update code to match ID as requested
            $area->update(['code' => (string) $area->id]);

            // Assign locations to this area
            $count = $this->assignLocationsToArea($area);

            // Apply area settings to the assigned locations
            if ($count > 0) {
                $area->applySettingsToLocations();
            }

            // Reload layout
            $this->loadLayout();

            // Notify frontend
            $zones = $this->zones->toArray();
            $this->dispatch('layout-loaded',
                zones: $zones,
                walls: $this->walls,
                fixedAreas: $this->fixedAreas,
                canvasWidth: $this->canvasWidth,
                canvasHeight: $this->canvasHeight,
                walkableAreas: $this->walkableAreas,
                navmeta: $this->navmeta,
                pickingAreas: $this->pickingAreas
            );

            \Filament\Notifications\Notification::make()
                ->title('ピッキングエリアを作成しました')
                ->body("{$count}件のロケーションを割り当てました")
                ->success()
                ->send();

        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('作成に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Assign locations inside the polygon to the picking area
     */
    private function assignLocationsToArea(\App\Models\WmsPickingArea $area): int
    {
        $polygon = $area->polygon;
        if (empty($polygon) || count($polygon) < 3) {
            return 0;
        }

        $locations = Location::where('floor_id', $this->selectedFloorId)->get();
        $count = 0;

        foreach ($locations as $location) {
            // Check if center point of location is inside polygon
            $centerX = ($location->x1_pos + $location->x2_pos) / 2;
            $centerY = ($location->y1_pos + $location->y2_pos) / 2;

            if ($this->isPointInPolygon($centerX, $centerY, $polygon)) {
                // Update WmsLocation
                \App\Models\WmsLocation::updateOrCreate(
                    ['location_id' => $location->id],
                    ['wms_picking_area_id' => $area->id]
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Reassign locations to the picking area based on current positions
     * This clears existing assignments and re-evaluates all locations
     */
    private function reassignLocationsToArea(\App\Models\WmsPickingArea $area): int
    {
        $polygon = $area->polygon;
        if (empty($polygon) || count($polygon) < 3) {
            return 0;
        }

        // Clear existing assignments for this area
        WmsLocation::where('wms_picking_area_id', $area->id)->update(['wms_picking_area_id' => null]);

        $locations = Location::where('floor_id', $area->floor_id)->get();
        $count = 0;

        foreach ($locations as $location) {
            // Check if center point of location is inside polygon
            $centerX = ($location->x1_pos + $location->x2_pos) / 2;
            $centerY = ($location->y1_pos + $location->y2_pos) / 2;

            if ($this->isPointInPolygon($centerX, $centerY, $polygon)) {
                // Update WmsLocation
                WmsLocation::updateOrCreate(
                    ['location_id' => $location->id],
                    ['wms_picking_area_id' => $area->id]
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Check if point is inside polygon (Ray casting algorithm)
     */
    private function isPointInPolygon($x, $y, $polygon): bool
    {
        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $polygon[$i]['x'];
            $yi = $polygon[$i]['y'];
            $xj = $polygon[$j]['x'];
            $yj = $polygon[$j]['y'];

            $intersect = (($yi > $y) != ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Delete picking area
     */
    public function deletePickingArea(int $id): void
    {
        try {
            $area = \App\Models\WmsPickingArea::find($id);
            if ($area) {
                // Unassign locations and delete area in transaction
                \Illuminate\Support\Facades\DB::transaction(function () use ($area) {
                    // Use Location model as WmsLocation is deprecated
                    \App\Models\Sakemaru\Location::whereHas('wmsLocation', function ($query) use ($area) {
                        $query->where('wms_picking_area_id', $area->id);
                    })->with('wmsLocation')->get()->each(function ($location) {
                        $location->wmsLocation()->update(['wms_picking_area_id' => null]);
                    });

                    $area->delete();
                });

                // Reload layout
                $this->loadLayout();

                // Notify frontend
                $zones = $this->zones->toArray();
                $this->dispatch('layout-loaded',
                    zones: $zones,
                    walls: $this->walls,
                    fixedAreas: $this->fixedAreas,
                    canvasWidth: $this->canvasWidth,
                    canvasHeight: $this->canvasHeight,
                    walkableAreas: $this->walkableAreas,
                    navmeta: $this->navmeta,
                    pickingAreas: $this->pickingAreas
                );

                \Filament\Notifications\Notification::make()
                    ->title('ピッキングエリアを削除しました')
                    ->success()
                    ->send();
            }
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('削除に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Get location count for a picking area
     */
    public function getAreaLocationCount(int $areaId): int
    {
        return WmsLocation::where('wms_picking_area_id', $areaId)->count();
    }

    /**
     * Update picking area settings and apply to locations
     */
    public function updatePickingAreaSettings(array $data): void
    {
        try {
            $area = WmsPickingArea::find($data['id']);

            if (! $area) {
                \Filament\Notifications\Notification::make()
                    ->title('エリアが見つかりません')
                    ->danger()
                    ->send();

                return;
            }

            // Update area settings
            $area->update([
                'name' => $data['name'],
                'color' => $data['color'],
                'available_quantity_flags' => $data['available_quantity_flags'],
                'temperature_type' => $data['temperature_type'],
                'is_restricted_area' => $data['is_restricted_area'] ?? false,
            ]);

            // Reassign locations based on current positions
            $locationCount = $this->reassignLocationsToArea($area);

            // Apply settings to all locations in this area
            $area->applySettingsToLocations();

            // Reload pickingAreas
            $this->loadPickingAreas();

            \Filament\Notifications\Notification::make()
                ->title('エリア設定を更新しました')
                ->body("{$locationCount} 件のロケーションを割り当てました")
                ->success()
                ->send();

        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('更新に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Update picking start point
     */
    public function updatePickingStartPoint($x, $y): void
    {
        $this->pickingStartX = (int) $x;
        $this->pickingStartY = (int) $y;
        $this->saveLayout();

        \Filament\Notifications\Notification::make()
            ->title('ピッキング開始地点を更新しました')
            ->success()
            ->send();
    }

    /**
     * Update picking end point
     */
    public function updatePickingEndPoint($x, $y): void
    {
        $this->pickingEndX = (int) $x;
        $this->pickingEndY = (int) $y;
        $this->saveLayout();

        \Filament\Notifications\Notification::make()
            ->title('ピッキング終了地点を更新しました')
            ->success()
            ->send();
    }

    /**
     * Save walkable area bitmap (convert to polygons and apply erosion)
     */
    public function saveWalkableBitmap(
        array $bitmap,
        int $cellSize,
        int $erosionDistance = 20,
        int $gridSizeParam = 10, // Keep parameter but rename to avoid confusion
        int $gridThreshold = 6
    ): void {
        // Fixed grid size for entire system
        $gridSize = 10;
        if (! $this->selectedWarehouseId) {
            \Filament\Notifications\Notification::make()
                ->title('倉庫を選択してください')
                ->danger()
                ->send();

            return;
        }

        try {
            // Convert bitmap to rectangles (much more compact than bitmap)
            $converter = new \App\Services\Picking\BitmapToRectangles;
            $rectangles = $converter->convert($bitmap, $cellSize);

            if (empty($rectangles)) {
                \Filament\Notifications\Notification::make()
                    ->title('歩行領域が定義されていません')
                    ->warning()
                    ->send();

                return;
            }

            // Convert rectangles to polygons for pathfinding
            $polygons = [];
            foreach ($rectangles as $rect) {
                $polygons[] = [
                    'outer' => [
                        [$rect['x1'], $rect['y1']],
                        [$rect['x2'], $rect['y1']],
                        [$rect['x2'], $rect['y2']],
                        [$rect['x1'], $rect['y2']],
                    ],
                    'holes' => [],
                ];
            }

            // Apply erosion to account for cart width (if erosion distance > 0)
            $finalPolygons = $polygons;
            if ($erosionDistance > 0) {
                $erosion = new \App\Services\Picking\PolygonErosion;
                $erodedPolygons = $erosion->erode($polygons, $erosionDistance);

                if (empty($erodedPolygons)) {
                    \Filament\Notifications\Notification::make()
                        ->title('エロージョン後に有効な歩行領域が残りませんでした')
                        ->body('エロージョン距離を小さくするか、歩行領域をもっと広く塗ってください。')
                        ->warning()
                        ->send();

                    return;
                }
                $finalPolygons = $erodedPolygons;
            }

            // Update Livewire properties
            // Save the eroded polygons for pathfinding (walkableAreas)
            $this->walkableAreas = $finalPolygons;

            // Save metadata including the original rectangles (compact representation)
            $this->navmeta = [
                'cell_size' => $cellSize,
                'erosion_distance' => $erosionDistance,
                'grid_size' => $gridSize,
                'grid_threshold' => $gridThreshold,
                'original_rectangles' => $rectangles, // Store rectangles instead of bitmap
                'generated_at' => now()->toIso8601String(),
            ];

            // Save to database
            $this->saveLayout();

            $message = sprintf('%d個の長方形から%d個のポリゴンを生成しました', count($rectangles), count($finalPolygons));
            if ($erosionDistance > 0) {
                $message .= sprintf('（エロージョン: %dpx）', $erosionDistance);
            } else {
                $message .= '（エロージョンなし）';
            }
            $message .= sprintf('、Grid: %dpx、閾値: %d', $gridSize, $gridThreshold);

            \Filament\Notifications\Notification::make()
                ->title('歩行領域を保存しました')
                ->body($message)
                ->success()
                ->send();

        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('歩行領域の保存に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Update location details (temperature_type, is_restricted_area, etc.)
     */
    public function updateLocation(array $locationData): void
    {
        try {
            $location = Location::find($locationData['id']);

            if (! $location) {
                \Filament\Notifications\Notification::make()
                    ->title('ロケーションが見つかりません')
                    ->danger()
                    ->send();

                return;
            }

            $location->update([
                'code1' => $locationData['code1'] ?? $location->code1,
                'code2' => $locationData['code2'] ?? $location->code2,
                'name' => $locationData['name'] ?? $location->name,
                'available_quantity_flags' => $locationData['available_quantity_flags'] ?? $location->available_quantity_flags,
                'temperature_type' => $locationData['temperature_type'] ?? $location->temperature_type,
                'is_restricted_area' => $locationData['is_restricted_area'] ?? $location->is_restricted_area,
            ]);

            \Filament\Notifications\Notification::make()
                ->title('ロケーション情報を更新しました')
                ->success()
                ->send();

        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('ロケーション情報の更新に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Search locations in the same warehouse for stock transfer
     */
    public function searchTransferLocations(string $search, int $excludeLocationId): array
    {
        if (! $this->selectedWarehouseId || strlen($search) < 1) {
            return [];
        }

        $locations = Location::where('warehouse_id', $this->selectedWarehouseId)
            ->where('id', '!=', $excludeLocationId)
            ->whereNotNull('code1')
            ->whereNotNull('code2')
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('code1', 'like', "%{$search}%")
                    ->orWhere('code2', 'like', "%{$search}%")
                    ->orWhereRaw('CONCAT(code1, code2) LIKE ?', ["%{$search}%"]);
            })
            ->with('floor:id,name,code')
            ->orderBy('code1')
            ->orderBy('code2')
            ->limit(20)
            ->get(['id', 'floor_id', 'code1', 'code2', 'name']);

        return $locations->map(function ($loc) {
            return [
                'id' => $loc->id,
                'code1' => $loc->code1,
                'code2' => $loc->code2,
                'name' => $loc->name,
                'floor_name' => $loc->floor?->name ?? '',
            ];
        })->toArray();
    }

    /**
     * Execute stock transfer between locations
     * Note: real_stock_lots経由でlocation移動を行う
     */
    public function executeStockTransfer(array $transferData): void
    {
        try {
            $sourceLocationId = $transferData['source_location_id'];
            $targetLocationId = $transferData['target_location_id'];
            $warehouseId = $transferData['warehouse_id'];
            $items = $transferData['items'] ?? [];

            if (empty($items)) {
                \Filament\Notifications\Notification::make()
                    ->title('移動する商品が選択されていません')
                    ->warning()
                    ->send();

                return;
            }

            // Get current user info for worker tracking
            $userId = auth()->id();
            $userName = auth()->user()?->name ?? 'Unknown';

            // Get target location's floor_id
            $targetLocation = \Illuminate\Support\Facades\DB::connection('sakemaru')
                ->table('locations')
                ->where('id', $targetLocationId)
                ->first();

            \Illuminate\Support\Facades\DB::connection('sakemaru')->transaction(function () use (
                $sourceLocationId,
                $targetLocationId,
                $targetLocation,
                $warehouseId,
                $items,
                $userId,
                $userName
            ) {
                foreach ($items as $item) {
                    $lotId = $item['lot_id'] ?? null;
                    $realStockId = $item['real_stock_id'];
                    $itemId = $item['item_id'];
                    $transferQty = (int) $item['transfer_qty'];
                    $totalQty = (int) ($item['total_qty'] ?? $transferQty);

                    // Get the source lot record
                    $sourceLot = \Illuminate\Support\Facades\DB::connection('sakemaru')
                        ->table('real_stock_lots')
                        ->where('real_stock_id', $realStockId)
                        ->where('location_id', $sourceLocationId)
                        ->where('status', 'ACTIVE')
                        ->first();

                    if (! $sourceLot) {
                        continue;
                    }

                    if ($transferQty >= $sourceLot->current_quantity) {
                        // Full transfer: just update the lot's location_id
                        \Illuminate\Support\Facades\DB::connection('sakemaru')
                            ->table('real_stock_lots')
                            ->where('id', $sourceLot->id)
                            ->update([
                                'location_id' => $targetLocationId,
                                'floor_id' => $targetLocation?->floor_id ?? $sourceLot->floor_id,
                                'updated_at' => now(),
                            ]);
                    } else {
                        // Partial transfer: reduce source lot and create/update target lot
                        $remainingQty = $sourceLot->current_quantity - $transferQty;

                        // Reduce quantity at source lot
                        \Illuminate\Support\Facades\DB::connection('sakemaru')
                            ->table('real_stock_lots')
                            ->where('id', $sourceLot->id)
                            ->update([
                                'current_quantity' => $remainingQty,
                                'updated_at' => now(),
                            ]);

                        // Check if there's an existing lot at target location with same properties
                        $existingTargetLot = \Illuminate\Support\Facades\DB::connection('sakemaru')
                            ->table('real_stock_lots')
                            ->where('real_stock_id', $realStockId)
                            ->where('location_id', $targetLocationId)
                            ->where('expiration_date', $sourceLot->expiration_date)
                            ->where('status', 'ACTIVE')
                            ->first();

                        if ($existingTargetLot) {
                            // Add to existing lot at target
                            \Illuminate\Support\Facades\DB::connection('sakemaru')
                                ->table('real_stock_lots')
                                ->where('id', $existingTargetLot->id)
                                ->update([
                                    'current_quantity' => $existingTargetLot->current_quantity + $transferQty,
                                    'updated_at' => now(),
                                ]);
                        } else {
                            // Create new lot at target location
                            \Illuminate\Support\Facades\DB::connection('sakemaru')
                                ->table('real_stock_lots')
                                ->insert([
                                    'real_stock_id' => $realStockId,
                                    'purchase_id' => $sourceLot->purchase_id,
                                    'trade_item_id' => $sourceLot->trade_item_id,
                                    'floor_id' => $targetLocation?->floor_id ?? $sourceLot->floor_id,
                                    'location_id' => $targetLocationId,
                                    'price' => $sourceLot->price,
                                    'content_amount' => $sourceLot->content_amount,
                                    'container_amount' => $sourceLot->container_amount,
                                    'expiration_date' => $sourceLot->expiration_date,
                                    'initial_quantity' => $transferQty,
                                    'current_quantity' => $transferQty,
                                    'reserved_quantity' => 0,
                                    'status' => 'ACTIVE',
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                        }
                    }

                    // Create transfer history record
                    \App\Models\WmsStockTransfer::create([
                        'item_id' => $itemId,
                        'real_stock_id' => $realStockId,
                        'transfer_qty' => $transferQty,
                        'warehouse_id' => $warehouseId,
                        'item_management_type' => 'NONE',
                        'source_location_id' => $sourceLocationId,
                        'target_location_id' => $targetLocationId,
                        'worker_id' => $userId,
                        'worker_name' => $userName,
                        'transferred_at' => now(),
                    ]);
                }
            });

            $itemCount = count($items);
            \Filament\Notifications\Notification::make()
                ->title("在庫を移動しました（{$itemCount}件）")
                ->success()
                ->send();

        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('在庫移動に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Get all active pickers (for area assignment)
     */
    public function getPickersForWarehouse(int $warehouseId): array
    {
        // Get warehouse names for display
        $warehouseNames = \Illuminate\Support\Facades\DB::connection('sakemaru')
            ->table('warehouses')
            ->pluck('name', 'id')
            ->toArray();

        // Return all active pickers - any picker can be assigned to any area
        return WmsPicker::where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'can_access_restricted_area', 'default_warehouse_id'])
            ->map(fn ($picker) => [
                'id' => $picker->id,
                'code' => $picker->code,
                'name' => $picker->name,
                'display_name' => "[{$picker->code}] {$picker->name}",
                'can_access_restricted_area' => $picker->can_access_restricted_area,
                'default_warehouse_id' => $picker->default_warehouse_id,
                'warehouse_name' => $picker->default_warehouse_id ? ($warehouseNames[$picker->default_warehouse_id] ?? null) : null,
            ])
            ->toArray();
    }

    /**
     * Get all active warehouses for filter dropdown
     */
    public function getWarehousesList(): array
    {
        return \Illuminate\Support\Facades\DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn ($wh) => [
                'id' => $wh->id,
                'code' => $wh->code,
                'name' => $wh->name,
            ])
            ->toArray();
    }

    /**
     * Get pickers assigned to a picking area
     */
    public function getAreaPickers(int $areaId): array
    {
        $area = WmsPickingArea::find($areaId);
        if (! $area) {
            return [];
        }

        return $area->pickers()
            ->orderBy('code')
            ->get(['wms_pickers.id', 'code', 'name', 'can_access_restricted_area'])
            ->map(fn ($picker) => [
                'id' => $picker->id,
                'code' => $picker->code,
                'name' => $picker->name,
                'display_name' => "[{$picker->code}] {$picker->name}",
                'can_access_restricted_area' => $picker->can_access_restricted_area,
            ])
            ->toArray();
    }

    /**
     * Update picker assignments for a picking area
     */
    public function updateAreaPickers(int $areaId, array $pickerIds): void
    {
        try {
            $area = WmsPickingArea::find($areaId);
            if (! $area) {
                \Filament\Notifications\Notification::make()
                    ->title('エリアが見つかりません')
                    ->danger()
                    ->send();

                return;
            }

            // Sync pickers (this will add/remove as needed)
            $area->pickers()->sync($pickerIds);

            \Filament\Notifications\Notification::make()
                ->title('担当ピッカーを更新しました')
                ->body(count($pickerIds).'名のピッカーを設定しました')
                ->success()
                ->send();

        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('更新に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Create a new picker from the area settings modal
     */
    public function createPicker(array $data): ?array
    {
        try {
            // Validate required fields
            if (empty($data['code']) || empty($data['name']) || empty($data['password'])) {
                \Filament\Notifications\Notification::make()
                    ->title('必須項目を入力してください')
                    ->danger()
                    ->send();

                return null;
            }

            // Check for duplicate code
            $existingPicker = WmsPicker::where('code', $data['code'])->first();
            if ($existingPicker) {
                \Filament\Notifications\Notification::make()
                    ->title('このコードは既に使用されています')
                    ->danger()
                    ->send();

                return null;
            }

            // Create the picker
            $picker = WmsPicker::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
                'default_warehouse_id' => $this->selectedWarehouseId,
                'can_access_restricted_area' => $data['can_access_restricted_area'] ?? false,
                'is_active' => true,
            ]);

            \Filament\Notifications\Notification::make()
                ->title('ピッカーを登録しました')
                ->body("[{$picker->code}] {$picker->name}")
                ->success()
                ->send();

            // Get warehouse name for display
            $warehouseName = null;
            if ($this->selectedWarehouseId) {
                $warehouseName = \Illuminate\Support\Facades\DB::connection('sakemaru')
                    ->table('warehouses')
                    ->where('id', $this->selectedWarehouseId)
                    ->value('name');
            }

            // Return the new picker data for Alpine.js
            return [
                'id' => $picker->id,
                'code' => $picker->code,
                'name' => $picker->name,
                'display_name' => "[{$picker->code}] {$picker->name}",
                'can_access_restricted_area' => $picker->can_access_restricted_area,
                'default_warehouse_id' => $this->selectedWarehouseId,
                'warehouse_name' => $warehouseName,
            ];

        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('登録に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }
}
