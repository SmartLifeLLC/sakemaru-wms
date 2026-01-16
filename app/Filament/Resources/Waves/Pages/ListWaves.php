<?php

namespace App\Filament\Resources\Waves\Pages;

use App\Filament\Resources\Waves\WaveResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Earning;
use App\Models\Sakemaru\Warehouse;
use App\Models\Wave;
use App\Models\WaveSetting;
use App\Models\WmsPickingItemResult;
use App\Services\StockAllocationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * 波動一覧ページ
 *
 * 波動生成モーダルでは売上伝票と倉庫移動伝票の両方を対象として表示する
 */
class ListWaves extends ListRecords
{
    protected static string $resource = WaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateWave')
                ->label('波動生成')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->modalHeading('波動生成')
                ->modalDescription('対象伝票を選択して波動を生成します。同じ時間帯に既存の波動がある場合でも、新規波動として生成されます。')
                ->modalSubmitActionLabel('波動を生成')
                ->modalWidth('4xl')
                ->form([
                    Select::make('warehouse_id')
                        ->label('倉庫')
                        ->options(Warehouse::query()->pluck('name', 'id'))
                        ->default(fn () => auth()->user()?->default_warehouse_id)
                        ->required()
                        ->live(),

                    DatePicker::make('shipping_date')
                        ->label('出荷日')
                        ->default(fn () => ClientSetting::systemDate()->format('Y-m-d'))
                        ->required()
                        ->live(),

                    Placeholder::make('earnings_preview')
                        ->label('対象伝票')
                        ->content(function (Get $get): HtmlString {
                            $warehouseId = $get('warehouse_id');
                            $shippingDate = $get('shipping_date');

                            if (! $warehouseId || ! $shippingDate) {
                                return new HtmlString('<div class="text-gray-500">倉庫と出荷日を選択してください</div>');
                            }

                            // Get summary by delivery course for earnings
                            $earningSummary = DB::connection('sakemaru')
                                ->table('earnings')
                                ->join('delivery_courses', 'earnings.delivery_course_id', '=', 'delivery_courses.id')
                                ->where('earnings.warehouse_id', $warehouseId)
                                ->where('earnings.delivered_date', $shippingDate)
                                ->where('earnings.is_delivered', 0)
                                ->where('earnings.picking_status', 'BEFORE')
                                ->selectRaw('delivery_courses.id as course_id, delivery_courses.name as course_name, COUNT(*) as count')
                                ->groupBy('delivery_courses.id', 'delivery_courses.name')
                                ->orderBy('delivery_courses.name')
                                ->get()
                                ->keyBy('course_id');

                            // Get summary by delivery course for stock_transfers
                            // 仮想倉庫間移動は対象外（物理的ピッキング不要）
                            $stockTransferSummary = DB::connection('sakemaru')
                                ->table('stock_transfers as st')
                                ->join('delivery_courses as dc', 'st.delivery_course_id', '=', 'dc.id')
                                ->join('warehouses as fw', 'st.from_warehouse_id', '=', 'fw.id')
                                ->join('warehouses as tw', 'st.to_warehouse_id', '=', 'tw.id')
                                ->where('st.from_warehouse_id', $warehouseId)
                                ->where('st.delivered_date', $shippingDate)
                                ->where('st.is_active', true)
                                ->where('st.picking_status', 'BEFORE')
                                // 仮想倉庫間移動は対象外
                                ->where(function ($query) {
                                    $query->where(function ($q) {
                                        $q->where('fw.is_virtual', false)
                                            ->orWhere('tw.is_virtual', false);
                                    })
                                        ->where(function ($q) {
                                            $q->whereRaw('COALESCE(fw.stock_warehouse_id, fw.id) != COALESCE(tw.stock_warehouse_id, tw.id)');
                                        });
                                })
                                ->selectRaw('dc.id as course_id, dc.name as course_name, COUNT(*) as count')
                                ->groupBy('dc.id', 'dc.name')
                                ->orderBy('dc.name')
                                ->get()
                                ->keyBy('course_id');

                            // 両方とも空の場合
                            if ($earningSummary->isEmpty() && $stockTransferSummary->isEmpty()) {
                                return new HtmlString('<div class="text-gray-500">対象となる伝票がありません</div>');
                            }

                            // Merge summaries by delivery course
                            $allCourseIds = $earningSummary->keys()->merge($stockTransferSummary->keys())->unique();
                            $mergedSummary = [];
                            foreach ($allCourseIds as $courseId) {
                                $earningData = $earningSummary->get($courseId);
                                $stockTransferData = $stockTransferSummary->get($courseId);
                                $courseName = $earningData->course_name ?? $stockTransferData->course_name ?? '不明';
                                $mergedSummary[] = [
                                    'course_name' => $courseName,
                                    'earning_count' => $earningData->count ?? 0,
                                    'stock_transfer_count' => $stockTransferData->count ?? 0,
                                ];
                            }

                            // Sort by course name
                            usort($mergedSummary, fn ($a, $b) => strcmp($a['course_name'], $b['course_name']));

                            $totalEarningCount = $earningSummary->sum('count');
                            $totalStockTransferCount = $stockTransferSummary->sum('count');
                            $totalCount = $totalEarningCount + $totalStockTransferCount;

                            $html = '<div class="space-y-3">';

                            // Summary by delivery course
                            $html .= '<div class="overflow-x-auto"><table class="w-full text-sm border-collapse">';
                            $html .= '<thead><tr class="bg-gray-100 dark:bg-gray-800">';
                            $html .= '<th class="border px-3 py-2 text-left">配送コース</th>';
                            $html .= '<th class="border px-3 py-2 text-right">売上伝票</th>';
                            $html .= '<th class="border px-3 py-2 text-right">移動伝票</th>';
                            $html .= '<th class="border px-3 py-2 text-right">合計</th>';
                            $html .= '</tr></thead><tbody>';

                            foreach ($mergedSummary as $row) {
                                $rowTotal = $row['earning_count'] + $row['stock_transfer_count'];
                                $html .= '<tr class="hover:bg-gray-50 dark:hover:bg-gray-700">';
                                $html .= "<td class=\"border px-3 py-2\">{$row['course_name']}</td>";
                                $html .= "<td class=\"border px-3 py-2 text-right\">{$row['earning_count']}件</td>";
                                $html .= "<td class=\"border px-3 py-2 text-right text-purple-600 dark:text-purple-400\">{$row['stock_transfer_count']}件</td>";
                                $html .= "<td class=\"border px-3 py-2 text-right font-medium\">{$rowTotal}件</td>";
                                $html .= '</tr>';
                            }

                            $html .= '</tbody></table></div>';

                            // Total
                            $html .= '<div class="flex justify-between items-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">';
                            $html .= '<span class="font-medium">合計</span>';
                            $html .= '<div class="text-right">';
                            $html .= '<span class="text-sm text-gray-600 dark:text-gray-400 mr-4">売上: '.$totalEarningCount.'件</span>';
                            $html .= '<span class="text-sm text-purple-600 dark:text-purple-400 mr-4">移動: '.$totalStockTransferCount.'件</span>';
                            $html .= '<span class="text-lg font-bold text-blue-600 dark:text-blue-400">'.$totalCount.'件</span>';
                            $html .= '</div>';
                            $html .= '</div>';

                            // Warning for large volume
                            if ($totalCount > 100) {
                                $html .= '<div class="p-3 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg text-yellow-700 dark:text-yellow-400 text-sm">';
                                $html .= '<span class="font-medium">注意:</span> 伝票数が多いため、生成に時間がかかる場合があります。';
                                $html .= '</div>';
                            }

                            $html .= '</div>';

                            return new HtmlString($html);
                        }),
                ])
                ->action(function (array $data): void {
                    $this->generateManualWave($data);
                }),
        ];
    }

    /**
     * 手動波動生成処理
     *
     * 売上伝票（earnings）と倉庫移動伝票（stock_transfers）の両方を対象として波動を生成する
     */
    protected function generateManualWave(array $data): void
    {
        $warehouseId = $data['warehouse_id'];
        $shippingDate = $data['shipping_date'];

        // Get eligible earnings grouped by delivery_course_id
        // delivery_course_id が未設定の伝票はスキップする
        $earnings = Earning::query()
            ->where('warehouse_id', $warehouseId)
            ->where('delivered_date', $shippingDate)
            ->where('is_delivered', 0)
            ->where('picking_status', 'BEFORE')
            ->whereNotNull('delivery_course_id')
            ->get();

        // Get eligible stock_transfers grouped by delivery_course_id
        // 仮想倉庫間移動は対象外（物理的ピッキング不要）
        $stockTransfers = $this->getEligibleStockTransfersQuery($shippingDate, $warehouseId)->get();

        if ($earnings->isEmpty() && $stockTransfers->isEmpty()) {
            Notification::make()
                ->title('対象伝票がありません')
                ->warning()
                ->send();

            return;
        }

        // Group by delivery_course_id
        $earningsByDeliveryCourse = $earnings->groupBy('delivery_course_id');
        $stockTransfersByDeliveryCourse = $stockTransfers->groupBy('delivery_course_id');

        // Get all delivery_course_ids
        $allDeliveryCourseIds = $earningsByDeliveryCourse->keys()
            ->merge($stockTransfersByDeliveryCourse->keys())
            ->unique();

        try {
            $createdWaves = [];
            $totalEarnings = 0;
            $totalStockTransfers = 0;

            DB::transaction(function () use (
                $warehouseId,
                $shippingDate,
                $allDeliveryCourseIds,
                $earningsByDeliveryCourse,
                $stockTransfersByDeliveryCourse,
                &$createdWaves,
                &$totalEarnings,
                &$totalStockTransfers
            ) {
                // Get warehouse info
                $warehouse = DB::connection('sakemaru')
                    ->table('warehouses')
                    ->where('id', $warehouseId)
                    ->first();

                foreach ($allDeliveryCourseIds as $deliveryCourseId) {
                    $courseEarnings = $earningsByDeliveryCourse->get($deliveryCourseId, collect());
                    $courseStockTransfers = $stockTransfersByDeliveryCourse->get($deliveryCourseId, collect());

                    // Find or create wave setting
                    $waveSetting = WaveSetting::where('warehouse_id', $warehouseId)
                        ->where('delivery_course_id', $deliveryCourseId)
                        ->first();

                    if (! $waveSetting) {
                        $waveSetting = WaveSetting::create([
                            'warehouse_id' => $warehouseId,
                            'delivery_course_id' => $deliveryCourseId,
                            'picking_start_time' => null,
                            'picking_deadline_time' => null,
                            'creator_id' => auth()->id() ?? 1,
                            'last_updater_id' => auth()->id() ?? 1,
                        ]);
                    }

                    // Get course info
                    $course = DB::connection('sakemaru')
                        ->table('delivery_courses')
                        ->where('id', $deliveryCourseId)
                        ->first();

                    // Check if wave already exists for this setting and date
                    $existingWave = Wave::where('wms_wave_setting_id', $waveSetting->id)
                        ->where('shipping_date', $shippingDate)
                        ->first();

                    if ($existingWave) {
                        // Use existing wave instead of creating new one
                        $wave = $existingWave;
                        $waveNo = $existingWave->wave_no;
                    } else {
                        // Create wave
                        $wave = Wave::create([
                            'wms_wave_setting_id' => $waveSetting->id,
                            'wave_no' => uniqid('TEMP_'),
                            'shipping_date' => $shippingDate,
                            'status' => 'PENDING',
                        ]);

                        // Generate wave_no
                        $waveNo = Wave::generateWaveNo(
                            $warehouse->code ?? 0,
                            $course->code ?? 0,
                            $shippingDate,
                            $wave->id
                        );
                        $wave->update(['wave_no' => $waveNo]);
                    }

                    // Process earnings
                    if ($courseEarnings->isNotEmpty()) {
                        $this->processEarningsForWave($wave, $waveSetting, $courseEarnings, $warehouse, $course, $shippingDate);
                        $totalEarnings += $courseEarnings->count();
                    }

                    // Process stock_transfers
                    if ($courseStockTransfers->isNotEmpty()) {
                        $this->processStockTransfersForWave($wave, $waveSetting, $courseStockTransfers, $warehouse, $course, $shippingDate);
                        $totalStockTransfers += $courseStockTransfers->count();
                    }

                    $createdWaves[] = $waveNo;
                }
            });

            $bodyMessage = '生成数: '.count($createdWaves).'件';
            if ($totalEarnings > 0) {
                $bodyMessage .= " (売上: {$totalEarnings}件";
            }
            if ($totalStockTransfers > 0) {
                $bodyMessage .= $totalEarnings > 0 ? ", 移動: {$totalStockTransfers}件)" : " (移動: {$totalStockTransfers}件)";
            } else {
                $bodyMessage .= ')';
            }

            Notification::make()
                ->title('波動を生成しました')
                ->body($bodyMessage)
                ->success()
                ->send();

        } catch (\Exception $e) {
            Notification::make()
                ->title('波動生成に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Process earnings for wave - create picking tasks and item results
     */
    protected function processEarningsForWave(
        Wave $wave,
        WaveSetting $waveSetting,
        $earnings,
        $warehouse,
        $course,
        string $shippingDate
    ): void {
        $earningIds = $earnings->pluck('id')->toArray();
        $tradeIds = $earnings->pluck('trade_id')->toArray();

        // Get all trade items
        $tradeItems = DB::connection('sakemaru')
            ->table('trade_items')
            ->whereIn('trade_id', $tradeIds)
            ->get();

        // Create earning_id and buyer_id lookup
        $tradeIdToEarningId = $earnings->pluck('id', 'trade_id')->toArray();
        $tradeIdToBuyerId = $earnings->pluck('buyer_id', 'trade_id')->toArray();

        // Group items and allocate stock
        $itemsByGroup = [];
        $reservationResults = [];

        foreach ($tradeItems as $tradeItem) {
            $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;
            $buyerId = $tradeIdToBuyerId[$tradeItem->trade_id] ?? null;
            if (! $earningId) {
                continue;
            }

            // Reserve stock
            $allocationService = new StockAllocationService;
            $result = $allocationService->allocateForItem(
                $wave->id,
                $waveSetting->warehouse_id,
                $tradeItem->item_id,
                $tradeItem->quantity,
                $tradeItem->quantity_type ?? 'PIECE',
                $earningId,
                'EARNING',
                $buyerId
            );

            // Get primary reservation
            $primaryReservation = DB::connection('sakemaru')
                ->table('wms_reservations')
                ->where('wave_id', $wave->id)
                ->where('item_id', $tradeItem->item_id)
                ->where('source_id', $earningId)
                ->whereNotNull('location_id')
                ->orderBy('qty_each', 'desc')
                ->orderBy('id', 'asc')
                ->first();

            $reservationResult = [
                'allocated_qty' => $result['allocated'],
                'real_stock_id' => $primaryReservation->real_stock_id ?? null,
                'location_id' => $primaryReservation->location_id ?? null,
                'walking_order' => null,
            ];

            // Get walking_order
            if ($reservationResult['location_id']) {
                $wmsLocation = DB::connection('sakemaru')
                    ->table('wms_locations')
                    ->where('location_id', $reservationResult['location_id'])
                    ->first();
                $reservationResult['walking_order'] = $wmsLocation->walking_order ?? null;
            }

            $reservationResults[$tradeItem->id] = $reservationResult;

            // Get picking area and floor info
            $pickingAreaId = null;
            $floorId = null;
            $temperatureType = null;
            $isRestrictedArea = false;

            if ($reservationResult['location_id']) {
                $location = DB::connection('sakemaru')
                    ->table('locations')
                    ->where('id', $reservationResult['location_id'])
                    ->first();
                $floorId = $location->floor_id ?? null;
                $temperatureType = $location->temperature_type ?? null;
                $isRestrictedArea = $location->is_restricted_area ?? false;

                $wmsLocation = DB::connection('sakemaru')
                    ->table('wms_locations')
                    ->where('location_id', $reservationResult['location_id'])
                    ->first();
                $pickingAreaId = $wmsLocation->wms_picking_area_id ?? null;
            }

            // Fallback for items without location
            if ($pickingAreaId === null || $floorId === null) {
                $itemLocation = DB::connection('sakemaru')
                    ->table('real_stocks as rs')
                    ->join('real_stock_lots as rsl', 'rs.id', '=', 'rsl.real_stock_id')
                    ->join('wms_locations as wl', 'rsl.location_id', '=', 'wl.location_id')
                    ->join('locations as l', 'rsl.location_id', '=', 'l.id')
                    ->where('rs.warehouse_id', $waveSetting->warehouse_id)
                    ->where('rs.item_id', $tradeItem->item_id)
                    ->whereNotNull('wl.wms_picking_area_id')
                    ->select('wl.wms_picking_area_id', 'l.floor_id', 'l.temperature_type', 'l.is_restricted_area')
                    ->first();

                if ($itemLocation) {
                    $pickingAreaId = $pickingAreaId ?? $itemLocation->wms_picking_area_id;
                    $floorId = $floorId ?? $itemLocation->floor_id;
                    $temperatureType = $temperatureType ?? $itemLocation->temperature_type;
                    $isRestrictedArea = $isRestrictedArea ?? $itemLocation->is_restricted_area;
                } else {
                    $defaultArea = DB::connection('sakemaru')
                        ->table('wms_picking_areas')
                        ->where('warehouse_id', $waveSetting->warehouse_id)
                        ->where('is_active', true)
                        ->orderBy('display_order', 'asc')
                        ->first();
                    $pickingAreaId = $pickingAreaId ?? ($defaultArea->id ?? null);
                }
            }

            // Group by floor_id
            $groupKey = ($floorId ?? 'null');
            if (! isset($itemsByGroup[$groupKey])) {
                $itemsByGroup[$groupKey] = [
                    'floor_id' => $floorId,
                    'picking_area_id' => $pickingAreaId,
                    'temperature_type' => $temperatureType,
                    'is_restricted_area' => $isRestrictedArea,
                    'items' => [],
                ];
            }
            $itemsByGroup[$groupKey]['items'][] = $tradeItem;
        }

        // Create picking tasks
        foreach ($itemsByGroup as $groupData) {
            if (empty($groupData['items'])) {
                continue;
            }

            $validItems = [];
            $hasRestrictedItem = false;
            foreach ($groupData['items'] as $tradeItem) {
                $reservationResult = $reservationResults[$tradeItem->id] ?? null;
                if ($reservationResult) {
                    $validItems[] = $tradeItem;
                    if ($reservationResult['location_id']) {
                        $location = DB::connection('sakemaru')
                            ->table('locations')
                            ->where('id', $reservationResult['location_id'])
                            ->first();
                        if ($location && $location->is_restricted_area) {
                            $hasRestrictedItem = true;
                        }
                    }
                }
            }

            if (empty($validItems)) {
                continue;
            }

            $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                'wave_id' => $wave->id,
                'wms_picking_area_id' => $groupData['picking_area_id'],
                'warehouse_id' => $waveSetting->warehouse_id,
                'warehouse_code' => $warehouse->code,
                'floor_id' => $groupData['floor_id'],
                'temperature_type' => $groupData['temperature_type'],
                'is_restricted_area' => $hasRestrictedItem,
                'delivery_course_id' => $waveSetting->delivery_course_id,
                'delivery_course_code' => $course->code,
                'shipment_date' => $shippingDate,
                'status' => 'PENDING',
                'task_type' => 'WAVE',
                'picker_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create picking item results
            foreach ($validItems as $tradeItem) {
                $reservationResult = $reservationResults[$tradeItem->id];
                $earningId = $tradeIdToEarningId[$tradeItem->trade_id] ?? null;

                if (! $tradeItem->quantity_type) {
                    throw new \RuntimeException(
                        "quantity_type must be specified for trade_item ID {$tradeItem->id}"
                    );
                }

                DB::connection('sakemaru')->table('wms_picking_item_results')->insert([
                    'picking_task_id' => $pickingTaskId,
                    'earning_id' => $earningId,
                    'source_type' => WmsPickingItemResult::SOURCE_TYPE_EARNING,
                    'stock_transfer_id' => null,
                    'trade_id' => $tradeItem->trade_id,
                    'trade_item_id' => $tradeItem->id,
                    'item_id' => $tradeItem->item_id,
                    'real_stock_id' => $reservationResult['real_stock_id'],
                    'location_id' => $reservationResult['location_id'],
                    'walking_order' => $reservationResult['walking_order'],
                    'ordered_qty' => $tradeItem->quantity,
                    'ordered_qty_type' => $tradeItem->quantity_type,
                    'planned_qty' => $reservationResult['allocated_qty'],
                    'planned_qty_type' => $tradeItem->quantity_type,
                    'picked_qty' => 0,
                    'picked_qty_type' => $tradeItem->quantity_type,
                    'shortage_qty' => 0,
                    'status' => 'PENDING',
                    'picker_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Update earnings status
        DB::connection('sakemaru')
            ->table('earnings')
            ->whereIn('id', $earningIds)
            ->update([
                'picking_status' => 'BEFORE_PICKING',
                'updated_at' => now(),
            ]);
    }

    /**
     * Process stock_transfers for wave - create picking tasks and item results
     */
    protected function processStockTransfersForWave(
        Wave $wave,
        WaveSetting $waveSetting,
        $stockTransfers,
        $warehouse,
        $course,
        string $shippingDate
    ): void {
        $stockTransferIds = $stockTransfers->pluck('id')->toArray();
        $tradeIds = $stockTransfers->pluck('trade_id')->toArray();

        // Create stock_transfer_id lookup from trade_id
        $tradeIdToStockTransferId = $stockTransfers->pluck('id', 'trade_id')->toArray();

        // Get all trade items for stock_transfers
        $tradeItems = DB::connection('sakemaru')
            ->table('trade_items')
            ->whereIn('trade_id', $tradeIds)
            ->where('is_active', true)
            ->get();

        foreach ($tradeItems as $tradeItem) {
            $stockTransferId = $tradeIdToStockTransferId[$tradeItem->trade_id] ?? null;
            if (! $stockTransferId) {
                continue;
            }

            // Reserve stock for this trade item
            $allocationService = new StockAllocationService;
            $result = $allocationService->allocateForItem(
                $wave->id,
                $waveSetting->warehouse_id,
                $tradeItem->item_id,
                $tradeItem->quantity,
                $tradeItem->quantity_type ?? 'PIECE',
                $stockTransferId,
                'STOCK_TRANSFER',
                null // buyer_id
            );

            // Get primary reservation
            $primaryReservation = DB::connection('sakemaru')
                ->table('wms_reservations')
                ->where('wave_id', $wave->id)
                ->where('item_id', $tradeItem->item_id)
                ->where('source_id', $stockTransferId)
                ->where('source_type', 'STOCK_TRANSFER')
                ->whereNotNull('location_id')
                ->orderBy('qty_each', 'desc')
                ->orderBy('id', 'asc')
                ->first();

            $reservationResult = [
                'allocated_qty' => $result['allocated'],
                'real_stock_id' => $primaryReservation->real_stock_id ?? null,
                'location_id' => $primaryReservation->location_id ?? null,
                'walking_order' => null,
            ];

            // Get walking_order from wms_locations
            if ($reservationResult['location_id']) {
                $wmsLocation = DB::connection('sakemaru')
                    ->table('wms_locations')
                    ->where('location_id', $reservationResult['location_id'])
                    ->first();
                $reservationResult['walking_order'] = $wmsLocation->walking_order ?? null;
            }

            // Get picking area and floor
            $pickingAreaId = null;
            $floorId = null;

            if ($reservationResult['location_id']) {
                $location = DB::connection('sakemaru')
                    ->table('locations')
                    ->where('id', $reservationResult['location_id'])
                    ->first();
                $floorId = $location->floor_id ?? null;

                $wmsLocation = DB::connection('sakemaru')
                    ->table('wms_locations')
                    ->where('location_id', $reservationResult['location_id'])
                    ->first();
                $pickingAreaId = $wmsLocation->wms_picking_area_id ?? null;
            }

            // Default picking area if not found
            if ($pickingAreaId === null) {
                $defaultArea = DB::connection('sakemaru')
                    ->table('wms_picking_areas')
                    ->where('warehouse_id', $waveSetting->warehouse_id)
                    ->where('is_active', true)
                    ->orderBy('display_order', 'asc')
                    ->first();
                $pickingAreaId = $defaultArea->id ?? null;
            }

            // Find or create picking task for this floor
            $existingTask = DB::connection('sakemaru')
                ->table('wms_picking_tasks')
                ->where('wave_id', $wave->id)
                ->where('floor_id', $floorId)
                ->first();

            if ($existingTask) {
                $pickingTaskId = $existingTask->id;
            } else {
                $pickingTaskId = DB::connection('sakemaru')->table('wms_picking_tasks')->insertGetId([
                    'wave_id' => $wave->id,
                    'wms_picking_area_id' => $pickingAreaId,
                    'warehouse_id' => $waveSetting->warehouse_id,
                    'warehouse_code' => $warehouse->code,
                    'floor_id' => $floorId,
                    'delivery_course_id' => $waveSetting->delivery_course_id,
                    'delivery_course_code' => $course->code,
                    'shipment_date' => $shippingDate,
                    'status' => 'PENDING',
                    'task_type' => 'WAVE',
                    'picker_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Create picking item result for stock_transfer
            if (! $tradeItem->quantity_type) {
                throw new \RuntimeException(
                    "quantity_type must be specified for trade_item ID {$tradeItem->id}"
                );
            }

            DB::connection('sakemaru')->table('wms_picking_item_results')->insert([
                'picking_task_id' => $pickingTaskId,
                'earning_id' => null, // Not an earning
                'source_type' => WmsPickingItemResult::SOURCE_TYPE_STOCK_TRANSFER,
                'stock_transfer_id' => $stockTransferId,
                'trade_id' => $tradeItem->trade_id,
                'trade_item_id' => $tradeItem->id,
                'item_id' => $tradeItem->item_id,
                'real_stock_id' => $reservationResult['real_stock_id'],
                'location_id' => $reservationResult['location_id'],
                'walking_order' => $reservationResult['walking_order'],
                'ordered_qty' => $tradeItem->quantity,
                'ordered_qty_type' => $tradeItem->quantity_type,
                'planned_qty' => $reservationResult['allocated_qty'],
                'planned_qty_type' => $tradeItem->quantity_type,
                'picked_qty' => 0,
                'picked_qty_type' => $tradeItem->quantity_type,
                'shortage_qty' => 0,
                'status' => 'PENDING',
                'picker_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Update stock_transfers picking_status to BEFORE_PICKING
        DB::connection('sakemaru')
            ->table('stock_transfers')
            ->whereIn('id', $stockTransferIds)
            ->update([
                'picking_status' => 'BEFORE_PICKING',
                'updated_at' => now(),
            ]);
    }

    /**
     * ピッキング対象の倉庫間移動伝票クエリを取得
     *
     * 仮想倉庫間移動（物理的ピッキング不要）は除外：
     * - from_warehouse.is_virtual = true AND to_warehouse.is_virtual = true
     * - from_warehouse.stock_warehouse_id == to_warehouse.stock_warehouse_id
     */
    protected function getEligibleStockTransfersQuery(string $shippingDate, int $warehouseId)
    {
        return DB::connection('sakemaru')
            ->table('stock_transfers as st')
            ->join('warehouses as fw', 'st.from_warehouse_id', '=', 'fw.id')
            ->join('warehouses as tw', 'st.to_warehouse_id', '=', 'tw.id')
            ->where('st.delivered_date', $shippingDate)
            ->where('st.is_active', true)
            ->where('st.picking_status', 'BEFORE')
            ->where('st.from_warehouse_id', $warehouseId)
            ->whereNotNull('st.delivery_course_id')
            // 仮想倉庫間移動は対象外
            ->where(function ($query) {
                $query->where(function ($q) {
                    // 両方が仮想倉庫の場合は対象外
                    $q->where('fw.is_virtual', false)
                        ->orWhere('tw.is_virtual', false);
                })
                    ->where(function ($q) {
                        // 同じ実倉庫に紐づく仮想倉庫間の場合は対象外
                        $q->whereRaw('COALESCE(fw.stock_warehouse_id, fw.id) != COALESCE(tw.stock_warehouse_id, tw.id)');
                    });
            })
            ->select('st.*');
    }
}
