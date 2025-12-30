<?php

namespace App\Services;

use App\Models\WmsPickingLog;
use App\Models\WmsPicker;
use App\Models\WmsPickingTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PickingLogService
{
    /**
     * Log picking task start
     */
    public static function logTaskStart(
        Request $request,
        WmsPickingTask $task,
        array $response,
        int $statusCode
    ): void {
        $picker = $request->user();

        // Get first earning_id from picking item results (for logging purposes)
        // Note: Tasks may contain items from multiple earnings after specification change
        $earningId = $task->pickingItemResults()->whereNotNull('earning_id')->value('earning_id');

        WmsPickingLog::create([
            'picker_id' => $picker->id,
            'picker_code' => $picker->code,
            'picker_name' => $picker->name,
            'action_type' => 'START',
            'endpoint' => $request->path(),
            'http_method' => $request->method(),
            'picking_task_id' => $task->id,
            'wave_id' => $task->wave_id,
            'earning_id' => $earningId,
            'status_before' => $task->getOriginal('status'),
            'status_after' => $task->status,
            'request_data' => self::sanitizeRequestData($request),
            'response_data' => $response,
            'response_status_code' => $statusCode,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_id' => $request->input('device_id'),
        ]);
    }

    /**
     * Log picking item update (pick operation)
     */
    public static function logItemPick(
        Request $request,
        int $itemResultId,
        array $itemResultBefore,
        array $itemResultAfter,
        ?array $stockBefore,
        ?array $stockAfter,
        array $response,
        int $statusCode
    ): void {
        $picker = $request->user();

        // Get task info
        $task = DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('id', $itemResultAfter['picking_task_id'])
            ->first();

        // Get item info
        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('id', $itemResultAfter['item_id'])
            ->first();

        // Get earning_id from the item result itself (now stored at item level)
        $earningId = $itemResultAfter['earning_id'] ?? null;

        WmsPickingLog::create([
            'picker_id' => $picker->id,
            'picker_code' => $picker->code,
            'picker_name' => $picker->name,
            'action_type' => 'PICK',
            'endpoint' => $request->path(),
            'http_method' => $request->method(),
            'picking_task_id' => $task->id ?? null,
            'picking_item_result_id' => $itemResultId,
            'wave_id' => $task->wave_id ?? null,
            'earning_id' => $earningId,
            'item_id' => $itemResultAfter['item_id'],
            'item_code' => $item->code ?? null,
            'item_name' => $item->name ?? null,
            'real_stock_id' => $itemResultAfter['real_stock_id'],
            'location_id' => $itemResultAfter['location_id'],
            'planned_qty' => $itemResultAfter['planned_qty'],
            'planned_qty_type' => $itemResultAfter['planned_qty_type'],
            'picked_qty' => $itemResultAfter['picked_qty'],
            'picked_qty_type' => $itemResultAfter['picked_qty_type'],
            'shortage_qty' => $itemResultAfter['shortage_qty'],
            'stock_qty_before' => $stockBefore['current_quantity'] ?? null,
            'stock_qty_after' => $stockAfter['current_quantity'] ?? null,
            'reserved_qty_before' => $stockBefore['reserved_quantity'] ?? null,
            'reserved_qty_after' => $stockAfter['reserved_quantity'] ?? null,
            'picking_qty_before' => $stockBefore['picking_quantity'] ?? null,
            'picking_qty_after' => $stockAfter['picking_quantity'] ?? null,
            'status_before' => $itemResultBefore['status'] ?? null,
            'status_after' => $itemResultAfter['status'],
            'request_data' => self::sanitizeRequestData($request),
            'response_data' => $response,
            'response_status_code' => $statusCode,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_id' => $request->input('device_id'),
        ]);
    }

    /**
     * Log picking task completion
     */
    public static function logTaskComplete(
        Request $request,
        WmsPickingTask $task,
        string $statusBefore,
        array $response,
        int $statusCode
    ): void {
        $picker = $request->user();

        // Get first earning_id from picking item results (for logging purposes)
        // Note: Tasks may contain items from multiple earnings after specification change
        $earningId = $task->pickingItemResults()->whereNotNull('earning_id')->value('earning_id');

        WmsPickingLog::create([
            'picker_id' => $picker->id,
            'picker_code' => $picker->code,
            'picker_name' => $picker->name,
            'action_type' => 'COMPLETE',
            'endpoint' => $request->path(),
            'http_method' => $request->method(),
            'picking_task_id' => $task->id,
            'wave_id' => $task->wave_id,
            'earning_id' => $earningId,
            'status_before' => $statusBefore,
            'status_after' => $task->status,
            'request_data' => self::sanitizeRequestData($request),
            'response_data' => $response,
            'response_status_code' => $statusCode,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_id' => $request->input('device_id'),
        ]);
    }

    /**
     * Log login action
     */
    public static function logLogin(
        Request $request,
        WmsPicker $picker,
        array $response,
        int $statusCode
    ): void {
        WmsPickingLog::create([
            'picker_id' => $picker->id,
            'picker_code' => $picker->code,
            'picker_name' => $picker->name,
            'action_type' => 'LOGIN',
            'endpoint' => $request->path(),
            'http_method' => $request->method(),
            'request_data' => [
                'code' => $request->input('code'),
                'device_id' => $request->input('device_id'),
            ],
            'response_data' => [
                'code' => $response['code'] ?? null,
                'message' => $response['result']['message'] ?? null,
            ],
            'response_status_code' => $statusCode,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_id' => $request->input('device_id'),
        ]);
    }

    /**
     * Log logout action
     */
    public static function logLogout(
        Request $request,
        array $response,
        int $statusCode
    ): void {
        $picker = $request->user();

        WmsPickingLog::create([
            'picker_id' => $picker->id,
            'picker_code' => $picker->code,
            'picker_name' => $picker->name,
            'action_type' => 'LOGOUT',
            'endpoint' => $request->path(),
            'http_method' => $request->method(),
            'request_data' => [],
            'response_data' => $response,
            'response_status_code' => $statusCode,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Sanitize request data (remove sensitive information)
     */
    private static function sanitizeRequestData(Request $request): array
    {
        $data = $request->all();

        // Remove sensitive fields
        unset($data['password']);
        unset($data['token']);
        unset($data['api_key']);

        return $data;
    }
}
