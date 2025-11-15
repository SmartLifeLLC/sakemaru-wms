<?php

namespace App\Console\Commands;

use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Earning;
use App\Models\Wave;
use App\Models\WaveSetting;
use App\Services\StockAllocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateWavesCommand extends Command
{
    protected $signature = 'wms:generate-waves {--date= : Shipping date (YYYY-MM-DD), defaults to today} {--reset : Reset all wave-related data before generating new waves}';

    protected $description = 'Generate WMS waves based on wms_wave_settings for eligible earnings';

    public function handle()
    {
        $shippingDate = $this->option('date') ?? ClientSetting::systemDate()->format('Y-m-d');


        $shouldReset = $this->option('reset');

        $this->info("Generating waves for shipping date: {$shippingDate}");

        // Reset wave-related data if --reset flag is provided
        if ($shouldReset) {
            $this->warn("⚠️  Reset flag detected. Cleaning up all wave-related data...");
            $this->resetWaveData($shippingDate);
            $this->info("✓ Wave data reset completed.");
            $this->newLine();
        }

        // Get current time
        // Wave generation runs every 1 minute, so we only process waves where start time has passed
        $currentTime = now();

        $this->line("Current time: {$currentTime->format('H:i:s')}");
        $this->line("Processing waves with picking start time that has passed...");

        // Get wave settings where picking_start_time has already passed
        // This allows earnings to be entered up until the picking start time
        $waveSettings = WaveSetting::whereTime('picking_start_time', '<=', $currentTime->format('H:i:s'))
            ->get();

        if ($waveSettings->isEmpty()) {
            $this->warn('No wave settings found with picking start time before ' . $currentTime->format('H:i:s') . '. Please create wave settings first.');
            return 1;
        }

        $this->info("Found {$waveSettings->count()} wave setting(s) eligible for generation");

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($waveSettings as $setting) {
            // Check if wave already exists for this setting and date
            $existingWave = Wave::where('wms_wave_setting_id', $setting->id)
                ->where('shipping_date', $shippingDate)
                ->first();
            if ($existingWave) {
                $skippedCount++;
                continue;
            }

            // Check if there are eligible earnings for this wave
            $earningsCount = Earning::where('delivered_date', $shippingDate)
                ->where('is_delivered', 0)
                ->where('picking_status', 'BEFORE')
                ->where('warehouse_id', $setting->warehouse_id)
                ->where('delivery_course_id', $setting->delivery_course_id)
                ->count();

            if ($earningsCount === 0) {
                $this->line("No eligible earnings found for warehouse {$setting->warehouse_id}, course {$setting->delivery_course_id}. Skipping.");
                $skippedCount++;
                continue;
            }

            // Create wave within transaction
            DB::transaction(function () use ($setting, $shippingDate, $earningsCount, &$createdCount) {
                // Get warehouse and course codes for wave_no generation
                $warehouse = DB::connection('sakemaru')
                    ->table('warehouses')
                    ->where('id', $setting->warehouse_id)
                    ->first();

                $course = DB::connection('sakemaru')
                    ->table('delivery_courses')
                    ->where('id', $setting->delivery_course_id)
                    ->first();

                // Create wave
                $wave = Wave::create([
                    'wms_wave_setting_id' => $setting->id,
                    'wave_no' => uniqid('TEMP_'), // Temporary, will update after getting ID
                    'shipping_date' => $shippingDate,
                    'status' => 'PENDING',
                ]);

                // Update wave_no with actual ID
                $waveNo = Wave::generateWaveNo(
                    $warehouse->code ?? 0,
                    $course->code ?? 0,
                    $shippingDate,
                    $wave->id
                );

                $wave->update(['wave_no' => $waveNo]);

                // Get earnings for this wave
                $earnings = Earning::where('delivered_date', $shippingDate)
                    ->where('is_delivered', 0)
                    ->where('picking_status', 'BEFORE')
                    ->where('warehouse_id', $setting->warehouse_id)
                    ->where('delivery_course_id', $setting->delivery_course_id)
                    ->get();

                // Create picking tasks grouped by warehouse, floor, picking_area, and delivery_course
                // IMPORTANT: All trade_items for same warehouse/floor/area/course go into ONE picking task

                // Get all trade items for all earnings in this wave
                $earningIds = $earnings->pluck('id')->toArray();
                $tradeIds = $earnings->pluck('trade_id')->toArray();

                $tradeItems = DB::connection('sakemaru')
                    ->table('trade_items')
                    ->whereIn('trade_id', $tradeIds)
                    ->get();

                // Create earning_id lookup for each trade_item
                $tradeIdToEarningId = $earnings->pluck('id', 'trade_id')->toArray();

                // Group trade items by (floor_id, picking_area_id)
                // We determine the floor and picking area by reserving stock first
                $itemsByGroup = [];
                $reservationResults = [];

                foreach ($tradeItems as $tradeItem) {
                        // Get earning_id for this trade_item
                        $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;
                        if (!$earningId) {
                            continue; // Skip if no matching earning found
                        }

                        // Reserve stock for this trade item using optimized allocation service
                        $allocationService = new StockAllocationService();
                        $result = $allocationService->allocateForItem(
                            $wave->id,
                            $setting->warehouse_id,
                            $tradeItem->item_id,
                            $tradeItem->quantity,
                            $tradeItem->quantity_type ?? 'PIECE',
                            $earningId,
                            'EARNING'
                        );

                        // Get primary location and real_stock from first reservation
                        // Note: There may be multiple reservations if stock is split across locations
                        // We use the first reservation as the primary picking location
                        $primaryReservation = DB::connection('sakemaru')
                            ->table('wms_reservations')
                            ->where('wave_id', $wave->id)
                            ->where('item_id', $tradeItem->item_id)
                            ->where('source_id', $earningId)
                            ->whereNotNull('location_id')
                            ->orderBy('qty_each', 'desc') // Order by quantity (largest first)
                            ->orderBy('id', 'asc')
                            ->first();

                        $reservationResult = [
                            'allocated_qty' => $result['allocated'],
                            'real_stock_id' => $primaryReservation->real_stock_id ?? null,
                            'location_id' => $primaryReservation->location_id ?? null,
                            'walking_order' => null,
                        ];

                        // Get walking_order from wms_locations for route optimization
                        if ($reservationResult['location_id']) {
                            $wmsLocation = DB::connection('sakemaru')
                                ->table('wms_locations')
                                ->where('location_id', $reservationResult['location_id'])
                                ->first();
                            $reservationResult['walking_order'] = $wmsLocation->walking_order ?? null;
                        }

                        $reservationResults[$tradeItem->id] = $reservationResult;

                        // Skip items with zero allocation (complete shortage)
                        if ($result['allocated'] == 0) {
                            continue;
                        }

                        // Get picking area ID and floor ID from primary location
                        $pickingAreaId = null;
                        $floorId = null;

                        if ($reservationResult['location_id']) {
                            // Get floor_id from locations table
                            $location = DB::connection('sakemaru')
                                ->table('locations')
                                ->where('id', $reservationResult['location_id'])
                                ->first();
                            $floorId = $location->floor_id ?? null;

                            // Get picking_area_id from wms_locations table
                            $wmsLocation = DB::connection('sakemaru')
                                ->table('wms_locations')
                                ->where('location_id', $reservationResult['location_id'])
                                ->first();
                            $pickingAreaId = $wmsLocation->wms_picking_area_id ?? null;
                        }

                        // If no location was found (shortage), try to find any historical location for this item
                        if ($pickingAreaId === null || $floorId === null) {
                            $itemLocation = DB::connection('sakemaru')
                                ->table('real_stocks as rs')
                                ->join('wms_locations as wl', 'rs.location_id', '=', 'wl.location_id')
                                ->join('locations as l', 'rs.location_id', '=', 'l.id')
                                ->where('rs.warehouse_id', $setting->warehouse_id)
                                ->where('rs.item_id', $tradeItem->item_id)
                                ->whereNotNull('wl.wms_picking_area_id')
                                ->select('wl.wms_picking_area_id', 'l.floor_id')
                                ->first();

                            if ($itemLocation) {
                                $pickingAreaId = $pickingAreaId ?? $itemLocation->wms_picking_area_id;
                                $floorId = $floorId ?? $itemLocation->floor_id;
                            } else {
                                // If still no picking area found, assign to first active picking area as default
                                $defaultArea = DB::connection('sakemaru')
                                    ->table('wms_picking_areas')
                                    ->where('warehouse_id', $setting->warehouse_id)
                                    ->where('is_active', true)
                                    ->orderBy('display_order', 'asc')
                                    ->first();

                                $pickingAreaId = $pickingAreaId ?? ($defaultArea->id ?? null);
                            }
                        }

                        // Group by (floor_id, picking_area_id)
                        $groupKey = ($floorId ?? 'null') . '|' . ($pickingAreaId ?? 'null');
                        if (!isset($itemsByGroup[$groupKey])) {
                            $itemsByGroup[$groupKey] = [
                                'floor_id' => $floorId,
                                'picking_area_id' => $pickingAreaId,
                                'items' => [],
                            ];
                        }
                        $itemsByGroup[$groupKey]['items'][] = $tradeItem;
                    }

                // Create one picking task per (floor_id, picking_area_id) group
                foreach ($itemsByGroup as $groupData) {
                    // Skip groups with no items (all items had zero allocation)
                    if (empty($groupData['items'])) {
                        continue;
                    }

                    // Filter items to only include those with successful reservations
                    $validItems = [];
                    foreach ($groupData['items'] as $tradeItem) {
                        $reservationResult = $reservationResults[$tradeItem->id] ?? null;
                        if ($reservationResult && $reservationResult['allocated_qty'] > 0) {
                            $validItems[] = $tradeItem;
                        }
                    }

                    // Skip if no valid items remain after filtering
                    if (empty($validItems)) {
                        continue;
                    }

                    $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                        'wave_id' => $wave->id,
                        'wms_picking_area_id' => $groupData['picking_area_id'],
                        'warehouse_id' => $setting->warehouse_id,
                        'warehouse_code' => $warehouse->code,
                        'floor_id' => $groupData['floor_id'], // Added: floor_id for grouping
                        'delivery_course_id' => $setting->delivery_course_id,
                        'delivery_course_code' => $course->code,
                        'shipment_date' => $shippingDate,
                        'status' => 'PENDING',
                        'task_type' => 'WAVE',
                        'picker_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Create picking item results for valid items in this group
                    foreach ($validItems as $tradeItem) {
                        $reservationResult = $reservationResults[$tradeItem->id];
                        $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;

                        DB::connection('sakemaru')->table('wms_picking_item_results')->insert([
                            'picking_task_id' => $pickingTaskId,
                            'earning_id' => $earningId, // Added: earning_id now tracked at item level
                            'trade_id' => $tradeItem->trade_id, // Added: trade_id now tracked at item level
                            'trade_item_id' => $tradeItem->id,
                            'item_id' => $tradeItem->item_id,
                            'real_stock_id' => $reservationResult['real_stock_id'], // Primary real_stock from reservation
                            'location_id' => $reservationResult['location_id'], // Primary picking location from reservation
                            'walking_order' => $reservationResult['walking_order'], // Warehouse movement sequence for route optimization
                            'ordered_qty' => $tradeItem->quantity, // Original order quantity
                            'ordered_qty_type' => $tradeItem->quantity_type ?? 'PIECE', // From trade_items.quantity_type
                            'planned_qty' => $reservationResult['allocated_qty'], // Allocated quantity from reservations
                            'planned_qty_type' => $tradeItem->quantity_type ?? 'PIECE', // Same as ordered (for now)
                            'picked_qty' => 0, // Will be set by picker during picking
                            'picked_qty_type' => $tradeItem->quantity_type ?? 'PIECE', // Will be set by picker
                            'shortage_qty' => 0, // Will be set by picker during picking
                            'status' => 'PENDING', // Changed: PENDING is initial state (not PICKING)
                            'picker_id' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                // Update all earnings picking_status to PICKING
                DB::connection('sakemaru')
                    ->table('earnings')
                    ->whereIn('id', $earningIds)
                    ->update([
                        'picking_status' => 'PICKING',
                        'updated_at' => now(),
                    ]);

                $this->info("Created wave {$waveNo} with {$earningsCount} earnings and picking tasks");
                $createdCount++;
            });
        }

        $this->info("Wave generation completed. Created: {$createdCount}, Skipped: {$skippedCount}");

        return 0;
    }

    /**
     * Reset all wave-related data for the specified shipping date
     */
    protected function resetWaveData($shippingDate)
    {
        DB::transaction(function () use ($shippingDate) {
            // Get waves for this shipping date
            $waves = Wave::where('shipping_date', $shippingDate)->get();

            if ($waves->isEmpty()) {
                $this->info('  No waves found for this shipping date.');
                return;
            }

            $waveIds = $waves->pluck('id')->toArray();
            $this->info("  Found " . count($waveIds) . " wave(s) to reset.");

            // 1. Get earnings that were part of these waves (via picking_item_results)
            $earningIds = DB::connection('sakemaru')
                ->table('wms_picking_item_results')
                ->whereIn('picking_task_id', function ($query) use ($waveIds) {
                    $query->select('id')
                        ->from('wms_picking_tasks')
                        ->whereIn('wave_id', $waveIds);
                })
                ->pluck('earning_id')
                ->unique()
                ->toArray();

            if (!empty($earningIds)) {
                // Reset earning status back to BEFORE
                $updatedEarnings = DB::connection('sakemaru')
                    ->table('earnings')
                    ->whereIn('id', $earningIds)
                    ->update([
                        'picking_status' => 'BEFORE',
                        'updated_at' => now(),
                    ]);
                $this->info("  ✓ Reset {$updatedEarnings} earnings to BEFORE status");
            }

            // 2. Delete picking item results
            $deletedItemResults = DB::connection('sakemaru')
                ->table('wms_picking_item_results')
                ->whereIn('picking_task_id', function ($query) use ($waveIds) {
                    $query->select('id')
                        ->from('wms_picking_tasks')
                        ->whereIn('wave_id', $waveIds);
                })
                ->delete();
            $this->info("  ✓ Deleted {$deletedItemResults} picking item results");

            // 3. Delete picking tasks
            $deletedTasks = DB::connection('sakemaru')
                ->table('wms_picking_tasks')
                ->whereIn('wave_id', $waveIds)
                ->delete();
            $this->info("  ✓ Deleted {$deletedTasks} picking tasks");

            // 4. Delete reservations and restore real_stocks wms_reserved_qty
            $reservations = DB::connection('sakemaru')
                ->table('wms_reservations')
                ->whereIn('wave_id', $waveIds)
                ->get();

            foreach ($reservations as $reservation) {
                if ($reservation->real_stock_id && $reservation->qty_each > 0) {
                    // Decrease wms_reserved_qty in real_stocks
                    DB::connection('sakemaru')
                        ->table('real_stocks')
                        ->where('id', $reservation->real_stock_id)
                        ->update([
                            'wms_reserved_qty' => DB::raw('GREATEST(wms_reserved_qty - ' . $reservation->qty_each . ', 0)'),
                            'updated_at' => now(),
                        ]);
                }
            }

            $deletedReservations = DB::connection('sakemaru')
                ->table('wms_reservations')
                ->whereIn('wave_id', $waveIds)
                ->delete();
            $this->info("  ✓ Deleted {$deletedReservations} reservations and restored real_stocks");

            // 5. Delete waves
            $deletedWaves = Wave::whereIn('id', $waveIds)->delete();
            $this->info("  ✓ Deleted {$deletedWaves} waves");

            // 6. Reset WMS columns in real_stocks (wms_real_stocks table no longer exists)
            $updatedStocks = DB::connection('sakemaru')
                ->table('real_stocks')
                ->where('wms_reserved_qty', '>', 0)
                ->orWhere('wms_picking_qty', '>', 0)
                ->update([
                    'wms_reserved_qty' => 0,
                    'wms_picking_qty' => 0,
                    'wms_lock_version' => 0,
                ]);

            if ($updatedStocks > 0) {
                $this->info("  ✓ Reset {$updatedStocks} real_stocks records");
            }

            // 7. Delete idempotency keys for wave reservations
            $deletedKeys = DB::connection('sakemaru')
                ->table('wms_idempotency_keys')
                ->where('scope', 'wave_reservation')
                ->delete();

            if ($deletedKeys > 0) {
                $this->info("  ✓ Deleted {$deletedKeys} idempotency keys");
            }
        });
    }
}
