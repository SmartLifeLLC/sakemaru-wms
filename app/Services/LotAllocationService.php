<?php

namespace App\Services;

use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\RealStockLot;
use App\Models\Sakemaru\RealStockLotEarning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LotAllocationService
{
    /**
     * ロットから在庫を引き当てる（FIFO順）
     *
     * @param  int  $realStockId  real_stock ID
     * @param  int  $earningId  売上ID
     * @param  int  $tradeItemId  売上明細ID
     * @param  int  $quantity  引き当て数量
     * @param  float  $sellingPrice  売上単価
     * @param  float  $sellingAmount  売上金額
     * @return array 引き当て結果の配列
     *
     * @throws InsufficientStockException 在庫不足の場合
     */
    public function allocate(
        int $realStockId,
        int $earningId,
        int $tradeItemId,
        int $quantity,
        float $sellingPrice,
        float $sellingAmount
    ): array {
        $remaining = $quantity;
        $allocations = [];

        // FIFO順でロットを取得
        // 有効期限が近い順（NULLは最後）、古い順
        $lots = RealStockLot::query()
            ->where('real_stock_id', $realStockId)
            ->where('status', RealStockLot::STATUS_ACTIVE)
            ->whereRaw('current_quantity > reserved_quantity')
            ->where(function ($query) {
                $query->whereNull('expiration_date')
                    ->orWhere('expiration_date', '>', now());
            })
            ->orderByRaw('CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiration_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $available = $lot->current_quantity - $lot->reserved_quantity;
            $allocateQty = min($available, $remaining);

            if ($allocateQty > 0) {
                // 売上金額を按分計算
                $ratio = $allocateQty / $quantity;
                $allocatedSellingAmount = round($sellingAmount * $ratio, 2);

                $allocations[] = [
                    'lot' => $lot,
                    'quantity' => $allocateQty,
                    'purchase_price' => $lot->price,
                    'purchase_amount' => $allocateQty * ($lot->price ?? 0),
                    'selling_price' => $sellingPrice,
                    'selling_amount' => $allocatedSellingAmount,
                ];
                $remaining -= $allocateQty;
            }
        }

        if ($remaining > 0) {
            throw new \RuntimeException("在庫不足: 残り {$remaining} 個が引き当て不可");
        }

        return $allocations;
    }

    /**
     * 引き当てを実行し、DBに保存する
     *
     * @return array 作成されたRealStockLotEarningの配列
     */
    public function executeAllocation(
        int $realStockId,
        int $earningId,
        int $tradeItemId,
        int $quantity,
        float $sellingPrice,
        float $sellingAmount
    ): array {
        return DB::connection('sakemaru')->transaction(function () use (
            $realStockId, $earningId, $tradeItemId, $quantity, $sellingPrice, $sellingAmount
        ) {
            // 引き当て計算
            $allocations = $this->allocate(
                $realStockId,
                $earningId,
                $tradeItemId,
                $quantity,
                $sellingPrice,
                $sellingAmount
            );

            $lotEarnings = [];
            $totalReservedQty = 0;

            foreach ($allocations as $allocation) {
                /** @var RealStockLot $lot */
                $lot = $allocation['lot'];

                // RealStockLotEarning作成
                $lotEarning = RealStockLotEarning::create([
                    'real_stock_lot_id' => $lot->id,
                    'earning_id' => $earningId,
                    'trade_item_id' => $tradeItemId,
                    'quantity' => $allocation['quantity'],
                    'purchase_price' => $allocation['purchase_price'],
                    'purchase_amount' => $allocation['purchase_amount'],
                    'selling_price' => $allocation['selling_price'],
                    'selling_amount' => $allocation['selling_amount'],
                    'status' => RealStockLotEarning::STATUS_RESERVED,
                    'reserved_at' => now(),
                ]);
                $lotEarnings[] = $lotEarning;

                // ロットのreserved_quantity更新
                $lot->reserved_quantity += $allocation['quantity'];
                $lot->save();

                $totalReservedQty += $allocation['quantity'];
            }

            // RealStockのreserved_quantity更新
            $realStock = RealStock::findOrFail($realStockId);
            $realStock->reserved_quantity += $totalReservedQty;
            $realStock->save();

            Log::info('Lot allocation completed', [
                'real_stock_id' => $realStockId,
                'earning_id' => $earningId,
                'trade_item_id' => $tradeItemId,
                'quantity' => $quantity,
                'lot_count' => count($allocations),
            ]);

            return $lotEarnings;
        });
    }

    /**
     * 引き当てをキャンセルする
     */
    public function cancelAllocation(int $earningId, int $tradeItemId): void
    {
        DB::connection('sakemaru')->transaction(function () use ($earningId, $tradeItemId) {
            $lotEarnings = RealStockLotEarning::where('earning_id', $earningId)
                ->where('trade_item_id', $tradeItemId)
                ->where('status', RealStockLotEarning::STATUS_RESERVED)
                ->lockForUpdate()
                ->get();

            foreach ($lotEarnings as $lotEarning) {
                $lot = $lotEarning->realStockLot;
                $realStock = $lot->realStock;

                // ロットのreserved_quantity減少
                $lot->reserved_quantity -= $lotEarning->quantity;
                $lot->save();

                // RealStockのreserved_quantity減少
                $realStock->reserved_quantity -= $lotEarning->quantity;
                $realStock->save();

                // ステータスをCANCELLEDに更新
                $lotEarning->markAsCancelled();
            }

            Log::info('Lot allocation cancelled', [
                'earning_id' => $earningId,
                'trade_item_id' => $tradeItemId,
                'cancelled_count' => $lotEarnings->count(),
            ]);
        });
    }

    /**
     * 配送完了処理（ロットからの出荷確定）
     *
     * @param  int  $deliveredQty  出荷数量
     */
    public function confirmDelivery(int $earningId, int $tradeItemId, int $deliveredQty): void
    {
        DB::connection('sakemaru')->transaction(function () use ($earningId, $tradeItemId, $deliveredQty) {
            $lotEarnings = RealStockLotEarning::where('earning_id', $earningId)
                ->where('trade_item_id', $tradeItemId)
                ->where('status', RealStockLotEarning::STATUS_RESERVED)
                ->lockForUpdate()
                ->get();

            $remaining = $deliveredQty;

            foreach ($lotEarnings as $lotEarning) {
                if ($remaining <= 0) {
                    break;
                }

                $lot = $lotEarning->realStockLot;
                $realStock = $lot->realStock;

                $processQty = min($lotEarning->quantity, $remaining);

                // ロットの在庫減少
                $lot->current_quantity -= $processQty;
                $lot->reserved_quantity -= $processQty;
                $lot->save();

                // ロットが空になったらDEPLETED
                $lot->checkAndMarkDepleted();

                // RealStockの在庫減少
                $realStock->current_quantity -= $processQty;
                $realStock->reserved_quantity -= $processQty;
                $realStock->save();

                // ステータスをDELIVEREDに更新
                $lotEarning->markAsDelivered();

                $remaining -= $processQty;
            }

            Log::info('Delivery confirmed from lots', [
                'earning_id' => $earningId,
                'trade_item_id' => $tradeItemId,
                'delivered_qty' => $deliveredQty,
            ]);
        });
    }

    /**
     * 仕入からロットを作成
     */
    public function createLotFromPurchase(
        int $realStockId,
        ?int $purchaseId,
        ?int $tradeItemId,
        int $quantity,
        ?float $price = null,
        ?string $expirationDate = null,
        float $contentAmount = 0,
        float $containerAmount = 0
    ): RealStockLot {
        return DB::connection('sakemaru')->transaction(function () use (
            $realStockId, $purchaseId, $tradeItemId, $quantity, $price, $expirationDate, $contentAmount, $containerAmount
        ) {
            // ロット作成
            $lot = RealStockLot::create([
                'real_stock_id' => $realStockId,
                'purchase_id' => $purchaseId,
                'trade_item_id' => $tradeItemId,
                'price' => $price,
                'content_amount' => $contentAmount,
                'container_amount' => $containerAmount,
                'expiration_date' => $expirationDate,
                'initial_quantity' => $quantity,
                'current_quantity' => $quantity,
                'reserved_quantity' => 0,
                'status' => RealStockLot::STATUS_ACTIVE,
            ]);

            // RealStockのcurrent_quantity更新
            $realStock = RealStock::findOrFail($realStockId);
            $realStock->current_quantity += $quantity;
            $realStock->save();

            Log::info('Lot created from purchase', [
                'lot_id' => $lot->id,
                'real_stock_id' => $realStockId,
                'purchase_id' => $purchaseId,
                'quantity' => $quantity,
            ]);

            return $lot;
        });
    }
}
