<?php

namespace App\Services\InventoryCount;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InventoryCountFinalizationService
{
    private const DETAILS_PER_VOUCHER = 200;

    public function confirmedRounds(WmsInventoryCount $count): array
    {
        if (! in_array($count->status, [WmsInventoryCount::STATUS_COUNTING, WmsInventoryCount::STATUS_CHECKED], true)) {
            return [];
        }

        return array_filter([
            2 => $count->second_count_confirmed_at !== null ? '2回目' : null,
            3 => $count->final_count_confirmed_at !== null ? '3回目' : null,
        ], fn ($label) => $label !== null);
    }

    public function preview(WmsInventoryCount $count, ?int $round = null): array
    {
        $count = $count->fresh();
        $round ??= array_key_last($this->confirmedRounds($count));
        $this->assertReady($count, $round);

        // PDFと同じ対象・確定差異を利用する。未入力は出力上のみ0とし、入力履歴を改変しない。
        $items = (new InventoryDiffListPdfService)->diffItemsForRound($count, $round);
        $stocks = DB::connection('sakemaru')->table('real_stocks as rs')
            ->leftJoin('stock_allocations as sa', 'sa.id', '=', 'rs.stock_allocation_id')
            ->whereIn('rs.id', $items->pluck('real_stock_id')->filter()->unique())
            ->get(['rs.id', 'rs.client_id', 'rs.warehouse_id', 'rs.item_id', 'sa.code'])
            ->keyBy('id');
        $defaultAllocationCode = DB::connection('sakemaru')->table('stock_allocations')
            ->where('client_id', $count->client_id)->where('code', '1')->value('code');

        $rows = $items->sortBy('id')->map(function (WmsInventoryCountItem $item) use ($stocks, $count, $round, $defaultAllocationCode): array {
            if ((int) $item->item?->client_id !== (int) $count->client_id
                || (string) $item->item?->code !== (string) $item->item_code) {
                throw ValidationException::withMessages(['inventory_count' => "商品{$item->item_code}のマスタ対応を確認してください。"]);
            }
            $stock = $stocks->get($item->real_stock_id);
            if ($item->real_stock_id && (! $stock
                || (int) $stock->client_id !== (int) $count->client_id
                || (int) $stock->warehouse_id !== (int) $count->warehouse_id
                || (int) $stock->item_id !== (int) $item->item_id)) {
                throw ValidationException::withMessages(['inventory_count' => "商品{$item->item_code}の在庫行を確認してください。"]);
            }
            $allocationCode = $stock ? $stock->code : $defaultAllocationCode;
            if ($allocationCode === null) {
                throw ValidationException::withMessages(['inventory_count' => "商品{$item->item_code}の在庫区分が見つかりません。"]);
            }

            $quantityColumn = $round === 2 ? 'second_count_quantity' : 'final_count_quantity';
            if ($round === 2 && $item->second_count_quantity === null) {
                $quantityColumn = 'first_count_quantity';
            }
            $confirmedPrefix = $round === 2 ? 'second_count_confirmed_' : 'final_count_confirmed_';
            foreach ([$quantityColumn, $confirmedPrefix.'system_quantity', $confirmedPrefix.'difference_quantity'] as $column) {
                if ($item->getRawOriginal($column) !== null) {
                    $this->integerQuantity($item->getRawOriginal($column));
                }
            }
            if ($item->getRawOriginal($confirmedPrefix.'system_quantity') === null) {
                $this->integerQuantity($item->getRawOriginal('ending_system_quantity') ?? $item->getRawOriginal('system_quantity'));
            }

            $before = $this->integerQuantity($item->pdf_system_quantity);
            $after = $this->integerQuantity($item->pdf_actual_quantity);
            $difference = $this->integerQuantity($item->pdf_end_difference_quantity);
            if ($after - $before !== $difference) {
                throw ValidationException::withMessages(['inventory_count' => "商品{$item->item_code}の確定差異と数量が一致しません。"]);
            }

            return [
                'item_id' => (int) $item->item_id,
                'wms_inventory_count_item_id' => (int) $item->id,
                'real_stock_id' => $item->real_stock_id ? (int) $item->real_stock_id : null,
                'item_code' => (string) $item->item_code,
                'item_name' => (string) $item->item_name,
                'stock_allocation_code' => (string) $allocationCode,
                'stock_quantity_before' => $before,
                'stock_quantity_after' => $after,
                'inventory_adjustment_quantity' => $difference,
                'unit_price' => (float) $item->cost_price,
                'amount' => round($difference * (float) $item->cost_price, 2),
                'count_round' => $round,
                'uncounted_as_zero' => $item->roundQuantity($round) === null,
                'location_no' => (string) $item->location_no,
                'note' => $this->detailNote($count, $before, $after, $difference),
            ];
        })->values()->all();

        $itemDifferences = collect($rows)->groupBy('item_id')->map(fn ($items) => $items->sum('inventory_adjustment_quantity'));

        return [
            'rows' => $rows,
            'count_round' => $round,
            'detail_count' => count($rows),
            'voucher_item_count' => $itemDifferences->filter(fn ($quantity) => $quantity !== 0)->count(),
            'uncounted_count' => count(array_filter($rows, fn ($row) => $row['uncounted_as_zero'])),
            'increase_quantity' => $itemDifferences->sum(fn ($quantity) => max(0, $quantity)),
            'decrease_quantity' => $itemDifferences->sum(fn ($quantity) => min(0, $quantity)),
            'token' => hash('sha256', json_encode([$count->id, $round,
                ($round === 2 ? $count->second_count_confirmed_at : $count->final_count_confirmed_at)?->toISOString(), $rows], JSON_THROW_ON_ERROR)),
        ];
    }

