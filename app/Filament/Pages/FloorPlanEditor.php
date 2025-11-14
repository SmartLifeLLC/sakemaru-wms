<?php

namespace App\Filament\Pages;

use App\Enums\EMenuCategory;
use App\Models\Sakemaru\Warehouse;
use App\Models\Sakemaru\Floor;
use App\Models\Sakemaru\Location;
use App\Models\WmsLocationLevel;
use App\Models\WmsWarehouseLayout;
use App\Models\WmsFloorObject;
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

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::MASTER->label();
    }

    public static function getNavigationLabel(): string
    {
        return '倉庫フロアプラン';
    }

    public static function getNavigationSort(): ?int
    {
        return 50;
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
                navmeta: $this->navmeta
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
     * Get zones (locations) for selected floor
     */
    #[Computed]
    public function zones()
    {
        if (!$this->selectedFloorId) {
            return collect();
        }

        $locations = Location::where('floor_id', $this->selectedFloorId)
            ->whereNotNull('code1')
            ->whereNotNull('code2')
            ->orderBy('code1')
            ->orderBy('code2')
            ->get();

        return $locations->map(function ($location) {
            $levelsCount = WmsLocationLevel::where('location_id', $location->id)->count();

            return [
                'id' => $location->id,
                'floor_id' => $location->floor_id,
                'warehouse_id' => $location->warehouse_id,
                'code1' => $location->code1,
                'code2' => $location->code2,
                'name' => $location->name,
                'x1_pos' => (int) $location->x1_pos,
                'y1_pos' => (int) $location->y1_pos,
                'x2_pos' => (int) $location->x2_pos,
                'y2_pos' => (int) $location->y2_pos,
                'available_quantity_flags' => $location->available_quantity_flags,
                'levels' => $levelsCount,
            ];
        });
    }

    /**
     * Get floor objects (pillars, fixed areas) for selected floor
     */
    #[Computed]
    public function floorObjects()
    {
        if (!$this->selectedFloorId) {
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
        if (!$this->selectedWarehouseId) {
            $this->resetToDefaults();
            return;
        }

        // Try to get floor-specific layout first, then warehouse default
        $layout = WmsWarehouseLayout::where('warehouse_id', $this->selectedWarehouseId)
            ->where('floor_id', $this->selectedFloorId)
            ->first();

        if (!$layout && $this->selectedFloorId) {
            // Try warehouse default
            $layout = WmsWarehouseLayout::where('warehouse_id', $this->selectedWarehouseId)
                ->whereNull('floor_id')
                ->first();
        }

        if (!$layout) {
            $this->resetToDefaults();
            return;
        }

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
    }

    /**
     * Save layout
     */
    public function saveLayout(): void
    {
        if (!$this->selectedWarehouseId) {
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
    }

    /**
     * Update canvas size
     */
    public function updateCanvasSize($width, $height): void
    {
        if (!$this->selectedWarehouseId) {
            \Filament\Notifications\Notification::make()
                ->title('倉庫を選択してください')
                ->danger()
                ->send();
            return;
        }

        // Validate and update canvas size
        $this->canvasWidth = max(500, min(10000, (int)$width));
        $this->canvasHeight = max(500, min(10000, (int)$height));

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
            navmeta: $this->navmeta
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
            navmeta: $this->navmeta
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
            'name' => '柱' . $newId,
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
            'name' => '固定領域' . $newId,
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
        $this->walls = array_values(array_filter($this->walls, fn($wall) => $wall['id'] !== $id));
    }

    /**
     * Remove fixed area
     */
    public function removeFixedArea(int $id): void
    {
        $this->fixedAreas = array_values(array_filter($this->fixedAreas, fn($area) => $area['id'] !== $id));
    }

    /**
     * Add new zone (location)
     */
    public function addZone(): void
    {
        if (!$this->selectedFloorId) {
            \Filament\Notifications\Notification::make()
                ->title('フロアを選択してください')
                ->danger()
                ->send();
            return;
        }

        try {
            // Get floor to get client_id
            $floor = Floor::find($this->selectedFloorId);
            if (!$floor) {
                \Filament\Notifications\Notification::make()
                    ->title('フロアが見つかりません')
                    ->danger()
                    ->send();
                return;
            }

            // Get warehouse name
            $warehouse = Warehouse::find($this->selectedWarehouseId);
            if (!$warehouse) {
                \Filament\Notifications\Notification::make()
                    ->title('倉庫が見つかりません')
                    ->danger()
                    ->send();
                return;
            }

            // Generate codes
            $code1 = 'A';
            $code2 = str_pad((string)(Location::where('floor_id', $this->selectedFloorId)->count() + 1), 3, '0', STR_PAD_LEFT);

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

            // Create one WMS level for this location
            WmsLocationLevel::create([
                'location_id' => $newLocation->id,
                'level_number' => 1,
                'name' => "{$locationName} 1段",
                'available_quantity_flags' => 3,
            ]);

            // Reload zones and dispatch
            $zones = $this->zones->toArray();
            $this->dispatch('layout-loaded',
                zones: $zones,
                walls: $this->walls,
                fixedAreas: $this->fixedAreas
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
     * Save all positions at once (zones, walls, fixed areas)
     */
    public function saveAllPositions(array $changedZones, array $walls, array $fixedAreas): void
    {
        if (!$this->selectedWarehouseId) {
            return;
        }

        // Update only changed zones positions in database
        foreach ($changedZones as $zoneData) {
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

        // Update walls in Livewire state
        $this->walls = $walls;

        // Update fixed areas in Livewire state
        $this->fixedAreas = $fixedAreas;

        // Save layout (which saves walls and fixed areas to database)
        $this->saveLayout();

        // Show success notification
        \Filament\Notifications\Notification::make()
            ->title('保存しました')
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
        if (!$this->selectedWarehouseId || !$this->selectedFloorId) {
            \Filament\Notifications\Notification::make()
                ->title('倉庫とフロアを選択してください')
                ->danger()
                ->send();
            return null;
        }

        $warehouse = Warehouse::find($this->selectedWarehouseId);
        $floor = Floor::find($this->selectedFloorId);

        if (!$warehouse || !$floor) {
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
            if (!$this->selectedWarehouseId || !$this->selectedFloorId) {
                \Filament\Notifications\Notification::make()
                    ->title('倉庫とフロアを選択してください')
                    ->danger()
                    ->send();
                return;
            }

            $floor = Floor::find($this->selectedFloorId);
            if (!$floor) {
                throw new \Exception('フロアが見つかりません');
            }

            if (!is_array($layout)) {
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

                    // Update or create levels
                    $levels = $zoneData['levels'] ?? 1;
                    for ($level = 1; $level <= $levels; $level++) {
                        WmsLocationLevel::updateOrCreate(
                            [
                                'location_id' => $location->id,
                                'level_number' => $level,
                            ],
                            [
                                'name' => "{$locationName} {$level}段",
                                'available_quantity_flags' => $zoneData['available_quantity_flags'],
                            ]
                        );
                    }

                    // Remove excess levels
                    WmsLocationLevel::where('location_id', $location->id)
                        ->where('level_number', '>', $levels)
                        ->delete();
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
                canvasHeight: $this->canvasHeight
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
    public function saveWalkableRectangles(
        array $rectangles,
        int $erosionDistance = 20,
        int $gridSize = 20,
        int $gridThreshold = 6
    ): void {
        if (!$this->selectedWarehouseId) {
            \Filament\Notifications\Notification::make()
                ->title('倉庫を選択してください')
                ->danger()
                ->send();
            return;
        }

        try {
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

            if (empty($polygons)) {
                \Filament\Notifications\Notification::make()
                    ->title('歩行領域が定義されていません')
                    ->warning()
                    ->send();
                return;
            }

            // Apply erosion to account for cart width (if erosion distance > 0)
            $finalPolygons = $polygons;
            if ($erosionDistance > 0) {
                $erosion = new \App\Services\Picking\PolygonErosion();
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

            // Save metadata including the original rectangles for accurate restoration
            $this->navmeta = [
                'erosion_distance' => $erosionDistance,
                'grid_size' => $gridSize,
                'grid_threshold' => $gridThreshold,
                'original_rectangles' => $rectangles, // Store original rectangles
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
}
