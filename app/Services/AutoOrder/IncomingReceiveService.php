<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsIncomingImportError;
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
    /** 送料JANコード（単価比較対象外） */
    private const SHIPPING_JAN_CODE = '9999999999996';

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
            $result = $this->matchSlip($slip, $file);

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
    private function matchSlip(WmsIncomingReceivedSlip $slip, WmsIncomingReceivedFile $file): string
    {
        // slip_numberで入荷予定を検索（同一伝票番号の全入荷予定を取得）
        $schedules = WmsOrderIncomingSchedule::where('slip_number', $slip->slip_number)
            ->get();

        if ($schedules->isEmpty()) {
            // SLIP_NOT_FOUND ワーニング記録
            WmsIncomingImportError::create([
                'received_file_id' => $file->id,
                'received_slip_id' => $slip->id,
                'error_type' => 'WARNING',
                'error_code' => 'SLIP_NOT_FOUND',
                'error_message' => "伝票番号 {$slip->slip_number} に対応する入荷予定が見つかりません",
            ]);

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

        // 入荷予定のordering_codeマップを構築（Step 1用）
        $schedulesByOrderingCode = [];
        foreach ($schedules as $schedule) {
            if ($schedule->search_code) {
                foreach (explode(',', $schedule->search_code) as $code) {
                    $code = trim($code);
                    if ($code !== '') {
                        $schedulesByOrderingCode[$code] = $schedule;
                    }
                }
            }
        }

        // 明細レベルの照合
        $hasShortage = false;
        $hasPartial = false;
        $details = $slip->details;
        $totalShippedQty = 0;
        $matchedSchedule = $schedules->first();

        foreach ($details as $detail) {
            $matchResult = $this->matchDetailWithSchedules(
                $detail, $schedules, $schedulesByOrderingCode, $file
            );

            if ($matchResult['matched_schedule']) {
                $matchedSchedule = $matchResult['matched_schedule'];
            }

            if (! $detail->is_shortage) {
                $totalShippedQty += $detail->total_quantity;
            }

            if ($detail->match_status === 'SHORTAGE') {
                $hasShortage = true;
            } elseif ($detail->match_status === 'PARTIAL') {
                $hasPartial = true;
            }
        }

        // 最初に見つかったscheduleに対してslip-level更新
        $slip->update(['matched_schedule_id' => $matchedSchedule->id]);

        // shipped_quantity と仕入先単価を書き戻し
        $this->writebackShippedData($matchedSchedule, $details, $totalShippedQty, $file);

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
     * 明細と入荷予定の3段階マッチング
     *
     * Step 1: ordering_code(search_code) と d_jan_code の比較
     * Step 2: item_search_information から検索
     * Step 3: ITEM_NOT_FOUND エラー記録
     */
    private function matchDetailWithSchedules(
        $detail,
        $schedules,
        array $schedulesByOrderingCode,
        WmsIncomingReceivedFile $file
    ): array {
        $janCode = $detail->d_jan_code;
        $itemCode = $detail->d_item_code;
        $matchedSchedule = null;
        $matchedItemId = null;

        // Step 1: ordering_code（search_code）と d_jan_code で照合
        if ($janCode && isset($schedulesByOrderingCode[$janCode])) {
            $matchedSchedule = $schedulesByOrderingCode[$janCode];
            $matchedItemId = $matchedSchedule->item_id;
        }

        // Step 1b: d_item_code でも試行
        if (! $matchedItemId && $itemCode) {
            foreach ($schedules as $schedule) {
                $scheduleItem = $schedule->item;
                if ($scheduleItem && $scheduleItem->code === $itemCode) {
                    $matchedSchedule = $schedule;
                    $matchedItemId = $schedule->item_id;
                    break;
                }
            }
        }

        // Step 2: item_search_information から検索
        if (! $matchedItemId) {
            $searchCodes = array_filter([$janCode, $itemCode]);
            if (! empty($searchCodes)) {
                $itemId = DB::connection('sakemaru')
                    ->table('item_search_information')
                    ->whereIn('search_string', $searchCodes)
                    ->value('item_id');

                if ($itemId) {
                    $matchedItemId = $itemId;
                    // scheduleからも特定
                    $matchedSchedule = $schedules->firstWhere('item_id', $itemId);
                }
            }
        }

        // Step 3: 商品不明
        if (! $matchedItemId) {
            WmsIncomingImportError::create([
                'received_file_id' => $file->id,
                'received_slip_id' => $detail->received_slip_id,
                'received_detail_id' => $detail->id,
                'error_type' => 'ERROR',
                'error_code' => 'ITEM_NOT_FOUND',
                'error_message' => "商品を特定できません: JAN={$janCode}, 商品CD={$itemCode}",
                'item_code' => $janCode ?: $itemCode,
                'raw_data' => [
                    'd_jan_code' => $janCode,
                    'd_item_code' => $itemCode,
                    'd_product_name' => $detail->d_product_name,
                ],
            ]);

            $detail->update(['match_status' => 'NOT_FOUND']);

            return ['matched_schedule' => $matchedSchedule ?? $schedules->first()];
        }

        // 商品一致 → 数量照合
        $detail->update(['matched_item_id' => $matchedItemId]);

        $expectedQty = $matchedSchedule?->expected_quantity ?? 0;
        $detail->update(['expected_quantity' => $expectedQty]);

        if ($detail->is_shortage || $detail->total_quantity === 0) {
            $detail->update(['match_status' => 'SHORTAGE']);
        } elseif ($detail->total_quantity < $expectedQty) {
            $detail->update(['match_status' => 'PARTIAL']);
        } else {
            $detail->update(['match_status' => 'MATCHED']);
        }

        return ['matched_schedule' => $matchedSchedule ?? $schedules->first()];
    }

    /**
     * shipped_quantity と仕入先単価を入荷予定に書き戻し
     */
    private function writebackShippedData(
        WmsOrderIncomingSchedule $schedule,
        $details,
        int $totalShippedQty,
        WmsIncomingReceivedFile $file
    ): void {
        // 対象商品の明細を取得
        $matchedDetail = $details->firstWhere('matched_item_id', $schedule->item_id);

        // price_type 判定
        $priceType = 'CASE'; // デフォルト
        $partnerUnitPrice = null;
        $partnerCasePrice = null;

        if ($matchedDetail) {
            $rawUnitPrice = $matchedDetail->d_unit_price;

            // JX: 原単価は整数（下2桁が小数部）
            $partnerPrice = is_numeric($rawUnitPrice) ? (float) $rawUnitPrice / 100 : 0;

            $caseQty = (int) ($matchedDetail->d_case_quantity ?? 0);
            $pieceQty = (int) ($matchedDetail->d_piece_quantity ?? 0);

            if ($caseQty > 0) {
                $priceType = 'CASE';
                $partnerCasePrice = $partnerPrice;
            } elseif ($pieceQty > 0) {
                $priceType = 'PIECE';
                $partnerUnitPrice = $partnerPrice;
            } else {
                // 欠品時もデフォルト CASE
                $priceType = 'CASE';
                $partnerCasePrice = $partnerPrice;
            }
        }

        $schedule->update([
            'shipped_quantity' => $totalShippedQty,
            'partner_unit_price' => $partnerUnitPrice,
            'partner_case_price' => $partnerCasePrice,
            'price_type' => $priceType,
            'is_receive_matched' => true,
            'shortage_quantity' => max(0, $schedule->expected_quantity - $totalShippedQty),
        ]);

        // 単価不一致チェック（送料は除外）
        $janCode = $matchedDetail?->d_jan_code;
        if ($janCode !== self::SHIPPING_JAN_CODE && $matchedDetail) {
            $this->checkPriceMismatch($schedule, $file, $matchedDetail);
        }
    }

    /**
     * 単価不一致チェック
     */
    private function checkPriceMismatch(
        WmsOrderIncomingSchedule $schedule,
        WmsIncomingReceivedFile $file,
        $detail
    ): void {
        $priceType = $schedule->price_type;
        $hasMismatch = false;
        $expectedPrice = null;
        $actualPrice = null;

        if ($priceType === 'CASE' && $schedule->case_price !== null && $schedule->partner_case_price !== null) {
            if ((float) $schedule->case_price !== (float) $schedule->partner_case_price) {
                $hasMismatch = true;
                $expectedPrice = $schedule->case_price;
                $actualPrice = $schedule->partner_case_price;
            }
        } elseif ($priceType === 'PIECE' && $schedule->unit_price !== null && $schedule->partner_unit_price !== null) {
            if ((float) $schedule->unit_price !== (float) $schedule->partner_unit_price) {
                $hasMismatch = true;
                $expectedPrice = $schedule->unit_price;
                $actualPrice = $schedule->partner_unit_price;
            }
        }

        if ($hasMismatch) {
            WmsIncomingImportError::create([
                'received_file_id' => $file->id,
                'received_slip_id' => $detail->received_slip_id,
                'received_detail_id' => $detail->id,
                'error_type' => 'WARNING',
                'error_code' => 'PRICE_MISMATCH',
                'error_message' => "単価不一致（{$priceType}）: 自社={$expectedPrice} vs 仕入先={$actualPrice}",
                'item_code' => $detail->d_jan_code ?: $detail->d_item_code,
                'expected_price' => $expectedPrice,
                'actual_price' => $actualPrice,
            ]);
        }
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

        // 発注先特定: 受信ファイルの contractor_id → b_contractor_code（contractors.code）の順で解決
        $contractor = null;
        $receivedFile = $slip->file;
        if ($receivedFile?->contractor_id) {
            $contractor = Contractor::find($receivedFile->contractor_id);
        }
        if (! $contractor && $slip->b_contractor_code) {
            $contractor = Contractor::where('code', $slip->b_contractor_code)->first();
        }

        // 日付パース
        $orderDate = $this->parseJxDate($slip->b_order_date);
        $deliveryDate = $this->parseJxDate($slip->b_delivery_date);

        $details = $slip->details;
        $createdCount = 0;

        foreach ($details as $detail) {
            // 3段階で商品特定
            $itemId = $this->resolveItemId($detail);

            $isShortage = $detail->is_shortage || $detail->total_quantity === 0;
            $shippedQty = $isShortage ? 0 : $detail->total_quantity;

            // 同一伝票番号+商品の既存スケジュールがあればスキップ（再実行時の重複防止、キャンセル済みは除外）
            $existingSchedule = WmsOrderIncomingSchedule::where('slip_number', $slip->slip_number)
                ->where('item_id', $itemId)
                ->whereNotIn('status', [
                    IncomingScheduleStatus::CANCELLED->value,
                    IncomingScheduleStatus::PARTIAL_CANCELLED->value,
                ])
                ->first();

            if ($existingSchedule) {
                $detail->update([
                    'matched_item_id' => $itemId,
                    'match_status' => $isShortage ? 'SHORTAGE' : 'MATCHED',
                ]);
                $createdCount++;

                continue;
            }

            // 仕入先単価を明細から取得（d_unit_price は下2桁小数の整数表現）
            $rawUnitPrice = $detail->d_unit_price;
            $partnerPrice = is_numeric($rawUnitPrice) ? (float) $rawUnitPrice / 100 : null;

            $caseQty = (int) ($detail->d_case_quantity ?? 0);
            $priceType = $caseQty > 0 ? 'CASE' : 'PIECE';
            $partnerUnitPrice = $priceType === 'PIECE' ? $partnerPrice : null;
            $partnerCasePrice = $priceType === 'CASE' ? $partnerPrice : null;

            // 賞味期限: 商品マスタの default_expiration_days から算出
            $expirationDate = $this->calculateExpirationDate($itemId, $deliveryDate);

            // 商品コード・検索コードを取得
            $itemCode = Item::where('id', $itemId)->value('code');
            $searchCode = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $itemId)
                ->where('is_used_for_ordering', true)
                ->where('is_active', true)
                ->value('search_string');

            $schedule = WmsOrderIncomingSchedule::create([
                'warehouse_id' => $warehouse->id,
                'item_id' => $itemId,
                'item_code' => $itemCode,
                'search_code' => $searchCode,
                'contractor_id' => $contractor?->id,
                'order_source' => OrderSource::RECEIVED,
                'slip_number' => $slip->slip_number,
                'expected_quantity' => $shippedQty,
                'shipped_quantity' => $shippedQty,
                'received_quantity' => $shippedQty, // 発注先出荷実績をプリセット（検品時に不一致なら変更）
                'shortage_quantity' => 0,
                'is_receive_matched' => true,
                'partner_unit_price' => $partnerUnitPrice,
                'partner_case_price' => $partnerCasePrice,
                'price_type' => $priceType,
                'order_date' => $orderDate,
                'expected_arrival_date' => $deliveryDate,
                'expiration_date' => $expirationDate,
                'actual_arrival_date' => null,
                'status' => IncomingScheduleStatus::PENDING,
                'confirmed_at' => null,
            ]);

            // 明細にもマッチ情報をセット
            $detail->update([
                'matched_item_id' => $itemId,
                'match_status' => $isShortage ? 'SHORTAGE' : 'MATCHED',
            ]);

            $createdCount++;
        }

        return $createdCount;
    }

    /**
     * 明細から商品IDを解決
     *
     * Step 1: d_item_code → items.code
     * Step 2: d_jan_code / d_item_code → item_search_information.search_string
     */
    private function resolveItemId($detail): ?int
    {
        // Step 1: items.code で直接検索
        if ($detail->d_item_code) {
            $item = Item::where('code', $detail->d_item_code)->first();
            if ($item) {
                return $item->id;
            }
        }

        // Step 2: item_search_information
        $searchCodes = array_filter([$detail->d_jan_code, $detail->d_item_code]);
        if (! empty($searchCodes)) {
            $itemId = DB::connection('sakemaru')
                ->table('item_search_information')
                ->whereIn('search_string', $searchCodes)
                ->value('item_id');
            if ($itemId) {
                return $itemId;
            }
        }

        return null;
    }

    /**
     * JX日付文字列をパース（YYYYMMDD / YYMMDD / YYYY/MM/DD形式）
     */
    private function parseJxDate(?string $dateStr): ?string
    {
        if (! $dateStr || trim($dateStr) === '') {
            return null;
        }

        try {
            $str = trim($dateStr);

            // YYYYMMDD形式
            if (preg_match('/^\d{8}$/', $str)) {
                return Carbon::createFromFormat('Ymd', $str)->format('Y-m-d');
            }

            // YYMMDD形式（和暦ではなく西暦下2桁として扱う: 26→2026）
            if (preg_match('/^\d{6}$/', $str)) {
                return Carbon::createFromFormat('ymd', $str)->format('Y-m-d');
            }

            // YYYY/MM/DD or YYYY-MM-DD形式
            return Carbon::parse($str)->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('[IncomingReceiveService] 日付パース失敗', [
                'dateStr' => $dateStr,
                'error' => $e->getMessage(),
            ]);

            return null;
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
            // 発注先出荷実績を入庫検品数にプリセット（検品時に不一致なら変更）
            $shippedQty = $matchedDetail->total_quantity;
            $shortageQty = max(0, $schedule->expected_quantity - $shippedQty);

            // 仕入先単価を明細から取得（d_unit_price は下2桁小数の整数表現）
            $rawUnitPrice = $matchedDetail->d_unit_price;
            $partnerPrice = is_numeric($rawUnitPrice) ? (float) $rawUnitPrice / 100 : null;

            $caseQty = (int) ($matchedDetail->d_case_quantity ?? 0);
            $priceType = $caseQty > 0 ? 'CASE' : 'PIECE';
            $partnerUnitPrice = $priceType === 'PIECE' ? $partnerPrice : null;
            $partnerCasePrice = $priceType === 'CASE' ? $partnerPrice : null;

            // 賞味期限: 未設定の場合、商品マスタの default_expiration_days から算出
            $expirationDate = $schedule->expiration_date
                ?? $this->calculateExpirationDate($schedule->item_id, $schedule->expected_arrival_date);

            $schedule->update([
                'shipped_quantity' => $shippedQty,
                'received_quantity' => $shippedQty,
                'shortage_quantity' => $shortageQty,
                'partner_unit_price' => $partnerUnitPrice,
                'partner_case_price' => $partnerCasePrice,
                'price_type' => $priceType,
                'expiration_date' => $expirationDate,
                'is_receive_matched' => true,
            ]);
            // ステータスはPENDINGのまま（Handy検品で確定）
        } elseif ($slip->match_status === 'SHORTAGE') {
            // 欠品の場合 → 全量欠品として記録
            $schedule->update([
                'shortage_quantity' => $schedule->expected_quantity,
                'is_receive_matched' => true,
            ]);
        }
    }

    /**
     * 商品マスタの default_expiration_days から賞味期限を算出
     */
    private function calculateExpirationDate(?int $itemId, $baseDate): ?string
    {
        if (! $itemId || ! $baseDate) {
            return null;
        }

        $item = Item::find($itemId);
        if (! $item || ! $item->default_expiration_days) {
            return null;
        }

        $base = $baseDate instanceof Carbon ? $baseDate : Carbon::parse($baseDate);

        return $base->copy()->addDays($item->default_expiration_days)->format('Y-m-d');
    }
}