    public function confirm(WmsInventoryCount $count, int $userId, string $date, string $token, ?int $round = null): void
    {
        Validator::make(['adjustment_date' => $date], ['adjustment_date' => ['required', 'date_format:Y-m-d']])->validate();
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_date')
            || ! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'inventory_adjustment_count_round')) {
            throw ValidationException::withMessages(['inventory_count' => '棚卸し伝票日付のDB更新が必要です。']);
        }

        DB::connection('sakemaru')->transaction(function () use ($count, $userId, $date, $token, $round): void {
            $locked = WmsInventoryCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === WmsInventoryCount::STATUS_CONFIRMED) {
                if ($locked->inventory_adjustment_date?->toDateString() !== $date
                    || ($round !== null && $locked->inventory_adjustment_count_round !== $round)) {
                    throw ValidationException::withMessages(['adjustment_date' => '最終確定済みの日付・対象回は変更できません。']);
                }

                return;
            }

            $preview = $this->preview($locked, $round);
            if ($date < $locked->count_date->toDateString() || $date < InventoryCountLedgerBalanceService::OPENING_DATE) {
                throw ValidationException::withMessages(['adjustment_date' => '棚卸し日以降の伝票日付を指定してください。']);
            }
            if (! hash_equals($preview['token'], $token)) {
                throw ValidationException::withMessages(['inventory_count' => '確認後に棚卸しデータが変わりました。画面を閉じて再確認してください。']);
            }

            $connection = DB::connection('sakemaru');
            if ($connection->table('inventory_adjustment_queue')->where('wms_inventory_count_id', $locked->id)->exists()
                || $connection->table('inventory_adjustments')->where('wms_inventory_count_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['inventory_count' => '既存の棚卸し伝票連携があるため、重複作成を停止しました。連携状況を確認してください。']);
            }

            $queueIds = [];
            $requestIds = [];
            $voucherRows = $this->voucherRows($locked, $date, $preview['rows']);
            foreach (array_chunk($voucherRows, self::DETAILS_PER_VOUCHER) as $index => $rows) {
                $part = $index + 1;
                $requestId = "wms-inventory-final-{$locked->id}-{$part}";
                $queueIds[] = $connection->table('inventory_adjustment_queue')->insertGetId([
                    'client_id' => $locked->client_id,
                    'slip_number' => "{$locked->count_no}-{$part}",
                    'process_date' => $date,
                    'adjustment_date' => $date,
                    'warehouse_code' => $locked->warehouse_code,
                    'source_type' => 'WMS_INVENTORY_COUNT',
                    'source_id' => $locked->id,
                    'wms_inventory_count_id' => $locked->id,
                    'request_id' => $requestId,
                    'items' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'note' => "WMS棚卸最終確定 {$locked->count_no} ({$part})",
                    'status' => 'BEFORE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $requestIds[] = $requestId;
            }

            $locked->update([
                'status' => WmsInventoryCount::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
                'handy_reception' => false,
                'inventory_adjustment_date' => $date,
                'inventory_adjustment_count_round' => $preview['count_round'],
                'inventory_adjustment_request_id' => $requestIds[0] ?? null,
                'inventory_adjustment_request_ids' => $requestIds,
                'inventory_adjustment_queue_id' => $queueIds[0] ?? null,
                'inventory_adjustment_queue_ids' => $queueIds,
                'inventory_adjustment_queue_count' => count($queueIds),
                'inventory_adjustment_error_message' => null,
            ]);
        });
    }

    public function queues(WmsInventoryCount $count): \Illuminate\Support\Collection
    {
        return DB::connection('sakemaru')->table('inventory_adjustment_queue')
            ->where('wms_inventory_count_id', $count->id)->orderBy('id')
            ->get(['id', 'adjustment_date', 'status', 'is_success', 'inventory_adjustment_id', 'retry_count', 'error_message']);
    }

    private function voucherRows(WmsInventoryCount $count, string $date, array $sourceRows): array
    {
        $groups = collect($sourceRows)->groupBy('item_id');
        if ($groups->isEmpty()) {
            return [];
        }

        $db = DB::connection('sakemaru');
        if ($db->table('inventory_adjustment_queue')->where('client_id', $count->client_id)
            ->where('warehouse_code', $count->warehouse_code)
            ->where(fn ($query) => $query->where('status', '!=', 'FINISHED')->orWhereNull('is_success')->orWhere('is_success', false))
            ->exists()) {
            throw ValidationException::withMessages(['inventory_count' => '同じ倉庫に未完了の棚卸し伝票連携があります。連携完了を確認してください。']);
        }
        // 後日の絶対残高によって今回の差異が打ち消される場合は自動処理しない。
        if ($db->table('inventory_adjustment_items as ai')
            ->join('inventory_adjustments as a', 'a.id', '=', 'ai.inventory_adjustment_id')
            ->join('trade_items as ti', 'ti.id', '=', 'ai.trade_item_id')
            ->join('trades as t', 't.id', '=', 'a.trade_id')
            ->where('t.client_id', $count->client_id)->where('a.warehouse_id', $count->warehouse_id)
            ->where('t.is_active', true)->where('t.is_latest', true)->where('a.is_active', true)->where('ti.is_active', true)
            ->whereIn('ti.item_id', $groups->keys())->where('a.adjustment_date', '>', $date)
            ->where(fn ($query) => $query->whereNotNull('ai.stock_quantity_before')->orWhereNotNull('ai.stock_quantity_after')->orWhereNotNull('ai.applied_stock_quantity_after'))
            ->exists()) {
            throw ValidationException::withMessages(['adjustment_date' => '対象商品に指定日より後の棚卸し調節伝票があります。受払を確認してください。']);
        }

        $allocations = $db->table('real_stocks as rs')
            ->leftJoin('stock_allocations as sa', 'sa.id', '=', 'rs.stock_allocation_id')
            ->where('rs.client_id', $count->client_id)->where('rs.warehouse_id', $count->warehouse_id)
            ->whereIn('rs.item_id', $groups->keys())->get(['rs.item_id', 'sa.code'])->groupBy('item_id');
        foreach ($groups as $itemId => $rows) {
            $codes = $rows->pluck('stock_allocation_code')
                ->merge(($allocations->get($itemId) ?? collect())->pluck('code'))
                ->map(fn ($code) => $code === null ? null : (string) $code)->uniqueStrict();
            if ($codes->count() !== 1 || $codes->containsStrict(null)) {
                throw ValidationException::withMessages(['inventory_count' => "商品{$rows->first()['item_code']}に複数または不明の在庫区分があります。区分別の確認が必要です。"]);
            }
            if ($rows->pluck('unit_price')->unique()->count() !== 1) {
                throw ValidationException::withMessages(['inventory_count' => "商品{$rows->first()['item_code']}の棚番別原価が一致しません。"]);
            }
        }

        $groups = $groups->filter(fn ($rows) => $rows->sum('inventory_adjustment_quantity') !== 0);
        if ($groups->isEmpty()) {
            return [];
        }

        // 伝票ごと/商品ごとの再計算はせず、指定日の基幹受払残高を一括取得する。
        $balances = app(InventoryCountLedgerBalanceService::class)
            ->balancesBeforeAdjustmentByItem((int) $count->client_id, (int) $count->warehouse_id, $date);

        return $groups->map(function ($rows, $itemId) use ($balances, $date, $count): array {
            $row = $rows->first();
            $difference = $this->integerQuantity($rows->sum('inventory_adjustment_quantity'));
            $before = $this->integerQuantity($balances[$itemId] ?? 0);

            return array_replace($row, [
                'wms_inventory_count_item_id' => $rows->count() === 1 ? $row['wms_inventory_count_item_id'] : null,
                'stock_quantity_before' => $before,
                'stock_quantity_after' => $before + $difference,
                'inventory_adjustment_quantity' => $difference,
                'amount' => round($rows->sum('amount'), 2),
                'uncounted_as_zero' => $rows->contains('uncounted_as_zero', true),
                'location_no' => $rows->count() === 1 ? $row['location_no'] : null,
                'ledger_balance_date' => $date,
                'source_count_items' => $rows->values()->all(),
                'note' => $this->detailNote(
                    $count,
                    $this->integerQuantity($rows->sum('stock_quantity_before')),
                    $this->integerQuantity($rows->sum('stock_quantity_after')),
                    $difference,
                ),
            ]);
        })->values()->all();
    }

    private function detailNote(WmsInventoryCount $count, int $theory, int $actual, int $difference): string
    {
        return "棚卸 - {$count->count_date->toDateString()} - 理論 : {$theory} 実棚: {$actual} 差分 : {$difference}";
    }

    private function assertReady(WmsInventoryCount $count, ?int $round): void
    {
        if (! array_key_exists($round ?? 0, $this->confirmedRounds($count))) {
            throw ValidationException::withMessages(['inventory_count' => '2回目または3回目の確定後、その回を選択して棚卸し最終確定を実施してください。']);
        }
    }

    private function integerQuantity(mixed $value): int
    {
        if ($value === null || ! is_numeric($value) || (float) $value !== (float) (int) $value) {
            throw ValidationException::withMessages(['inventory_count' => '伝票数量に未設定または整数以外の値があります。']);
        }

        return (int) $value;
    }
}
