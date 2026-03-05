<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsIncomingReceivedFile;
use App\Models\WmsIncomingReceivedSlip;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingParsers\JxIncomingParser;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 入荷受信データサービス
 *
 * JX/CSVの受信データをパースし、入荷予定と照合する
 */
class IncomingReceiveService
{
    /**
     * JXデータをパースして保存
     */
    public function parseJxData(string $content, string $filename, ?int $contractorId = null): WmsIncomingReceivedFile
    {
        $parser = new JxIncomingParser;

        return $parser->parse($content, $filename, $contractorId);
    }

    /**
     * 受信ファイルの伝票を入荷予定と照合
     */
    public function matchWithSchedules(WmsIncomingReceivedFile $file): array
    {
        $matchedCount = 0;
        $unmatchedCount = 0;
        $shortageCount = 0;

        $slips = $file->slips()->with('details')->get();

        foreach ($slips as $slip) {
            $result = $this->matchSlip($slip);

            match ($result) {
                'MATCHED' => $matchedCount++,
                'SHORTAGE', 'PARTIAL' => $shortageCount++,
                default => $unmatchedCount++,
            };
        }

        // ファイルステータス更新
        $file->update([
            'status' => $unmatchedCount === 0 ? 'MATCHED' : 'PENDING',
        ]);

        Log::info('[IncomingReceiveService] 照合完了', [
            'file_id' => $file->id,
            'matched' => $matchedCount,
            'unmatched' => $unmatchedCount,
            'shortage' => $shortageCount,
        ]);

        return [
            'matched' => $matchedCount,
            'unmatched' => $unmatchedCount,
            'shortage' => $shortageCount,
            'total' => $slips->count(),
        ];
    }

    /**
     * 伝票単位の照合
     */
    private function matchSlip(WmsIncomingReceivedSlip $slip): string
    {
        // slip_numberで入荷予定を検索
        $schedule = WmsOrderIncomingSchedule::where('slip_number', $slip->slip_number)
            ->first();

        if (! $schedule) {
            // 未照合 → 受信データから入荷予定を新規作成
            $created = $this->createSchedulesFromSlip($slip);

            if ($created > 0) {
                $slip->update(['match_status' => 'CREATED']);
                Log::info('[IncomingReceiveService] 未照合伝票から入荷予定を新規作成', [
                    'slip_id' => $slip->id,
                    'slip_number' => $slip->slip_number,
                    'created_count' => $created,
                ]);

                return 'MATCHED';
            }

            $slip->update(['match_status' => 'NOT_FOUND']);

            return 'NOT_FOUND';
        }

        $slip->update(['matched_schedule_id' => $schedule->id]);

        // 照合フラグをセット
        $schedule->update(['is_receive_matched' => true]);

        // 明細レベルの照合
        $hasShortage = false;
        $hasPartial = false;
        $details = $slip->details;

        foreach ($details as $detail) {
            $this->matchDetail($detail, $schedule);

            if ($detail->match_status === 'SHORTAGE') {
                $hasShortage = true;
            } elseif ($detail->match_status === 'PARTIAL') {
                $hasPartial = true;
            }
        }

        // 伝票の欠品チェック：入荷予定にある商品が受信データに無い場合
        // （入荷予定は1品1レコードなので、schedule自体のitem_idを確認）
        $receivedItemCodes = $details->pluck('d_item_code')->filter()->toArray();
        $scheduleItemCode = $schedule->item?->code;

        if ($scheduleItemCode && ! in_array($scheduleItemCode, $receivedItemCodes)) {
            // 商品がDレコードに存在しない → 欠品
            $hasShortage = true;
        }

        // 伝票ステータス決定
        $status = 'MATCHED';
        if ($hasShortage) {
            $status = 'SHORTAGE';
        } elseif ($hasPartial) {
            $status = 'PARTIAL';
        }

        $slip->update([
            'match_status' => $status,
            'shortage_count' => $details->where('is_shortage', true)->count()
                + $details->where('match_status', 'SHORTAGE')->count(),
        ]);

        return $status;
    }

    /**
     * 未照合伝票から入荷予定を新規作成
     *
     * 各明細（detail）ごとに wms_order_incoming_schedules を1件作成
     */
    private function createSchedulesFromSlip(WmsIncomingReceivedSlip $slip): int
    {
        // 倉庫特定: b_shop_code（4桁ゼロ埋め）→ warehouses.code（ltrim('0')で照合）
        $warehouseCode = ltrim($slip->b_shop_code ?? '', '0');
        $warehouse = Warehouse::where(DB::raw('LTRIM(code)'), $warehouseCode)
            ->orWhere('code', $slip->b_shop_code)
            ->first();

        if (! $warehouse) {
            Log::warning('[IncomingReceiveService] 倉庫コード解決失敗', [
                'slip_id' => $slip->id,
                'b_shop_code' => $slip->b_shop_code,
            ]);

            return 0;
        }

        // 発注先特定: b_contractor_code → contractors.code
        $contractor = null;
        if ($slip->b_contractor_code) {
            $contractor = Contractor::where('code', $slip->b_contractor_code)->first();
        }

        // 日付パース
        $orderDate = $this->parseJxDate($slip->b_order_date);
        $deliveryDate = $this->parseJxDate($slip->b_delivery_date);

        $details = $slip->details;
        $createdCount = 0;

        foreach ($details as $detail) {
            // d_item_code → items.code で item_id を解決
            $item = null;
            if ($detail->d_item_code) {
                $item = Item::where('code', $detail->d_item_code)->first();
            }

            $isShortage = $detail->is_shortage || $detail->total_quantity === 0;
            $expectedQty = $isShortage ? 0 : $detail->total_quantity;
            $receivedQty = $isShortage ? 0 : $detail->total_quantity;
            $shortageQty = $isShortage ? $expectedQty : 0;

            $schedule = WmsOrderIncomingSchedule::create([
                'warehouse_id' => $warehouse->id,
                'item_id' => $item?->id,
                'contractor_id' => $contractor?->id,
                'order_source' => OrderSource::RECEIVED,
                'slip_number' => $slip->slip_number,
                'expected_quantity' => $expectedQty,
                'received_quantity' => $receivedQty,
                'shortage_quantity' => $shortageQty,
                'is_receive_matched' => true,
                'order_date' => $orderDate,
                'expected_arrival_date' => $deliveryDate,
                'actual_arrival_date' => $deliveryDate ?? now()->format('Y-m-d'),
                'status' => $receivedQty > 0 ? IncomingScheduleStatus::CONFIRMED : IncomingScheduleStatus::PENDING,
                'confirmed_at' => $receivedQty > 0 ? now() : null,
            ]);

            // 明細にもマッチ情報をセット
            $detail->update([
                'matched_item_id' => $item?->id,
                'match_status' => $isShortage ? 'SHORTAGE' : 'MATCHED',
            ]);

            $createdCount++;
        }

        return $createdCount;
    }

