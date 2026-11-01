<?php

namespace App\Services\Shortage;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared WMS/core contract: exact CREATE request -> existing transfer; never creates business documents. */
class ProxyShipmentTransferResolver
{
    private function db()
    {
        return DB::connection('sakemaru');
    }

    public function resolve(int $allocationId, int $clientId): array
    {
        $requestExpression = $this->db()->getDriverName() === 'sqlite' ? "'proxy-shipment-' || a.id" : "CONCAT('proxy-shipment-', a.id)";
        $row = $this->db()->table('wms_shortage_allocations as a')
            ->join('wms_shortages as s', 's.id', '=', 'a.shortage_id')
            ->join('trades as original', 'original.id', '=', 's.trade_id')
            ->join('stock_transfer_queue as q', function ($join) use ($requestExpression) {
                $join->whereRaw("q.request_id = {$requestExpression}")
                    ->where('q.action_type', 'CREATE');
            })
            ->join('stock_transfers as st', 'st.id', '=', 'q.stock_transfer_id')
            ->join('trades as t', 't.id', '=', 'st.trade_id')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('stock_transfer_queue as duplicate')
                    ->whereColumn('duplicate.stock_transfer_id', 'q.stock_transfer_id')
                    ->whereColumn('duplicate.id', '!=', 'q.id')->where('duplicate.action_type', 'CREATE')
                    ->where('duplicate.request_id', 'like', 'proxy-shipment-%');
            })
            ->where('a.id', $allocationId)->where('original.client_id', $clientId)
            ->where('q.client_id', $clientId)->where('st.client_id', $clientId)->where('t.client_id', $clientId)
            ->where('a.is_finished', true)->whereIn('a.status', ['FULFILLED', 'SHORTAGE'])
            ->where('a.picked_qty', '>', 0)->where('q.status', 'FINISHED')->where('q.is_success', true)
            ->where('st.is_active', true)->where('t.is_active', true)->where('t.is_latest', true)
            ->where(fn ($q) => $q->whereNull('a.stock_transfer_queue_id')->orWhereColumn('a.stock_transfer_queue_id', 'q.id'))
            ->select(['a.id as allocation_id', 'q.id as stock_transfer_queue_id', 'st.id as stock_transfer_id', 't.serial_id'])
            ->first();
        if (! $row) {
            throw ValidationException::withMessages(['allocation_ids' => "依頼ID {$allocationId} は印刷できません。完了状態と倉庫移動伝票を確認してください。"]);
        }
        $result = (array) $row;
        $result['snapshot_hash'] = $this->snapshot((int) $row->stock_transfer_id, $clientId);

        return $result;
    }

    public function snapshot(int $transferId, int $clientId): string
    {
        $transfer = $this->db()->table('stock_transfers')->where('id', $transferId)->where('client_id', $clientId)->where('is_active', true)->first();
        $trade = $transfer ? $this->db()->table('trades')->where('id', $transfer->trade_id)
            ->where('client_id', $clientId)->where('is_active', true)->where('is_latest', true)->first() : null;
        if (! $trade) {
            throw ValidationException::withMessages(['allocation_ids' => '倉庫移動伝票が変更されています。一覧を更新してください。']);
        }
        $items = $this->db()->table('trade_items')->where('trade_id', $trade->id)->orderBy('id')->get();
        $normalize = static function ($row): array {
            $values = (array) $row;
            ksort($values);

            return array_map(static fn ($value) => $value === null ? null : (string) $value, $values);
        };

        return hash('sha256', json_encode([$normalize($transfer), $normalize($trade), $items->map($normalize)->all()], JSON_THROW_ON_ERROR));
    }
}
