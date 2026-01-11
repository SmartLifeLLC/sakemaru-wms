<?php

namespace App\Services\Print;

use App\Models\PrintRequestQueue;
use App\Models\WmsPickingTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrintRequestService
{
    /**
     * 配送コース別に伝票印刷依頼を作成
     *
     * @param int $deliveryCourseId 配送コースID
     * @param string $shipmentDate 納品日
     * @param int $warehouseId 倉庫ID
     * @param int|null $waveId ウェーブID (Optional)
     * @return array ['success' => bool, 'message' => string, 'queue_id' => int|null]
     */
    public function createPrintRequest(int $deliveryCourseId, string $shipmentDate, int $warehouseId, ?int $waveId = null): array
    {
        try {
            // 配送コース別プリンター設定を取得
            $printerSetting = DB::connection('sakemaru')
                ->table('client_printer_course_settings')
                ->where('warehouse_id', $warehouseId)
                ->where('delivery_course_id', $deliveryCourseId)
                ->where('is_active', true)
                ->first();

            if (!$printerSetting) {
                return [
                    'success' => false,
                    'message' => '配送コースにプリンターが設定されていません。',
                    'queue_id' => null,
                ];
            }

            // プリンタードライバーIDを取得
            $printerDriverId = $printerSetting->printer_driver_id;

            // 対象のピッキングタスクを取得
            $query = WmsPickingTask::where('delivery_course_id', $deliveryCourseId)
                ->where('shipment_date', $shipmentDate);

            if ($waveId) {
                $query->where('wave_id', $waveId);
            }

            $tasks = $query->with(['pickingItemResults'])->get();

            if ($tasks->isEmpty()) {
                return [
                    'success' => false,
                    'message' => '対象のピッキングタスクが見つかりません。',
                    'queue_id' => null,
                ];
            }

            // 全てのearning_idを収集
            $earningIds = [];
            foreach ($tasks as $task) {
                foreach ($task->pickingItemResults as $itemResult) {
                    if ($itemResult->earning_id && !in_array($itemResult->earning_id, $earningIds)) {
                        $earningIds[] = $itemResult->earning_id;
                    }
                }
            }

            if (empty($earningIds)) {
                return [
                    'success' => false,
                    'message' => '印刷対象の売上が見つかりません。',
                    'queue_id' => null,
                ];
            }

            // client_idを取得（最初のearningから）
            $clientId = DB::connection('sakemaru')
                ->table('earnings')
                ->where('id', $earningIds[0])
                ->value('client_id');

            if (!$clientId) {
                return [
                    'success' => false,
                    'message' => 'クライアントIDが取得できません。',
                    'queue_id' => null,
                ];
            }

            // print_request_queueにレコードを作成
            // printer_driver_idが指定されているため CLIENT_SLIP_PRINTER を使用
            $queue = PrintRequestQueue::create([
                'client_id' => $clientId,
                'earning_ids' => $earningIds,
                'print_type' => PrintRequestQueue::PRINT_TYPE_CLIENT_SLIP_PRINTER,
                'group_by_delivery_course' => true,
                'warehouse_id' => $warehouseId,
                'printer_driver_id' => $printerDriverId,
                'status' => PrintRequestQueue::STATUS_PENDING,
            ]);

            Log::info('Print request created', [
                'queue_id' => $queue->id,
                'delivery_course_id' => $deliveryCourseId,
                'earning_ids' => $earningIds,
                'printer_driver_id' => $printerDriverId,
            ]);

            return [
                'success' => true,
                'message' => '印刷依頼を作成しました。',
                'queue_id' => $queue->id,
                'earning_count' => count($earningIds),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create print request', [
                'delivery_course_id' => $deliveryCourseId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => '印刷依頼の作成に失敗しました: ' . $e->getMessage(),
                'queue_id' => null,
            ];
        }
    }
}