    /**
     * JX日付文字列をパース（YYYYMMDD or YYYY/MM/DD形式）
     */
    private function parseJxDate(?string $dateStr): ?string
    {
        if (! $dateStr || trim($dateStr) === '') {
            return null;
        }

        try {
            // YYYYMMDD形式
            if (preg_match('/^\d{8}$/', trim($dateStr))) {
                return Carbon::createFromFormat('Ymd', trim($dateStr))->format('Y-m-d');
            }

            // YYYY/MM/DD or YYYY-MM-DD形式
            return Carbon::parse(trim($dateStr))->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('[IncomingReceiveService] 日付パース失敗', [
                'dateStr' => $dateStr,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 明細単位の照合
     */
    private function matchDetail($detail, WmsOrderIncomingSchedule $schedule): void
    {
        // 自社コードで入荷予定の商品と照合
        $itemCode = $detail->d_item_code;
        $scheduleItemCode = $schedule->item?->code;

        if (! $itemCode || ! $scheduleItemCode) {
            return;
        }

        if ($itemCode !== $scheduleItemCode) {
            // この入荷予定の商品ではない（他の入荷予定に対応する可能性）
            $detail->update(['match_status' => 'EXTRA']);

            return;
        }

        // 商品一致 → 数量照合
        $detail->update(['matched_item_id' => $schedule->item_id]);

        $expectedQty = $schedule->expected_quantity;
        $detail->update(['expected_quantity' => $expectedQty]);

        if ($detail->is_shortage || $detail->total_quantity === 0) {
            $detail->update(['match_status' => 'SHORTAGE']);
        } elseif ($detail->total_quantity < $expectedQty) {
            $detail->update(['match_status' => 'PARTIAL']);
        } else {
            $detail->update(['match_status' => 'MATCHED']);
        }
    }

    /**
     * 照合済みデータを入荷予定に適用
     */
    public function applyMatched(WmsIncomingReceivedFile $file): array
    {
        $appliedCount = 0;
        $errors = [];

        $slips = $file->slips()
            ->whereIn('match_status', ['MATCHED', 'PARTIAL', 'SHORTAGE'])
            ->whereNotNull('matched_schedule_id')
            ->with('details')
            ->get();

        foreach ($slips as $slip) {
            try {
                $this->applySlip($slip);
                $appliedCount++;
            } catch (\Exception $e) {
                $errors[] = [
                    'slip_id' => $slip->id,
                    'slip_number' => $slip->slip_number,
                    'error' => $e->getMessage(),
                ];
                Log::error('[IncomingReceiveService] 適用エラー', [
                    'slip_id' => $slip->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ファイルステータス更新
        if ($appliedCount > 0) {
            $file->update(['status' => 'APPLIED']);
        }

        return [
            'applied' => $appliedCount,
            'errors' => $errors,
        ];
    }

    /**
     * 伝票単位の適用
     */
    private function applySlip(WmsIncomingReceivedSlip $slip): void
    {
        $schedule = WmsOrderIncomingSchedule::find($slip->matched_schedule_id);
        if (! $schedule) {
            throw new \RuntimeException("入荷予定が見つかりません: {$slip->matched_schedule_id}");
        }

        // 対象商品の明細を取得
        $matchedDetail = $slip->details()
            ->where('matched_item_id', $schedule->item_id)
            ->whereIn('match_status', ['MATCHED', 'PARTIAL'])
            ->first();

        if ($matchedDetail) {
            // 出荷数量を入荷予定に反映
            $receivedQty = $matchedDetail->total_quantity;
            $shortageQty = max(0, $schedule->expected_quantity - $receivedQty);

            $schedule->update([
                'received_quantity' => $receivedQty,
                'shortage_quantity' => $shortageQty,
                'is_receive_matched' => true,
            ]);

            if ($receivedQty >= $schedule->expected_quantity) {
                $schedule->update(['status' => 'CONFIRMED']);
            } elseif ($receivedQty > 0) {
                $schedule->update(['status' => 'PARTIAL']);
            }
        } elseif ($slip->match_status === 'SHORTAGE') {
            // 欠品の場合 → 全量欠品として記録
            $schedule->update([
                'shortage_quantity' => $schedule->expected_quantity,
                'is_receive_matched' => true,
            ]);
        }
    }
}
